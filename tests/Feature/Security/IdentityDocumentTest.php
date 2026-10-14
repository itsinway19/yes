<?php

namespace Tests\Feature\Security;

use App\Http\Middleware\EnsureCurrentPrincipal;
use App\Models\User;
use App\Security\IdentityDocument;
use App\Security\IdentityReceipt;
use App\Security\IdentityTransition;
use App\Security\IdentityTransitionConflict;
use App\Security\SessionAuthority;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\IdentityTransitionFixture;
use Tests\TestCase;

class IdentityDocumentTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['session.driver' => 'redis', 'session.connection' => 'default']);
        $this->assertSame('PONG', (string) Redis::connection()->ping());
    }

    private function login(): array
    {
        $user = User::factory()->create();
        $service = app(IdentityTransition::class);
        $lineage = $service->create('advertiser');
        $request = IdentityTransitionFixture::request();
        $service->login($request, $lineage, 'advertiser', 0, bin2hex(random_bytes(32)),
            fn () => IdentityTransitionFixture::authenticate($request, $user->email));
        $request->cookies->set(config('session.cookie').'-lineage', $lineage);

        return [$request, $lineage];
    }

    public function test_fresh_document_requires_committed_credential_and_signed_receipt_matches_it(): void
    {
        [$request] = $this->login();
        $documents = app(IdentityDocument::class);
        $metadata = $documents->fresh($request);
        $this->assertSame($metadata['binding'], $documents->admit($request, $metadata['receipt']));
        $this->assertSame($request->session()->token(), $metadata['csrf']);
        $this->assertSame(SessionAuthority::sessionKey($request), $metadata['binding']['credential']);
        $this->assertSame(0, DB::connection()->transactionLevel());
        $this->assertSame(0, DB::connection('pgsql_authority')->transactionLevel());
    }

    public function test_registration_verification_session_does_not_gain_current_principal_business_authority(): void
    {
        $user = User::factory()->unverified()->create();
        $service = app(IdentityTransition::class);
        $lineage = $service->create('advertiser');
        $request = IdentityTransitionFixture::request();
        $service->login($request, $lineage, 'advertiser', 0, bin2hex(random_bytes(32)),
            fn () => IdentityTransitionFixture::authenticate($request, $user->email));
        $request->cookies->set(config('session.cookie').'-lineage', $lineage);
        $documents = app(IdentityDocument::class);
        $metadata = $documents->fresh($request);
        $documents->admit($request, $metadata['receipt']);
        $this->assertSame('pending', DB::table('principal_lifecycles')->where('user_id', $user->id)->value('status'));
        $request->headers->set('Accept', 'application/json');
        $effect = false;
        try {
            app(EnsureCurrentPrincipal::class)->handle($request, function () use (&$effect) {
                $effect = true;

                return response('forbidden effect');
            });
            $this->fail('Pending registration executed current-principal business work.');
        } catch (HttpException $error) {
            $this->assertSame(403, $error->getStatusCode());
            $this->assertFalse($effect);
        }
    }

    public static function invalidAuthority(): array
    {
        return ['revoked' => ['revoked_at'], 'idle expiry' => ['idle_expires_at'], 'absolute expiry' => ['absolute_expires_at']];
    }

    #[DataProvider('invalidAuthority')]
    public function test_a_correctly_signed_receipt_never_restores_invalid_canonical_authority(string $column): void
    {
        [$request] = $this->login();
        $documents = app(IdentityDocument::class);
        $metadata = $documents->fresh($request);
        DB::connection('pgsql_authority')->table('security_sessions')
            ->where('session_key_hash', SessionAuthority::sessionKey($request))->update([$column => now()->subSecond()]);
        $this->expectException(AuthorizationException::class);
        $documents->admit($request, $metadata['receipt']);
    }

    public function test_signed_old_receipt_is_denied_after_account_switch_even_with_new_loaded_credential(): void
    {
        [$request, $lineage] = $this->login();
        $documents = app(IdentityDocument::class);
        $old = $documents->fresh($request);
        $other = User::factory()->create();
        app(IdentityTransition::class)->login($request, $lineage, 'advertiser', 1, bin2hex(random_bytes(32)),
            fn () => IdentityTransitionFixture::authenticate($request, $other->email));
        $this->expectException(IdentityTransitionConflict::class);
        $documents->admit($request, $old['receipt']);
    }

    public function test_signed_old_receipt_is_denied_after_sid_rebind(): void
    {
        [$request, $lineage] = $this->login();
        $documents = app(IdentityDocument::class);
        $old = $documents->fresh($request);
        app(IdentityTransition::class)->rebind($request, $lineage, 'advertiser', 1, bin2hex(random_bytes(32)));
        $this->expectException(IdentityTransitionConflict::class);
        $documents->admit($request, $old['receipt']);
    }

    public function test_signed_receipt_alone_cannot_authenticate_a_missing_credential(): void
    {
        [$request] = $this->login();
        $old = app(IdentityDocument::class)->fresh($request);
        $missing = IdentityTransitionFixture::request();
        $this->assertNull($missing->user());
        $this->expectException(IdentityTransitionConflict::class);
        app(IdentityDocument::class)->admit($missing, $old['receipt']);
    }

    public function test_valid_credential_and_receipt_cannot_override_a_different_browser_selector(): void
    {
        [$request] = $this->login();
        $documents = app(IdentityDocument::class);
        $metadata = $documents->fresh($request);
        $other = app(IdentityTransition::class)->create('advertiser');
        $request->cookies->set(config('session.cookie').'-lineage', $other);
        $this->expectException(IdentityTransitionConflict::class);
        $documents->admit($request, $metadata['receipt']);
    }

    public function test_credential_loss_with_selector_creates_only_a_guest_challenge_against_current_epoch(): void
    {
        [$request, $lineage] = $this->login();
        $missing = IdentityTransitionFixture::request();
        $missing->cookies->set(config('session.cookie').'-lineage', $lineage);
        $metadata = app(IdentityDocument::class)->fresh($missing);
        $this->assertSame($lineage, $metadata['binding']['lineage']);
        $this->assertSame(1, $metadata['binding']['epoch']);
        $this->assertSame(0, $metadata['binding']['principal']);
        $this->assertNull($missing->user());
        $this->assertSame('active', DB::table('browser_identity_transitions')->value('status'));
    }

    public function test_locked_signed_precondition_rejects_wrong_captured_credential_before_authentication(): void
    {
        [$request, $lineage] = $this->login();
        $documents = app(IdentityDocument::class);
        $metadata = $documents->fresh($request);
        $binding = [...$metadata['binding'], 'credential' => str_repeat('a', 64), 'nonce' => bin2hex(random_bytes(32))];
        $challenge = app(IdentityReceipt::class)->issue($binding, 'transition', time() + 60);
        [$captured, $locked] = $documents->transition($request, $challenge);
        $calls = 0;
        try {
            app(IdentityTransition::class)->login($request, $lineage, 'advertiser', 1, $captured['nonce'],
                function () use (&$calls): void {
                    $calls++;
                }, 'password', $locked);
            $this->fail('Wrong captured input authenticated.');
        } catch (IdentityTransitionConflict) {
            $this->assertSame(0, $calls);
            $this->assertSame(1, DB::table('security_sessions')->count());
            $this->assertSame(1, (int) DB::table('browser_identity_transitions')->value('epoch'));
        }
    }

    public function test_locked_precondition_executes_inside_actual_authority_transaction_before_guard_mutation(): void
    {
        [$request, $lineage] = $this->login();
        $documents = app(IdentityDocument::class);
        $documents->fresh($request);
        [$captured, $locked] = $documents->transition($request, $documents->challenge($request));
        $sequence = [];
        $other = User::factory()->create();
        app(IdentityTransition::class)->login($request, $lineage, 'advertiser', 1, $captured['nonce'],
            function () use ($request, $other, &$sequence): void {
                $sequence[] = 'guard';
                IdentityTransitionFixture::authenticate($request, $other->email);
            }, 'password', function (object $row) use ($locked, &$sequence): void {
                $this->assertSame(1, DB::connection('pgsql_authority')->transactionLevel());
                $locked($row);
                $sequence[] = 'locked-precondition';
            });
        $this->assertSame(['locked-precondition', 'guard'], $sequence);
    }
}

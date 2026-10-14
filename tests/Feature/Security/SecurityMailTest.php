<?php

namespace Tests\Feature\Security;

use App\Models\User;
use App\Notifications\SecurityNotice;
use App\Security\LifecycleAuthority;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Queue;
use RuntimeException;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Mime\RawMessage;
use Tests\TestCase;

class SecurityMailTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config()->set('queue.default', 'database');
        config()->set('queue.connections.database.connection', 'pgsql');
        config()->set('queue.connections.database.queue', 'default');
        config()->set('mail.default', 'array');
        Queue::setDefaultDriver('database');
        Mail::mailer('array')->getSymfonyTransport()->flush();
    }

    public function test_verification_mail_is_queued_encrypted_and_rendered_in_arabic_rtl(): void
    {
        app()->setLocale('ar');
        $user = User::factory()->unverified()->create();
        $user->sendEmailVerificationNotification();

        $job = DB::table('jobs')->first();
        $this->assertNotNull($job);
        $this->assertStringNotContainsString($user->email, $job->payload);
        $this->assertSame(0, Mail::mailer('array')->getSymfonyTransport()->messages()->count());

        $this->workOne();
        $messages = Mail::mailer('array')->getSymfonyTransport()->messages();
        $this->assertCount(1, $messages);
        $email = $messages->first()->getOriginalMessage();
        $this->assertStringContainsString('dir="rtl"', $email->getHtmlBody());
        $this->assertStringContainsString('تأكيد بريدك', $email->getSubject());
        $this->assertSame(0, DB::table('jobs')->count());
    }

    public function test_verified_or_replaced_recovery_operation_is_not_emailed_later(): void
    {
        $user = User::factory()->unverified()->create();
        $user->sendEmailVerificationNotification();
        app(LifecycleAuthority::class)->verifyPrimaryEmail($user);
        $this->workOne();
        $this->assertSame(0, Mail::mailer('array')->getSymfonyTransport()->messages()->count());

        $token = Password::broker()->createToken($user);
        $user->sendPasswordResetNotification($token);
        $payload = DB::table('jobs')->value('payload');
        $this->assertStringNotContainsString($token, $payload);
        Password::broker()->deleteToken($user);
        $this->workOne();
        $this->assertSame(0, Mail::mailer('array')->getSymfonyTransport()->messages()->count());
        $this->assertSame(0, DB::table('jobs')->count());
    }

    public function test_notice_is_dispatched_after_commit_and_not_after_rollback(): void
    {
        $user = User::factory()->create();
        try {
            DB::transaction(function () use ($user): void {
                $user->notify(new SecurityNotice('passkey_added'));
                $this->assertSame(0, DB::table('jobs')->count());
                throw new RuntimeException('rollback');
            });
        } catch (RuntimeException) {
            $this->addToAssertionCount(1);
        }
        $this->assertSame(0, DB::table('jobs')->count());

        DB::transaction(function () use ($user): void {
            $user->notify(new SecurityNotice('passkey_added'));
            $this->assertSame(0, DB::table('jobs')->count());
        });
        $this->assertSame(1, DB::table('jobs')->count());
        $this->workOne();
        $this->assertCount(1, Mail::mailer('array')->getSymfonyTransport()->messages());
    }

    public function test_english_reset_email_is_rendered_and_expired_token_is_skipped(): void
    {
        app()->setLocale('en');
        $user = User::factory()->create();
        $token = Password::broker()->createToken($user);
        $user->sendPasswordResetNotification($token);
        app()->setLocale('ar');
        $this->workOne();
        $messages = Mail::mailer('array')->getSymfonyTransport()->messages();
        $this->assertCount(1, $messages);
        $email = $messages->first()->getOriginalMessage();
        $this->assertSame('Reset your Builderad password', $email->getSubject());
        $this->assertStringContainsString('dir="ltr"', $email->getHtmlBody());
        $this->assertStringContainsString($token, $email->getTextBody());

        Mail::mailer('array')->getSymfonyTransport()->flush();
        $newToken = Password::broker()->createToken($user);
        $user->sendPasswordResetNotification($newToken);
        $this->travel(16)->minutes();
        try {
            $this->workOne();
        } finally {
            $this->travelBack();
        }
        $this->assertCount(0, Mail::mailer('array')->getSymfonyTransport()->messages());
    }

    public function test_queued_security_notice_keeps_its_locale_after_browser_language_changes(): void
    {
        $this->useIdentityDocument();
        $user = User::factory()->create();
        foreach ([
            ['ar', 'en', 'أُضيف مفتاح مرور إلى حسابك', 'rtl'],
            ['en', 'ar', 'A passkey was added to your account', 'ltr'],
        ] as [$queuedLocale, $browserLocale, $subject, $direction]) {
            Mail::mailer('array')->getSymfonyTransport()->flush();
            app()->setLocale($queuedLocale);
            $user->notify(new SecurityNotice('passkey_added'));

            $this->post('/locale', ['locale' => $browserLocale])->assertRedirect('/login');
            $this->get('/login')->assertOk();
            $this->assertSame($browserLocale, app()->getLocale());

            $this->workOne();
            $messages = Mail::mailer('array')->getSymfonyTransport()->messages();
            $this->assertCount(1, $messages);
            $email = $messages->first()->getOriginalMessage();
            $this->assertSame($subject, $email->getSubject());
            $this->assertStringContainsString('dir="'.$direction.'"', $email->getHtmlBody());
        }
    }

    public function test_transport_failure_retries_are_bounded_and_visible_in_failed_jobs(): void
    {
        $user = User::factory()->create();
        Mail::mailer('array')->setSymfonyTransport(new class implements TransportInterface
        {
            public function send(RawMessage $message, ?Envelope $envelope = null): ?SentMessage
            {
                throw new RuntimeException('Local test transport failure');
            }

            public function __toString(): string
            {
                return 'local-test-failure';
            }
        });
        $user->notify(new SecurityNotice('passkey_added'));

        for ($attempt = 1; $attempt <= 3; $attempt++) {
            DB::table('jobs')->update(['available_at' => time() - 1]);
            $this->workOne();
            if ($attempt < 3) {
                $this->assertSame($attempt, (int) DB::table('jobs')->value('attempts'));
            }
        }
        $this->assertSame(0, DB::table('jobs')->count());
        $this->assertSame(1, DB::table('failed_jobs')->count());
    }

    public function test_mail_queue_outage_does_not_undo_lifecycle_invalidation_or_its_audit(): void
    {
        $user = User::factory()->create();
        config()->set('queue.default', 'unavailable-test-queue');
        Queue::setDefaultDriver('unavailable-test-queue');

        app(LifecycleAuthority::class)->disable($user, $user->id, 'security_hold');

        $this->assertDatabaseHas('principal_lifecycles', [
            'user_id' => $user->id, 'status' => 'disabled', 'generation' => 2,
        ]);
        $this->assertDatabaseHas('security_audit_events', [
            'subject_user_id' => $user->id, 'event' => 'principal_disabled',
        ]);
    }

    private function workOne(): void
    {
        Artisan::call('queue:work', [
            'connection' => 'database', '--queue' => 'default', '--once' => true,
            '--sleep' => 0, '--tries' => 3,
        ]);
    }
}

<?php

namespace App\Security;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Request/document binding layered over, never replacing, canonical admission. */
final class IdentityDocument
{
    public function __construct(private IdentityTransition $transitions, private IdentityReceipt $receipts) {}

    public function boundary(Request $request): string
    {
        return $request->is('internal/*') ? 'internal' : 'advertiser';
    }

    public function admit(Request $request, string $receipt): array
    {
        $claims = $this->receipts->read($receipt, 'document')['binding'];
        $lineage = $request->session()->get('builderad.identity_lineage');
        if (! is_string($lineage)) {
            throw new IdentityTransitionConflict;
        }
        $this->assertSelector($request, $lineage);
        if ($request->user()) {
            $this->transitions->admit($request, $lineage, $this->boundary($request));
        } else {
            $row = DB::table('browser_identity_transitions')->where('lineage_id', $lineage)
                ->where('boundary', $this->boundary($request))->first();
            if (! $row || (int) $row->epoch !== $request->session()->get('builderad.identity_epoch')) {
                throw new IdentityTransitionConflict;
            }
        }
        $binding = $this->binding($request, $claims['generation']);
        $this->receipts->verify($receipt, $binding, 'document');
        $request->attributes->set('builderad.identity_document', $binding);

        return $binding;
    }

    /** Only a new native document may bootstrap from an already committed credential. */
    public function fresh(Request $request): array
    {
        if (! $request->user()) {
            return $this->anonymous($request);
        }
        $lineage = $request->session()->get('builderad.identity_lineage');
        if (! is_string($lineage)) {
            throw new IdentityTransitionConflict;
        }
        $this->assertSelector($request, $lineage);
        $this->transitions->admit($request, $lineage, $this->boundary($request));
        $binding = $this->binding($request, bin2hex(random_bytes(32)));
        $request->attributes->set('builderad.identity_document', $binding);

        return $this->metadata($request, $binding);
    }

    /** Anonymous evidence authenticates no principal and never adopts a legacy login. */
    public function anonymous(Request $request): array
    {
        if ($request->user()) {
            throw new IdentityTransitionConflict;
        }
        $lineage = $request->session()->get('builderad.identity_lineage');
        $selected = $request->cookie($this->selectorName());
        if (is_string($selected)) {
            if (! Str::isUuid($selected)) {
                throw new IdentityTransitionConflict;
            }
            $row = DB::table('browser_identity_transitions')->where('lineage_id', $selected)
                ->where('boundary', $this->boundary($request))->first();
            if (! $row || ($lineage !== null && $lineage !== $selected)) {
                throw new IdentityTransitionConflict;
            }
            if ($lineage === null) {
                $request->session()->put(['builderad.identity_lineage' => $selected, 'builderad.identity_epoch' => (int) $row->epoch]);
            }
            $lineage = $selected;
        }
        if ($lineage === null) {
            $lineage = $this->transitions->create($this->boundary($request));
            $request->session()->put(['builderad.identity_lineage' => $lineage, 'builderad.identity_epoch' => 0]);
        }
        $row = DB::table('browser_identity_transitions')->where('lineage_id', $lineage)
            ->where('boundary', $this->boundary($request))->first();
        if (! $row || (int) $row->epoch !== $request->session()->get('builderad.identity_epoch')) {
            throw new IdentityTransitionConflict;
        }
        $this->establishSelector($request, $lineage);
        $request->session()->put('builderad.identity_cookie_lease', time() + (int) config('session.lifetime') * 60);
        $binding = $this->binding($request, bin2hex(random_bytes(32)));
        $request->attributes->set('builderad.identity_document', $binding);

        return $this->metadata($request, $binding);
    }

    /** Explicit native sign-in recovery; never an implicit adoption or write replay. */
    public function reauthenticate(Request $request): array
    {
        if (! $request->isMethod('GET') || ! $request->is('login', 'internal/login')) {
            throw new IdentityTransitionConflict;
        }
        $lineage = $request->session()->get('builderad.identity_lineage');
        $selected = $request->cookie($this->selectorName());
        if (is_string($selected)) {
            $lineage = Str::isUuid($selected) ? $selected : null;
        }
        $row = is_string($lineage) && Str::isUuid($lineage) ? DB::table('browser_identity_transitions')->where('lineage_id', $lineage)
            ->where('boundary', $this->boundary($request))->first() : null;
        if ($request->user()) {
            // Explicit recovery retires only the loaded input security session,
            // including an unbound legacy session. Never follow the selector to
            // revoke a later/current credential belonging to another request.
            app(SessionAuthority::class)->revokeCurrent($request);
        }
        Auth::guard($this->boundary($request) === 'internal' ? 'internal' : 'web')->logoutCurrentDevice();
        $request->session()->invalidate();
        $request->session()->regenerateToken();
        if ($row) {
            $request->session()->put(['builderad.identity_lineage' => $row->lineage_id, 'builderad.identity_epoch' => (int) $row->epoch]);
        }
        if (! $row) {
            $request->cookies->remove($this->selectorName());
        }

        return $this->anonymous($request);
    }

    private function selectorName(): string
    {
        return (string) config('session.cookie').'-lineage';
    }

    private function assertSelector(Request $request, string $lineage): void
    {
        if ($request->cookie($this->selectorName()) !== $lineage) {
            throw new IdentityTransitionConflict;
        }
    }

    private function establishSelector(Request $request, string $lineage): void
    {
        if ($request->cookie($this->selectorName()) === $lineage) {
            return;
        }
        // This encrypted, HttpOnly selector orders a browser boundary; it is not
        // an authentication credential. Never echo it on login or renewal.
        Cookie::queue(cookie($this->selectorName(), $lineage, 43200,
            config('session.path'), config('session.domain'), config('session.secure'), true, false, config('session.same_site')));
        $request->cookies->set($this->selectorName(), $lineage);
        $request->attributes->set('builderad.identity_selector_created', true);
    }

    public function metadata(Request $request, ?array $binding = null): array
    {
        $binding ??= $request->attributes->get('builderad.identity_document');
        if (! is_array($binding)) {
            throw new IdentityTransitionConflict;
        }

        return ['receipt' => $this->receipts->issue($binding, 'document', time() + 7200),
            'binding' => $binding, 'csrf' => $request->session()->token(),
            'transition' => $this->receipts->issue([...$binding, 'nonce' => bin2hex(random_bytes(32))], 'transition', time() + 600),
            // Availability is not authority. New cookies have a fixed retention
            // bound; fresh documents may observe Redis TTL without renewing it.
            // Legacy cookies keep their old conservative bound.
            'leaseMilliseconds' => $request->attributes->get('builderad.identity_lease_milliseconds')
                ?? ($request->user() && $request->session()->has('builderad.identity_cookie_deadline')
                    ? app(IdentityRenewal::class)->remainingMilliseconds($request)
                    : max(1000, ((int) $request->session()->get('builderad.identity_cookie_lease', time()) - time()) * 1000)),
            'renewalProtocol' => IdentityRenewal::compatible($request) ? 1 : 0];
    }

    /** Caller has performed CSRF and preliminary input validation before invoking this. */
    public function transition(Request $request, string $challenge): array
    {
        $captured = $this->receipts->read($challenge, 'transition')['binding'];
        if ($captured['boundary'] !== $this->boundary($request)) {
            throw new IdentityTransitionConflict;
        }

        return [$captured, function (object $locked) use ($request, $challenge, $captured): void {
            $this->assertLocked($request, $locked, $challenge, $captured);
        }];
    }

    public function challenge(Request $request): string
    {
        $binding = $request->attributes->get('builderad.identity_document');
        if (! is_array($binding)) {
            throw new IdentityTransitionConflict;
        }

        return $this->receipts->issue([...$binding, 'nonce' => bin2hex(random_bytes(32))], 'transition', time() + 600);
    }

    private function assertLocked(Request $request, object $locked, string $challenge, array $captured): void
    {
        if ($locked->lineage_id !== $captured['lineage'] || $locked->boundary !== $captured['boundary']
            || (int) $locked->epoch !== $captured['epoch']) {
            throw new IdentityTransitionConflict;
        }
        $expected = [...$this->binding($request, $captured['generation']), 'nonce' => $captured['nonce']];
        $this->receipts->verify($challenge, $expected, 'transition');
        // A guest challenge can replace an active lineage only through explicit
        // authentication. It cannot adopt its principal or execute protected work.
        if ($expected['principal'] !== 0 && ($locked->status !== 'active'
            || (int) $locked->principal_id !== $expected['principal']
            || ! hash_equals((string) $locked->current_session_key_hash, $expected['credential']))) {
            throw new IdentityTransitionConflict;
        }
        if ($expected['principal'] !== 0) {
            $this->transitions->admit($request, $locked->lineage_id, $locked->boundary);
        }
    }

    private function binding(Request $request, string $generation): array
    {
        $lineage = $request->session()->get('builderad.identity_lineage');
        $epoch = $request->session()->get('builderad.identity_epoch');
        if (! is_string($lineage) || ! is_int($epoch)) {
            throw new IdentityTransitionConflict;
        }
        $this->assertSelector($request, $lineage);
        $user = $request->user();
        $lifecycle = $user ? DB::table('principal_lifecycles')->where('user_id', $user->id)->value('generation') : 0;

        return ['lineage' => $lineage, 'epoch' => $epoch, 'boundary' => $this->boundary($request),
            'credential' => SessionAuthority::sessionKey($request), 'principal' => (int) ($user?->id ?? 0),
            'lifecycle' => (int) $lifecycle,
            'context' => hash_hmac('sha256', $request->session()->getId(), (string) config('app.key')),
            'generation' => $generation];
    }
}

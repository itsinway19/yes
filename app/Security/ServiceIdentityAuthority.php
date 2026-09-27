<?php

namespace App\Security;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class ServiceIdentityAuthority
{
    public function __construct(private readonly GrantAuthority $grants) {}

    /** @return array{public_id: string, credential: string} */
    public function create(Request $request, string $name): array
    {
        $this->assertManager($request);
        $credential = $this->newCredential();
        $id = DB::connection('pgsql_authority')->table('service_identities')->insertGetId([
            'name' => $name,
            'status' => 'enabled',
            'credential_hash' => hash('sha256', $credential),
            'version' => 1,
            'created_by' => $request->user()->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $service = DB::connection('pgsql_authority')->table('service_identities')->where('id', $id)->first();
        $this->audit($request, $id, 'service_created', 'explicit_service_management', null, 'enabled', null, 1);

        return ['public_id' => $service->public_id, 'credential' => $credential];
    }

    /** @return array{public_id: string, credential: string} */
    public function rotate(Request $request, string $publicId): array
    {
        $this->assertManager($request);
        $service = DB::connection('pgsql_authority')->table('service_identities')->where('public_id', $publicId)
            ->lockForUpdate()->first();
        if (! $service || $service->status !== 'enabled') {
            throw new AuthorizationException('Current service identity required.');
        }
        $credential = $this->newCredential();
        DB::connection('pgsql_authority')->table('service_identities')->where('id', $service->id)->update([
            'credential_hash' => hash('sha256', $credential),
            'version' => (int) $service->version + 1,
            'updated_at' => now(),
        ]);
        $this->audit($request, $service->id, 'service_credential_rotated', 'explicit_service_management',
            'enabled', 'enabled', (int) $service->version, (int) $service->version + 1);

        return ['public_id' => $publicId, 'credential' => $credential];
    }

    public function revoke(Request $request, string $publicId): void
    {
        $this->assertManager($request);
        $service = DB::connection('pgsql_authority')->table('service_identities')->where('public_id', $publicId)
            ->lockForUpdate()->first();
        if (! $service) {
            abort(404);
        }
        if ($service->status === 'revoked') {
            return;
        }
        DB::connection('pgsql_authority')->table('service_identities')->where('id', $service->id)->update([
            'status' => 'revoked',
            'revoked_by' => $request->user()->id,
            'revoked_at' => now(),
            'version' => (int) $service->version + 1,
            'updated_at' => now(),
        ]);
        $this->audit($request, $service->id, 'service_revoked', 'explicit_service_management',
            'enabled', 'revoked', (int) $service->version, (int) $service->version + 1);
    }

    private function assertManager(Request $request): void
    {
        if (! $this->grants->has($request->user(),
            (int) $request->session()->get('a001_generation', 0), 'service.manage')) {
            throw new AuthorizationException('Explicit service management authority required.');
        }
    }

    private function newCredential(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }

    private function audit(Request $request, int $id, string $event, string $reason,
        ?string $before, string $after, ?int $priorVersion, int $newVersion): void
    {
        DB::connection('pgsql_authority')->table('service_audit_events')->insert([
            'actor_user_id' => $request->user()->id,
            'service_identity_id' => $id,
            'event' => $event,
            'reason' => $reason,
            'prior_status' => $before,
            'new_status' => $after,
            'prior_version' => $priorVersion,
            'new_version' => $newVersion,
            'occurred_at' => now(),
        ]);
    }
}

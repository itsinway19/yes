<?php

namespace App\Foundation\Tenancy;

use App\Security\SessionAuthority;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use LogicException;

final class OrganizationTeam
{
    public function __construct(private readonly PrincipalContext $principals) {}

    public function read(Request $request, string $organization): array
    {
        return $this->run($request, fn () => DB::scalar('SELECT builderad_v2_organization_team(?,?::uuid)', [SessionAuthority::sessionKey($request), $organization]));
    }

    public function command(Request $request, string $organization, array $input): array
    {
        return $this->run($request, fn () => DB::scalar('SELECT builderad_v2_organization_team_command(?,?::uuid,?::uuid,?,?::uuid,?)',
            [SessionAuthority::sessionKey($request), $organization, $input['command_id'], $input['effect'], $input['recipient_public_id'], $input['expected_revision']]));
    }

    private function run(Request $request, callable $operation): array
    {
        if (DB::connection()->transactionLevel() !== 0) {
            throw new LogicException('Organization team owns the clean transaction.');
        }

        return DB::transaction(function () use ($request, $operation): array {
            $this->principals->activate($request->user());

            return json_decode($operation(), true, flags: JSON_THROW_ON_ERROR);
        });
    }
}

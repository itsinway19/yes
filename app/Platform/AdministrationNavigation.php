<?php

namespace App\Platform;

use App\MarketKnowledge\MarketOperations;
use App\Security\GrantAuthority;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Presentation projection only. Every destination retains its canonical server guard. */
final class AdministrationNavigation
{
    public function paths(Request $request): array
    {
        if (! $request->is('internal/*') || ! $request->user()) {
            return [];
        }
        $grants = app(GrantAuthority::class);
        $generation = (int) $request->session()->get('a001_generation', 0);
        $paths = ['/internal/security/devices'];
        foreach (['staff.view' => '/internal/administration', 'service.manage' => '/internal/services',
            'business.manage' => '/internal/business-types', MarketOperations::ACTION => '/internal/administration/market-knowledge',
            PlatformMaintenance::ACTION => '/internal/administration/maintenance'] as $action => $path) {
            if ($grants->has($request->user(), $generation, $action)) {
                $paths[] = $path;
            }
        }
        $founder = $grants->canPrepareCommercialMarket($request);
        if ($founder) {
            $paths[] = '/internal/administration/customers';
            if ($grants->has($request->user(), $generation, 'grant.manage')) {
                $paths[] = '/internal/administration/appearance';
            }
        }
        if ($grants->canManageCommercialPreparation($request)) {
            $paths[] = '/internal/administration/commercial-markets';
        }
        if ($founder || DB::connection('pgsql_authority')->table('security_action_grants')->where('subject_user_id', $request->user()->id)
            ->where('subject_generation', $generation)->where('scope_kind', 'commercial')->where('status', 'active')
            ->whereNull('revoked_at')->whereRaw('(expires_at IS NULL OR expires_at > clock_timestamp())')->exists()) {
            $paths[] = '/internal/commercial';
        }

        return $paths;
    }
}

<?php

namespace Tests\Feature\Security;

use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class DeferredCapabilityGateTest extends TestCase
{
    public function test_deferred_owner_team_recovery_export_and_deletion_entry_points_are_closed(): void
    {
        foreach ([
            'workspaces.transfer', 'workspaces.close', 'workspaces.destroy',
            'workspaces.team.index', 'workspaces.team.invite',
            'profile.destroy', 'account.export', 'founder.recover',
        ] as $name) {
            $this->assertFalse(Route::has($name), $name);
        }

        $workspace = '00000000-0000-4000-8000-000000000001';
        foreach ([
            ['POST', '/workspaces/'.$workspace.'/transfer'],
            ['POST', '/workspaces/'.$workspace.'/close'],
            ['DELETE', '/workspaces/'.$workspace],
            ['GET', '/workspaces/'.$workspace.'/team'],
            ['POST', '/workspaces/'.$workspace.'/team/invitations'],
            ['GET', '/account/export'],
            ['DELETE', '/account'],
            ['POST', '/founder/recovery'],
        ] as [$method, $path]) {
            $this->assertContains($this->call($method, $path)->status(), [404, 405], $path);
        }
    }
}

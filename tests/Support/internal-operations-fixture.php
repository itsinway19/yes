<?php

use App\Models\User;
use App\Security\GrantAuthority;
use App\Security\LifecycleAuthority;
use Illuminate\Support\Facades\Hash;

if (getenv('GOALS_SPA_ONLY') !== 'auth' || getenv('INTERNAL_OPERATIONS_SLICE5') !== 'enabled') {
    throw new RuntimeException('Explicit isolated operations proof required');
}

if ($mode === 'operations-commercial-actors') {
    $operator = User::factory()->create(['name' => 'Commercial operator proof', 'email' => 'goals-c@example.test',
        'password' => Hash::make('DisposableGoalsProof42!')]);
    $fixture['c'] = ['user' => $operator->id];
    file_put_contents($file, json_encode($fixture, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
    echo json_encode(['administrator' => User::findOrFail($fixture['b']['user'])->public_id,
        'operator' => $operator->public_id], JSON_THROW_ON_ERROR).PHP_EOL;
    exit;
} elseif ($mode === 'operations-commercial-inspect') {
    echo json_encode(['approvals' => $db->table('security_commercial_market_approvals')->count(),
        'commercialGrants' => $db->table('security_action_grants')->where('scope_kind', 'commercial')->count(),
        'markets' => $db->table('commercial_markets')->count()], JSON_THROW_ON_ERROR).PHP_EOL;
    exit;
} elseif ($mode === 'operations-founder') {
    $db->table('internal_personnel')->where('user_id', $user)->update(['kind' => 'founder',
        'factor_independence_attested_at' => now(), 'factor_independence_attested_by' => $user]);
    foreach (GrantAuthority::STAFF_ACTIONS as $action) {
        $db->table('security_action_grants')->insert(['subject_user_id' => $user, 'source' => 'founder_bootstrap',
            'action' => $action, 'scope_kind' => 'platform', 'subject_generation' => 1, 'reason' => 'isolated operations operator fixture',
            'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
    }
} elseif ($mode === 'operations-complete-target') {
    $target = User::where('email', 'operations-employee@example.test')->sole();
    app(LifecycleAuthority::class)->verifyPrimaryEmail($target);
    foreach (['first', 'second'] as $name) {
        $db->table('passkeys')->insert(['user_id' => $target->id, 'name' => 'Synthetic eligibility '.$name,
            'credential_id' => 'operations-eligibility-'.$name.'-'.$target->id, 'credential' => '{}', 'created_at' => now(), 'updated_at' => now()]);
    }
} elseif (in_array($mode, ['operations-revoke-invite', 'operations-revoke-business', 'operations-revoke-service'], true)) {
    $action = match ($mode) {
        'operations-revoke-business' => 'business.manage', 'operations-revoke-service' => 'service.manage', default => 'staff.invite'
    };
    $db->table('security_action_grants')->where('subject_user_id', $user)->where('action', $action)
        ->update(['status' => 'revoked', 'revoked_at' => now()]);
} elseif ($mode === 'operations-restore-grants') {
    $db->table('security_action_grants')->where('subject_user_id', $user)->update(['status' => 'active', 'revoked_at' => null]);
} elseif ($mode !== 'operations-inspect') {
    throw new RuntimeException('Unknown operations fixture mode');
}
$target = User::where('email', 'operations-employee@example.test')->first();
echo json_encode(['actor' => $user, 'users' => $db->table('users')->count(),
    'services' => $db->table('service_identities')->orderBy('id')->get(['public_id', 'name', 'status', 'version', 'credential_hash']),
    'serviceGrants' => $db->table('service_action_grants')->get(['id', 'status']),
    'serviceAudit' => $db->table('service_audit_events')->pluck('event'),
    'customerEvents' => $db->table('customer_administration_events')->count(),
    'customer' => $db->table('principal_lifecycles')->where('user_id', $fixture['b']['user'])->first(['status', 'generation']),
    'customerActiveSessions' => $db->table('security_sessions')->where('user_id', $fixture['b']['user'])->whereNull('revoked_at')->count(),
    'target' => $target ? ['public_id' => $target->public_id,
        'lifecycle' => $db->table('principal_lifecycles')->where('user_id', $target->id)->value('status'),
        'personnel' => $db->table('internal_personnel')->where('user_id', $target->id)->first(['status', 'attested_at', 'factor_independence_attested_at']),
        'grants' => $db->table('security_action_grants')->where('subject_user_id', $target->id)->get(['id', 'action', 'status'])] : null,
    'audit' => $db->table('security_audit_events')->pluck('event')], JSON_THROW_ON_ERROR).PHP_EOL;
exit;

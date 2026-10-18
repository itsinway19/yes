<?php

use App\Models\User;
use Illuminate\Support\Facades\Hash;

if (getenv('GOALS_SPA_ONLY') !== 'auth' || getenv('INTERNAL_IDENTITY_SLICE4') !== 'enabled') {
    throw new RuntimeException('Explicit isolated internal identity proof required');
}

if ($mode === 'internal-pending') {
    $actor = User::factory()->unverified()->create(['email' => 'internal-pending-'.$who.'@example.test', 'password' => Hash::make('DisposableGoalsProof42!')]);
    $db->table('principal_lifecycles')->where('user_id', $actor->id)->update(['principal_class' => 'staff']);
    $db->table('internal_personnel')->insert(['user_id' => $actor->id, 'kind' => $who === 'a' ? 'employee' : 'founder', 'status' => 'pending',
        'created_at' => now(), 'updated_at' => now()]);
    $fixture['pending_'.$who] = ['user' => $actor->id, 'public_id' => $actor->public_id, 'email' => $actor->email];
    file_put_contents($file, json_encode($fixture, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
    echo json_encode($fixture['pending_'.$who], JSON_THROW_ON_ERROR).PHP_EOL;
    exit;
}
if ($mode === 'internal-founder-actor') {
    $db->table('internal_personnel')->where('user_id', $user)->update(['kind' => 'founder',
        'factor_independence_attested_at' => now(), 'factor_independence_attested_by' => $user]);
    foreach (['staff.view', 'staff.attest', 'staff.activate'] as $action) {
        $db->table('security_action_grants')->insert(['subject_user_id' => $user, 'source' => 'founder_bootstrap',
            'action' => $action, 'scope_kind' => 'platform', 'subject_generation' => 1, 'reason' => 'isolated browser operator fixture',
            'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
    }
} elseif ($mode !== 'internal-status') {
    throw new RuntimeException('Unknown internal fixture action');
}
$pending = $fixture['pending_'.$who]['user'] ?? $user;
echo json_encode(['lifecycle' => $db->table('principal_lifecycles')->where('user_id', $pending)->first(['status', 'generation', 'principal_class']),
    'personnel' => $db->table('internal_personnel')->where('user_id', $pending)->first(['kind', 'status', 'attested_at', 'factor_independence_attested_at']),
    'keys' => $db->table('passkeys')->where('user_id', $pending)->get(['id', 'name']),
    'sessions' => $db->table('security_sessions')->where('user_id', $pending)->get(['id', 'auth_method', 'revoked_at', 'last_seen_at', 'idle_expires_at'])], JSON_THROW_ON_ERROR).PHP_EOL;
exit;

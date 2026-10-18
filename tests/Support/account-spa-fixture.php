<?php

use App\Models\User;
use Illuminate\Support\Facades\Hash;

if (getenv('GOALS_SPA_ONLY') !== 'account') {
    throw new RuntimeException('Explicit isolated account proof required');
}
if ($mode === 'account-email-actor') {
    $actor = User::factory()->create(['email' => 'email-ack@example.test', 'password' => Hash::make('DisposableGoalsProof42!')]);
    $fixture['email'] = ['user' => $actor->id];
    file_put_contents($file, json_encode($fixture, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
    echo json_encode(['user' => $actor->id], JSON_THROW_ON_ERROR).PHP_EOL;
    exit;
}
if ($mode === 'account-age-auth') {
    $db->table('security_sessions')->where('user_id', $user)->update(['authenticated_at' => now()->subHour()]);
} elseif ($mode === 'account-staff') {
    $db->table('principal_lifecycles')->where('user_id', $user)->update(['principal_class' => 'staff']);
    $db->table('internal_personnel')->insert(['user_id' => $user, 'kind' => 'employee', 'status' => 'active', 'attested_by' => $user,
        'attested_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
} elseif ($mode !== 'account-inspect') {
    throw new RuntimeException('Unknown account fixture action');
}
echo json_encode(['keys' => $db->table('passkeys')->where('user_id', $user)->get(['id', 'name']),
    'sessions' => $db->table('security_sessions')->where('user_id', $user)->get(['id', 'auth_method', 'revoked_at']),
    'audit' => $db->table('security_audit_events')->where('subject_user_id', $user)->pluck('event'),
    'email' => $db->table('users')->where('id', $user)->value('email')], JSON_THROW_ON_ERROR).PHP_EOL;
exit;

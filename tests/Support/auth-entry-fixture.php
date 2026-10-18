<?php

use App\Models\User;
use Illuminate\Support\Facades\URL;

if (getenv('GOALS_SPA_ONLY') !== 'auth') {
    throw new RuntimeException('Explicit isolated authentication proof required');
}
if ($mode === 'auth-expire-token') {
    $email = $db->table('users')->where('id', $user)->value('email');
    $db->table('password_reset_tokens')->where('email', $email)->update(['created_at' => now()->subDays(2)]);
} elseif ($mode === 'auth-inspect-email') {
    $actor = $db->table('users')->where('email', 'auth-register@example.test')->first();
    echo json_encode(['user' => $actor?->id, 'verified' => $actor?->email_verified_at !== null,
        'lifecycle' => $actor ? $db->table('principal_lifecycles')->where('user_id', $actor->id)->value('status') : null], JSON_THROW_ON_ERROR).PHP_EOL;
    exit;
} elseif ($mode === 'auth-inspect-unknown') {
    echo json_encode(['count' => $db->table('users')->where('email', 'auth-unknown@example.test')->count()], JSON_THROW_ON_ERROR).PHP_EOL;
    exit;
} elseif ($mode === 'auth-expired-verification') {
    $actor = User::where('email', 'auth-register@example.test')->firstOrFail();
    echo json_encode(['url' => URL::temporarySignedRoute('verification.verify', now()->subMinute(),
        ['id' => $actor->id, 'hash' => sha1($actor->email)])], JSON_THROW_ON_ERROR).PHP_EOL;
    exit;
} elseif ($mode === 'auth-pending-staff') {
    $actor = $db->table('users')->where('email', 'auth-staff@example.test')->first();
    if (! $actor) {
        throw new RuntimeException('Browser registration must precede the staff fixture');
    }
    $db->table('principal_lifecycles')->where('user_id', $actor->id)->where('status', 'pending')->update(['principal_class' => 'staff']);
    $db->table('internal_personnel')->insert(['user_id' => $actor->id, 'kind' => 'employee', 'status' => 'pending',
        'created_at' => now(), 'updated_at' => now()]);
} else {
    throw new RuntimeException('Unknown authentication fixture action');
}
echo json_encode(['updated' => true], JSON_THROW_ON_ERROR).PHP_EOL;
exit;

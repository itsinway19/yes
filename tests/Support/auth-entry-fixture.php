<?php

use App\Models\User;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

if (getenv('GOALS_SPA_ONLY') !== 'auth') {
    throw new RuntimeException('Explicit isolated authentication proof required');
}
if ($mode === 'auth-expire-token') {
    $email = $db->table('users')->where('id', $user)->value('email');
    $db->table('password_reset_tokens')->where('email', $email)->update(['created_at' => now()->subDays(2)]);
} elseif ($mode === 'auth-legacy-unverified') {
    $email = ($argv[3] ?? '') === 'staff' ? 'auth-staff@example.test' : 'auth-legacy@example.test';
    User::factory()->unverified()->create([
        'name' => 'Isolated legacy pending advertiser',
        'email' => $email,
        'password' => 'BrowserRegistrationPassword42!',
    ]);
} elseif ($mode === 'auth-inspect-email') {
    $actor = $db->table('users')->where('email', 'auth-legacy@example.test')->first();
    echo json_encode(['user' => $actor?->id, 'verified' => $actor?->email_verified_at !== null,
        'lifecycle' => $actor ? $db->table('principal_lifecycles')->where('user_id', $actor->id)->value('status') : null], JSON_THROW_ON_ERROR).PHP_EOL;
    exit;
} elseif ($mode === 'auth-inspect-unknown') {
    $email = $argv[3] ?? '';
    if (! preg_match('/^auth-unknown-[a-f0-9-]{36}@example\.test$/D', $email)) {
        throw new RuntimeException('An isolated registration recovery address is required');
    }
    echo json_encode(['count' => $db->table('users')->where('email', $email)->count()], JSON_THROW_ON_ERROR).PHP_EOL;
    exit;
} elseif ($mode === 'auth-open-resend') {
    $attempt = $argv[3] ?? '';
    if (! Str::isUuid($attempt)) {
        throw new RuntimeException('An isolated registration attempt is required');
    }
    $affected = $db->table('registration_email_proofs')->where('attempt_id', $attempt)
        ->whereNull('user_id')->whereNull('verified_at')->update(['resend_at' => now()->subSecond()]);
    echo json_encode(['updated' => $affected], JSON_THROW_ON_ERROR).PHP_EOL;
    exit;
} elseif ($mode === 'auth-expired-verification') {
    $actor = User::where('email', 'auth-legacy@example.test')->firstOrFail();
    echo json_encode(['url' => URL::temporarySignedRoute('verification.verify', now()->subMinute(),
        ['id' => $actor->id, 'hash' => sha1($actor->email)])], JSON_THROW_ON_ERROR).PHP_EOL;
    exit;
} elseif ($mode === 'auth-pending-staff') {
    $actor = $db->table('users')->where('email', 'auth-staff@example.test')->first();
    if (! $actor) {
        throw new RuntimeException('An isolated pending advertiser fixture is required first');
    }
    $db->table('principal_lifecycles')->where('user_id', $actor->id)->where('status', 'pending')->update(['principal_class' => 'staff']);
    $db->table('internal_personnel')->insert(['user_id' => $actor->id, 'kind' => 'employee', 'status' => 'pending',
        'created_at' => now(), 'updated_at' => now()]);
} else {
    throw new RuntimeException('Unknown authentication fixture action');
}
echo json_encode(['updated' => true], JSON_THROW_ON_ERROR).PHP_EOL;
exit;

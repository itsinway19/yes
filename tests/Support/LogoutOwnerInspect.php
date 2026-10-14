<?php

use App\Security\DisposableTestDatabaseGuard;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
app(DisposableTestDatabaseGuard::class)->assertSafe();
$run = getenv('CURRENT_INTENT_PROOF_RUN');
if (realpath(config('session.files')) !== realpath($run.'/ui-storage/framework/sessions')) {
    throw new RuntimeException('Owned storage required');
}
$f = json_decode(file_get_contents($run.'/client-state-fixture.json'), true, flags: JSON_THROW_ON_ERROR);
echo json_encode(DB::connection('pgsql_migrations')->table('security_sessions')->whereIn('user_id', [$f['a'], $f['b']])->orderBy('id')->get(['id', 'user_id', 'revoked_at']), JSON_THROW_ON_ERROR);

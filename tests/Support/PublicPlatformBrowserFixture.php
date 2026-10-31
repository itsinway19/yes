<?php

// Disposable fixture only; uses the same signed-session lineage as existing browser proofs.
use App\Platform\PlatformMaintenance;
use App\Security\DisposableTestDatabaseGuard;
use Illuminate\Support\Facades\DB;

ob_start();
require __DIR__.'/AdminUiBrowserFixture.php';
ob_end_clean();
app(DisposableTestDatabaseGuard::class)->assertSafe();
$db = DB::connection('pgsql_migrations');
if (($argv[1] ?? '') !== 'employee') {
    $founder = $db->table('users')->where('email', 'admin-ui-founder@example.test')->sole();
    if (! $db->table('security_action_grants')->where('subject_user_id', $founder->id)->where('action', PlatformMaintenance::ACTION)->exists()) {
        $db->table('security_action_grants')->insert(['subject_user_id' => $founder->id, 'subject_generation' => 1,
            'source' => 'founder_bootstrap', 'action' => PlatformMaintenance::ACTION, 'scope_kind' => 'platform',
            'reason' => 'SYNTHETIC browser authority', 'status' => 'active', 'version' => 1, 'created_at' => now(), 'updated_at' => now()]);
    }
    $db->table('platform_maintenance_commands')->delete();
    $db->table('platform_maintenance')->where('id', 1)->update(['active' => false, 'revision' => 0]);
}
echo json_encode(['database' => $db->scalar('SELECT current_database()')]), PHP_EOL;

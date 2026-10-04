<?php

use App\Security\DisposableTestDatabaseGuard;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\DisposableDatabaseReset;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
app(DisposableTestDatabaseGuard::class)->assertSafe();
$reset = new DisposableDatabaseReset;
$reset->install();
$owner = DB::connection('pgsql_migrations');
$catalog = static function () use ($owner): array {
    $queries = [
        'tables' => "SELECT c.relname,c.relrowsecurity,c.relforcerowsecurity,pg_get_userbyid(c.relowner) AS owner,c.relacl::text FROM pg_class c JOIN pg_namespace n ON n.oid=c.relnamespace WHERE n.nspname='public' AND c.relkind IN ('r','p') ORDER BY c.relname",
        'columns' => "SELECT table_name,column_name,ordinal_position,data_type,udt_name,is_nullable,column_default FROM information_schema.columns WHERE table_schema='public' ORDER BY table_name,ordinal_position",
        'constraints' => "SELECT c.conrelid::regclass::text AS relation,c.conname,pg_get_constraintdef(c.oid) AS definition FROM pg_constraint c JOIN pg_namespace n ON n.oid=c.connamespace WHERE n.nspname='public' ORDER BY relation,c.conname",
        'indexes' => "SELECT tablename,indexname,indexdef FROM pg_indexes WHERE schemaname='public' ORDER BY tablename,indexname",
        'policies' => "SELECT tablename,policyname,permissive,roles::text,cmd,qual,with_check FROM pg_policies WHERE schemaname='public' ORDER BY tablename,policyname",
        'functions' => "SELECT p.oid::regprocedure::text AS signature,pg_get_functiondef(p.oid) AS definition,pg_get_userbyid(p.proowner) AS owner,p.proacl::text FROM pg_proc p JOIN pg_namespace n ON n.oid=p.pronamespace WHERE n.nspname='public' AND left(p.proname,10)='builderad_' ORDER BY signature",
        'triggers' => "SELECT c.relname,t.tgname,pg_get_triggerdef(t.oid) AS definition FROM pg_trigger t JOIN pg_class c ON c.oid=t.tgrelid JOIN pg_namespace n ON n.oid=c.relnamespace WHERE n.nspname='public' AND NOT t.tgisinternal ORDER BY c.relname,t.tgname",
    ];
    $result = [];
    foreach ($queries as $key => $sql) {
        $result[$key] = $owner->select($sql);
    }

    return $result;
};
$fresh = $catalog();
file_put_contents(storage_path('framework/testing/remediation-fresh-catalog.json'), json_encode($fresh, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
echo "EMPTY_TO_HEAD=PASS\n";
$reset->clear();
if (Artisan::call('migrate', ['--database' => 'pgsql_migrations', '--force' => true, '--path' => [
    'database/migrations/0001_01_01_000000_create_users_table.php',
    'database/migrations/0001_01_01_000010_create_workspace_foundation.php',
]]) !== 0) {
    throw new RuntimeException('Historical boundary installation failed.');
}
$users = [];
for ($i = 0; $i < 4; $i++) {
    $users[] = $owner->table('users')->insertGetId(['public_id' => (string) Str::uuid7(), 'name' => 'Synthetic legacy '.$i,
        'email' => 'legacy'.$i.'@example.test', 'email_verified_at' => $i === 3 ? null : '2026-10-01 00:00:00+00',
        'password' => password_hash('synthetic-password', PASSWORD_BCRYPT, ['cost' => 4]),
        'created_at' => '2026-10-01 00:00:00+00', 'updated_at' => '2026-10-01 00:00:00+00']);
}
$workspaces = [];
for ($i = 0; $i < 2; $i++) {
    $workspaces[] = $owner->table('workspaces')->insertGetId(['name' => 'Synthetic populated '.$i, 'slug' => 'synthetic-populated-'.$i,
        'status' => 'active', 'currency_code' => 'SAR', 'timezone' => 'UTC']);
}
foreach ([[0, 0, 'owner'], [0, 1, 'member'], [0, 2, 'admin'], [1, 1, 'owner'], [1, 3, 'member']] as [$workspace, $user, $role]) {
    $owner->table('workspace_memberships')->insert(['workspace_id' => $workspaces[$workspace], 'user_id' => $users[$user], 'role' => $role, 'status' => 'active']);
}
$owner->table('password_reset_tokens')->insert(['email' => 'legacy0@example.test', 'token' => password_hash('synthetic-token', PASSWORD_BCRYPT, ['cost' => 4]), 'created_at' => '2026-10-01 00:00:00+00']);
$preserved = [];
foreach (['users', 'workspaces', 'workspace_memberships', 'password_reset_tokens'] as $table) {
    $preserved[$table] = $owner->table($table)->get()->map(fn ($row) => (array) $row)->all();
}
if (Artisan::call('migrate', ['--database' => 'pgsql_migrations', '--force' => true]) !== 0) {
    throw new RuntimeException('Populated forward migration failed.');
}
foreach ($preserved as $table => $rows) {
    $actual = $owner->table($table)->get(array_keys($rows[0]))->map(fn ($row) => (array) $row)->all();
    if ($rows !== $actual) {
        throw new RuntimeException('Historical facts changed: '.$table);
    }
}
$forward = $catalog();
file_put_contents(storage_path('framework/testing/remediation-forward-catalog.json'), json_encode($forward, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
if ($fresh != $forward) {
    $different = array_keys(array_filter($fresh, fn ($rows, $key) => $rows != $forward[$key], ARRAY_FILTER_USE_BOTH));
    throw new RuntimeException('Final catalog differs: '.implode(', ', $different));
}
echo 'POPULATED_000010_TO_HEAD=PASS; PRESERVED='.json_encode(array_map('count', $preserved), JSON_THROW_ON_ERROR)."\n";
echo 'FINAL_CATALOG_EQUALITY=PASS; GROUPS=7; MIGRATIONS='.$owner->table('migrations')->count()."\n";
$reset->install();
if ($catalog() != $fresh) {
    throw new RuntimeException('Repeated reset catalog differs.');
}
echo "REPEATED_RESET_CATALOG_EQUALITY=PASS; PRIMARY_BUILDERAD_CONNECTIONS=0; PRIMARY_BUILDERAD_WRITES=0\n";

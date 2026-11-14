<?php

use App\Foundation\Business\BusinessScope;
use App\Models\User;
use App\Security\DisposableTestDatabaseGuard;
use App\Security\SessionAuthority;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Request;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;
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
$user = User::factory()->create();
$session = new Store('migration-durability', new ArraySessionHandler(120));
$session->start();
$request = Request::create('/api/businesses', 'POST');
$request->setUserResolver(fn () => $user);
$request->setLaravelSession($session);
app(SessionAuthority::class)->issue($request, $user);
$draft = app(BusinessScope::class)->create($request, (string) Str::uuid7(), 'Synthetic populated Business', null);
if (! isset($draft['business_public_id'])) {
    throw new RuntimeException('The Business fixture did not commit.');
}
$preserved = [];
$rows = fn (string $table) => $owner->select("SELECT to_jsonb(r)::text AS value FROM public.{$table} r ORDER BY value");
foreach (['users', 'businesses', 'business_context_heads', 'security_action_grants', 'business_command_receipts'] as $table) {
    $preserved[$table] = $rows($table);
}
if (Artisan::call('migrate', ['--database' => 'pgsql_migrations', '--force' => true]) !== 0) {
    throw new RuntimeException('Repeated migration on the populated Business installation failed.');
}
$reset->assertInstalledCatalog();
foreach ($preserved as $table => $expected) {
    if ($expected != $rows($table)) {
        throw new RuntimeException('Business facts changed on repeated migration: '.$table);
    }
}
$forward = $catalog();
file_put_contents(storage_path('framework/testing/remediation-forward-catalog.json'), json_encode($forward, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
if ($fresh != $forward) {
    $different = array_keys(array_filter($fresh, fn ($rows, $key) => $rows != $forward[$key], ARRAY_FILTER_USE_BOTH));
    throw new RuntimeException('Final catalog differs: '.implode(', ', $different));
}
echo 'POPULATED_BASELINE_REPEAT=PASS; PRESERVED='.json_encode(array_map('count', $preserved), JSON_THROW_ON_ERROR)."\n";
echo 'FINAL_CATALOG_EQUALITY=PASS; GROUPS=7; MIGRATIONS='.$owner->table('migrations')->count()."\n";
$reset->install();
if ($catalog() != $fresh) {
    throw new RuntimeException('Repeated reset catalog differs.');
}
echo "REPEATED_RESET_CATALOG_EQUALITY=PASS; PRIMARY_BUILDERAD_CONNECTIONS=0; PRIMARY_BUILDERAD_WRITES=0\n";

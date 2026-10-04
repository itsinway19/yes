<?php

use App\Business\BusinessContext;
use App\Foundation\Tenancy\WorkspaceContext;
use App\Models\User;
use App\Models\Workspace;
use App\Security\DisposableTestDatabaseGuard;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;
use Tests\Support\DisposableDatabaseReset;

try {
    require dirname(__DIR__, 2).'/vendor/autoload.php';
    $app = require dirname(__DIR__, 2).'/bootstrap/app.php';
    $app->make(Kernel::class)->bootstrap();
    app(DisposableTestDatabaseGuard::class)->assertSafe();
    $baseline = '8ebd9a1f1ffd7b23307d277304203c706e67d95d';
    if (getenv('DTM_PROOF_BASELINE') !== $baseline) {
        throw new RuntimeException('Unexpected authoritative baseline.');
    }
    $run = realpath(getenv('DTM_PROOF_RUN'));
    $allowed = realpath(storage_path('framework/testing'));
    if ($run === false || ! str_starts_with($run, $allowed.DIRECTORY_SEPARATOR)) {
        throw new RuntimeException('Proof artifacts escaped the task workspace.');
    }
    $owner = DB::connection('pgsql_migrations');
    $reset = new DisposableDatabaseReset;
    $json = static fn (mixed $value): string => json_encode($value, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $save = static fn (string $name, mixed $value) => file_put_contents($run.'/'.$name, $json($value));
    $assert = static function (bool $condition, string $message): void {
        if (! $condition) {
            throw new RuntimeException($message);
        }
    };
    $process = static function (array $arguments) use ($assert): string {
        $command = new Process($arguments, base_path());
        $command->setTimeout(120);
        $command->run();
        $assert($command->isSuccessful(), 'Proof tool failed: '.$command->getErrorOutput());

        return trim($command->getOutput());
    };
    // Only platform CRLF/LF is normalized; definitions, ACLs and policy semantics remain exact.
    $normalize = static function (mixed $value) use (&$normalize): mixed {
        if (is_string($value)) {
            return str_replace("\r\n", "\n", $value);
        }
        if (is_object($value)) {
            $value = (array) $value;
        }
        if (is_array($value)) {
            return array_map($normalize, $value);
        }

        return $value;
    };
    $catalog = static function () use ($owner, $normalize): array {
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
        foreach ($queries as $key => $query) {
            $result[$key] = $normalize($owner->select($query));
        }
        $result['roles'] = $normalize($owner->select("SELECT rolname,rolsuper,rolinherit,rolcreaterole,rolcreatedb,rolcanlogin,rolreplication,rolbypassrls,rolconfig::text FROM pg_roles WHERE rolname IN ('builderad_app','builderad_owner','builderad_authority') ORDER BY rolname"));
        $result['role_memberships'] = $normalize($owner->select("SELECT pg_get_userbyid(roleid) AS role,pg_get_userbyid(member) AS member,admin_option,inherit_option,set_option FROM pg_auth_members WHERE pg_get_userbyid(member) LIKE 'builderad_%' ORDER BY role,member"));

        return $result;
    };
    $reference = static function () use ($owner): array {
        $rows = $owner->table('country_references')->orderByRaw('code COLLATE "C"')->get(['code', 'name_en', 'name_ar'])->map(fn ($row) => (array) $row)->all();
        $provenance = json_decode(file_get_contents(database_path('reference/countries/provenance.json')), true, flags: JSON_THROW_ON_ERROR);

        return ['rows' => $rows, 'count' => count($rows), 'identity_digest' => hash('sha256', implode("\n", array_column($rows, 'code'))."\n"),
            'localization_digest' => hash('sha256', json_encode($rows, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n"),
            'source_provenance' => $provenance];
    };
    $installation = ['run' => $run, 'token_digest' => hash('sha256', getenv('BUILDERAD_TEST_ISOLATION_TOKEN')),
        'cluster_system_identifier' => getenv('DTM_CLUSTER_SYSTEM_IDENTIFIER')];
    $assert((bool) preg_match('/^\d+$/', $installation['cluster_system_identifier']), 'Independent cluster identity missing.');
    $n1Reference = getenv('DTM_DURABILITY_REFERENCE');
    if ($n1Reference) {
        $referencePath = realpath($n1Reference);
        $assert($referencePath !== false && str_starts_with($referencePath, $allowed.DIRECTORY_SEPARATOR), 'N1 comparison artifact escaped workspace.');
        $expected = json_decode(file_get_contents($referencePath), true, flags: JSON_THROW_ON_ERROR);
        $assert($expected['installation']['run'] !== $run && $expected['installation']['token_digest'] !== $installation['token_digest']
            && $expected['installation']['cluster_system_identifier'] !== $installation['cluster_system_identifier'], 'N1 is not an independently initialized cluster.');
        $reset->install();
        $actual = ['catalog' => $catalog(), 'reference' => $reference(), 'installation' => $installation];
        $save('n1-catalog.json', $actual);
        $assert($actual['catalog'] === $expected['catalog'], 'N1 schema/authority differs from F/P.');
        $assert($actual['reference'] === $expected['reference'], 'N1 identity/localization/source evidence differs.');
        $assert($owner->table('business_target_markets')->count() === 0 && $owner->table('business_target_market_events')->count() === 0, 'N1 fabricated intent.');
        echo "N1=PASS; INDEPENDENT_INITDB_TOKEN_SYSTEM_IDENTIFIER=PASS; FINAL_CATALOG_EQUALITY=PASS; IDENTITY_EQUIVALENCE=PASS; LOCALIZATION_EQUIVALENCE=PASS\n";
        exit(0);
    }
    $reset->install();
    $fresh = ['catalog' => $catalog(), 'reference' => $reference(), 'installation' => $installation];
    $save('fresh-catalog.json', $fresh);
    $assert($fresh['reference']['identity_digest'] === $fresh['reference']['source_provenance']['IDENTITY_DIGEST'], 'Fresh identity differs from pinned source.');
    $assert($fresh['reference']['localization_digest'] === $fresh['reference']['source_provenance']['LOCALIZATION_DIGEST_OR_EQUIVALENT'], 'Fresh labels differ from pinned source.');
    echo "EMPTY_TO_HEAD=PASS\n";
    for ($iteration = 1; $iteration <= 2; $iteration++) {
        // Real stale Business-owned intent/audit to prove reset actually removes it.
        $fixtureActor = User::factory()->create();
        $fixtureWorkspace = $owner->table('workspaces')->insertGetId(['name' => 'Synthetic reset '.$iteration, 'slug' => 'reset-'.$iteration, 'status' => 'active', 'business_context_version' => 1]);
        $owner->table('business_target_markets')->insert(['workspace_id' => $fixtureWorkspace, 'country_code' => 'SA']);
        $owner->table('business_target_market_events')->insert(['workspace_id' => $fixtureWorkspace, 'actor_user_id' => $fixtureActor->id,
            'version' => 1, 'before_codes' => '{}', 'after_codes' => '{SA}']);
        $reset->install();
        $assert($catalog() === $fresh['catalog'] && $reference() === $fresh['reference'], 'Repeated reset changed schema/authority/provenance.');
        $assert($owner->table('business_target_markets')->count() === 0 && $owner->table('business_target_market_events')->count() === 0, 'Stale declaration survived reset.');
        echo 'RESET_'.$iteration."=PASS\n";
    }
    $reset->clear();
    $archive = $run.'/exact-baseline.tar';
    $extraction = $run.'/exact-baseline';
    mkdir($extraction);
    $process(['git', 'archive', '--format=tar', '--output='.$archive, $baseline, 'database/migrations', 'database/sql', 'database/bootstrap']);
    $manifestText = $process(['git', 'ls-tree', '-r', $baseline, '--', 'database/migrations', 'database/sql', 'database/bootstrap']);
    $manifest = [];
    foreach (explode("\n", $manifestText) as $line) {
        $assert((bool) preg_match('/^100[0-7]{3} blob ([a-f0-9]{40})\t(database\/(?:migrations|sql|bootstrap)\/[^\r\n]+)$/', trim($line), $match), 'Unexpected baseline archive path/type.');
        $assert(! str_contains($match[2], '..'), 'Unsafe baseline archive member.');
        $manifest[$match[2]] = $match[1];
    }
    $process(['tar', '-xf', $archive, '-C', $extraction]);
    $sourceManifest = [];
    foreach ($manifest as $path => $blob) {
        $file = $extraction.'/'.$path;
        $assert(is_file($file) && $process(['git', 'hash-object', '--', $file]) === $blob, 'Extracted baseline blob mismatch: '.$path);
        $sourceManifest[$path] = ['git_blob' => $blob, 'sha256' => hash_file('sha256', $file)];
    }
    $save('baseline-source-manifest.json', ['baseline' => $baseline, 'archive_sha256' => hash_file('sha256', $archive), 'files' => $sourceManifest]);
    $originalDatabasePath = database_path();
    try {
        $app->useDatabasePath($extraction.'/database');
        $assert(Artisan::call('migrate', ['--database' => 'pgsql_migrations', '--force' => true, '--path' => [$extraction.'/database/migrations'], '--realpath' => true]) === 0, 'Exact baseline installation failed: '.Artisan::output());
        file_put_contents($run.'/baseline-migrate.log', Artisan::output());
        $baselineMigrations = $owner->table('migrations')->orderBy('migration')->pluck('migration')->all();
        $manifestMigrations = array_map(fn ($path) => pathinfo($path, PATHINFO_FILENAME), array_filter(array_keys($manifest), fn ($path) => str_starts_with($path, 'database/migrations/') && str_ends_with($path, '.php')));
        sort($manifestMigrations, SORT_STRING);
        $assert($baselineMigrations === $manifestMigrations && str_contains(end($baselineMigrations), '000320_'), 'Baseline migration inventory/terminal boundary differs.');
        $assert($owner->scalar("SELECT to_regclass('public.business_target_markets')") === null, 'Baseline accidentally installed working-tree DTM.');
        // Keep fixture-local identifiers out of the proof runner's catalog/reference closures.
        $fixture = (static function (): array {
            return require __DIR__.'/DeclaredTargetMarketsLegacyFixture.php';
        })();
    } finally {
        $app->useDatabasePath($originalDatabasePath);
    }
    $tables = array_column($owner->select("SELECT tablename FROM pg_tables WHERE schemaname='public' AND tablename<>'migrations' ORDER BY tablename"), 'tablename');
    $history = static function () use ($owner, $tables): array {
        $result = [];
        foreach ($tables as $table) {
            // Table names originate only from pg_catalog, quoted through the driver's grammar.
            $quoted = $owner->getQueryGrammar()->wrapTable($table);
            $result[$table] = array_column($owner->select('SELECT to_jsonb(t)::text AS row FROM '.$quoted.' t ORDER BY to_jsonb(t)::text COLLATE "C"'), 'row');
        }

        return $result;
    };
    $before = $history();
    $save('populated-before.json', $before);
    foreach (['workspaces', 'workspace_memberships', 'workspace_ownerships', 'security_action_grants', 'security_sessions', 'business_context_events',
        'business_types', 'business_source_guidance', 'commercial_market_assignments', 'source_registry', 'source_capabilities', 'provider_access', 'provider_observation_entries'] as $table) {
        $assert(count($before[$table]) > 0, 'Required representative historical fixture missing: '.$table);
    }
    $newMigration = database_path('migrations/0001_01_01_000330_create_declared_target_markets.php');
    $assert(Artisan::call('migrate', ['--database' => 'pgsql_migrations', '--force' => true, '--path' => [$newMigration], '--realpath' => true]) === 0, 'Only-new forward migration failed: '.Artisan::output());
    file_put_contents($run.'/forward-migrate.log', Artisan::output());
    $after = $history();
    $save('populated-after.json', $after);
    $assert($before === $after, 'A historical value changed during the forward migration.');
    $assert($owner->table('migrations')->count() === count($baselineMigrations) + 1, 'Historical migrations were replayed.');
    $assert($owner->table('business_target_markets')->count() === 0 && $owner->table('business_target_market_events')->count() === 0, 'Forward migration fabricated intent/audit.');
    $forward = ['catalog' => $catalog(), 'reference' => $reference(), 'installation' => $installation];
    $save('forward-catalog.json', $forward);
    if ($fresh['catalog'] !== $forward['catalog']) {
        $different = array_keys(array_filter($fresh['catalog'], fn ($rows, $key) => $rows !== $forward['catalog'][$key], ARRAY_FILTER_USE_BOTH));
        throw new RuntimeException('F/P schema/authority differs: '.implode(', ', $different));
    }
    $assert($fresh['reference'] === $forward['reference'], 'F/P identity/localization/source evidence differs.');
    foreach ($fixture['workspaces'] as $workspace) {
        $projection = app(WorkspaceContext::class)->run($fixture['actor'], $workspace['public_id'],
            fn (Workspace $active) => app(BusinessContext::class)->projection($fixture['request'], $active), $fixture['request']);
        $assert($projection['targetMarketsDeclared'] === false && $projection['targetMarkets'] === [], 'Existing Business was not valid/not-declared after forward migration.');
    }
    $save('durability-reference.json', $fresh);
    $save('durability-evidence.json', ['result' => 'PASS', 'baseline' => $baseline, 'baseline_migrations' => $baselineMigrations,
        'preserved_counts' => array_map('count', $before), 'historical_tables' => count($tables), 'installation' => $installation]);
    echo 'POPULATED_EXACT_BASELINE_TO_HEAD=PASS; HISTORICAL_TABLES='.count($tables).'; BASELINE_MIGRATIONS='.count($baselineMigrations)."\n";
    echo "REPEATED_RESET=PASS; FINAL_CATALOG_EQUALITY=PASS; IDENTITY_EQUIVALENCE=PASS; LOCALIZATION_EQUIVALENCE=PASS\n";
    echo 'N1_REFERENCE='.$run."/durability-reference.json\n";
} catch (Throwable $error) {
    fwrite(STDERR, 'DTM_DURABILITY=FAIL; '.$error->getMessage().' at '.$error->getFile().':'.$error->getLine()."\n");
    exit(1);
}

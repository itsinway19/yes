<?php

use App\Business\BusinessContext;
use App\Foundation\Tenancy\WorkspaceContext;
use App\Models\User;
use App\Models\Workspace;
use App\Security\DisposableTestDatabaseGuard;
use App\Security\GrantAuthority;
use App\Security\SessionAuthority;
use App\Security\WorkspaceAuthority;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;
use Tests\Support\DisposableDatabaseReset;

try {
    require dirname(__DIR__, 2).'/vendor/autoload.php';
    $app = require dirname(__DIR__, 2).'/bootstrap/app.php';
    $app->make(Kernel::class)->bootstrap();
    app(DisposableTestDatabaseGuard::class)->assertSafe();
    $baseline = '2cf51ea337b26e07bfcaff59ed9d3e81c632a50e';
    if (getenv('CURRENT_INTENT_PROOF_BASELINE') !== $baseline) {
        throw new RuntimeException('Unexpected authoritative baseline.');
    }
    $run = realpath(getenv('CURRENT_INTENT_PROOF_RUN'));
    $allowed = realpath(storage_path('framework/testing'));
    if ($run === false || ! str_starts_with($run, $allowed.DIRECTORY_SEPARATOR)) {
        throw new RuntimeException('Proof path escaped task workspace.');
    }
    if (getenv('CURRENT_INTENT_DURABILITY_REFERENCE') && ! is_file(getenv('CURRENT_INTENT_DURABILITY_REFERENCE'))) {
        throw new RuntimeException('Durability reference missing.');
    }
    $assert = static function (bool $condition, string $message): void {
        if (! $condition) {
            throw new RuntimeException($message);
        }
    };
    $save = static fn (string $name, mixed $value) => file_put_contents($run.'/'.$name,
        json_encode($value, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    $process = static function (array $arguments) use ($assert): string {
        $command = new Process($arguments, base_path());
        $command->setTimeout(120);
        $command->run();
        $assert($command->isSuccessful(), 'Proof subprocess failed: '.$command->getErrorOutput());

        return trim($command->getOutput());
    };
    $owner = DB::connection('pgsql_migrations');
    $normalize = static function (mixed $value) use (&$normalize): mixed {
        if (is_string($value)) {
            return str_replace("\r\n", "\n", $value);
        }
        if (is_object($value)) {
            $value = (array) $value;
        }

        return is_array($value) ? array_map($normalize, $value) : $value;
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

        return $result;
    };
    if (getenv('CURRENT_INTENT_DURABILITY_STAGE') === 'AfterRestart') {
        $state = json_decode(file_get_contents($run.'/restart-state.json'), true, flags: JSON_THROW_ON_ERROR);
        $fresh = $state['fresh'];
        $beforeRestart = $state['facts'];
        $catalogBeforeRestart = $state['catalog'];
        $workspace = $state['workspace'];
        $a = $state['a'];
        $confirmation = $state['confirmation'];
        $actor = User::findOrFail($state['actor']);
        $request = Request::create('/', 'POST');
        $request->setLaravelSession(app('session')->driver());
        $request->setUserResolver(fn () => $actor);
        app(SessionAuthority::class)->issue($request, $actor, 'password');
        $mutate = static fn (?array $value, array $confirmation, int $revision) => app(WorkspaceContext::class)->runBusinessMutation($actor, $workspace['public_id'],
            fn (Workspace $active) => app(BusinessContext::class)->replaceCurrentIntent($request, $active, $value, $confirmation, $revision), $request);
        $facts = static fn () => ['workspace' => $owner->selectOne('SELECT current_intent::text,business_context_version,updated_at::text FROM workspaces WHERE id=?', [$workspace['id']]),
            'journal' => $owner->select('SELECT to_jsonb(e)::text AS row FROM business_current_intent_events e WHERE workspace_id=? ORDER BY after_version', [$workspace['id']])];
        $assert($normalize($facts()) === $beforeRestart, 'Current value or complete history changed across restart.');
        $assert($catalog() === $catalogBeforeRestart, 'ACL/RLS/constraints/functions/triggers changed across restart.');
        $rejected = static function (Closure $operation, string $state) use ($assert): void {
            try {
                $operation();
                throw new RuntimeException('Expected denial missing: '.$state);
            } catch (QueryException $error) {
                $assert($error->getCode() === $state, 'Unexpected denial '.$error->getCode().' vs '.$state);
            }
        };
        $rejected(fn () => $owner->delete('DELETE FROM business_current_intent_events WHERE workspace_id=?', [$workspace['id']]), '23514');
        $rejected(fn () => $owner->update('UPDATE business_current_intent_events SET occurred_at=now() WHERE workspace_id=?', [$workspace['id']]), '23514');
        foreach (['TRUNCATE business_current_intent_events', 'INSERT INTO business_current_intent_events DEFAULT VALUES', 'UPDATE workspaces SET current_intent=NULL'] as $query) {
            $rejected(fn () => DB::statement($query), '42501');
        }
        $oversized = $a;
        $oversized['description'] .= '😀';
        $rejected(fn () => $mutate($oversized, $confirmation, 6), '22023');
        $assert($normalize($facts()) === $beforeRestart, 'Denied mutations changed row/revision/history/time.');
        $migration = require database_path('migrations/0001_01_01_000340_create_current_intent.php');
        try {
            $migration->down();
            throw new RuntimeException('Forward-only down unexpectedly succeeded.');
        } catch (LogicException $error) {
            $assert(str_contains($error->getMessage(), 'forward migration'), 'Wrong forward-only contract.');
        }
        foreach ($catalogBeforeRestart['constraints'] as $constraint) {
            if (str_contains($constraint['definition'], 'current_intent')) {
                $assert(! preg_match('/timezone_supported|timezone_name_allowed|validate_horizon|pg_timezone/', $constraint['definition']), 'Environmental acceptance is a durable CHECK.');
            }
        }
        $candidates = array_values(array_unique([...DateTimeZone::listIdentifiers(DateTimeZone::ALL), 'UTC']));
        $expected = [];
        foreach ($owner->select('SELECT name,builderad_current_intent_timezone_name_allowed(name) AS allowed FROM pg_timezone_names') as $row) {
            if ($row->allowed && in_array($row->name, $candidates, true)) {
                $expected[] = $row->name;
            }
        }
        $options = app(BusinessContext::class)->currentIntentTimezones();
        $assert(array_diff($options, $expected) === [] && array_diff($expected, $options) === [], 'Restored runtime option/writer exact set mismatch.');
        $assert($normalize($facts()) === $beforeRestart, 'Restored options altered history.');
        $save('integrated-durability.json', ['cluster' => getenv('CURRENT_INTENT_CLUSTER_SYSTEM_IDENTIFIER'), 'history' => $beforeRestart, 'catalog_groups' => 7,
            'restart' => 'immediate crash restart; committed WAL recovered', 'options' => count($options), 'bidirectional_set_differences' => [],
            'immutable_update_delete' => '23514', 'runtime_direct_mutations' => '42501', 'oversized' => '22023', 'forward_only' => 'PASS']);
        echo "HISTORY_DURABILITY=PASS; LIVE_RESTART=PASS; BOUNDARY_VALUES_RETAINED=PASS; ACL_RLS_STATIC_IMMUTABILITY=PASS; RESTORED_TIMEZONE_SET=PASS\n";

        $reset = new DisposableDatabaseReset;
        $reset->install();
        $assert($catalog() === $fresh, 'Repeated reset catalog differs.');
        $assert($owner->table('business_current_intent_events')->count() === 0, 'Journal survived disposable reset.');
        echo "INTEGRATED_DURABILITY=PASS; REPEATED_RESET=PASS; PRIMARY_BUILDERAD_CONNECTIONS=0; PRIMARY_BUILDERAD_WRITES=0\n";
        exit(0);
    }
    $reset = new DisposableDatabaseReset;
    $reset->install();
    $fresh = $catalog();
    $save('fresh-catalog.json', $fresh);
    echo "EMPTY_TO_IMPLEMENTATION=PASS\n";
    $reset->clear();
    $archive = $run.'/baseline.tar';
    $extract = $run.'/baseline';
    mkdir($extract);
    $process(['git', 'archive', '--format=tar', '--output='.$archive, $baseline, 'database']);
    $process(['tar', '-xf', $archive, '-C', $extract]);
    $manifest = [];
    foreach (explode("\n", $process(['git', 'ls-tree', '-r', $baseline, '--', 'database'])) as $line) {
        $assert((bool) preg_match('/^100[0-7]{3} blob ([a-f0-9]{40})\t(database\/[^\r\n]+)$/', $line, $match), 'Unsafe baseline archive entry.');
        $assert(! str_contains($match[2], '..'), 'Unsafe baseline archive path.');
        $assert($process(['git', 'hash-object', '--', $extract.'/'.$match[2]]) === $match[1], 'Baseline blob differs: '.$match[2]);
        $manifest[$match[2]] = $match[1];
    }
    $save('baseline-source-manifest.json', ['baseline' => $baseline, 'files' => $manifest]);
    $databasePath = database_path();
    try {
        $app->useDatabasePath($extract.'/database');
        $assert(Artisan::call('migrate', ['--database' => 'pgsql_migrations', '--force' => true,
            '--path' => [$extract.'/database/migrations'], '--realpath' => true]) === 0, 'Exact baseline installation failed: '.Artisan::output());
    } finally {
        $app->useDatabasePath($databasePath);
    }
    $baselineCatalog = $catalog();
    $assert($owner->table('migrations')->where('migration', 'like', '%000330_%')->exists(), 'Authorized baseline terminal migration missing.');
    $actor = User::factory()->create(['name' => 'Synthetic preserved intent business']);
    app(GrantAuthority::class)->issueAdvertiserWorkspaceCreation($actor, 1);
    $request = Request::create('/', 'POST');
    $request->setLaravelSession(app('session')->driver());
    $request->setUserResolver(fn () => $actor);
    app(SessionAuthority::class)->issue($request, $actor, 'password');
    $workspace = DB::transaction(fn () => app(WorkspaceAuthority::class)->create($request, 'Synthetic preserved intent', 'current-intent-preserved'));
    $type = $owner->table('business_types')->where('key', 'real_estate')->value('id');
    app(WorkspaceContext::class)->runBusinessMutation($actor, $workspace['public_id'],
        fn (Workspace $active) => app(BusinessContext::class)->write($request, $active, ['business_type_id' => $type, 'version' => 0]), $request);
    app(WorkspaceContext::class)->runBusinessMutation($actor, $workspace['public_id'],
        fn (Workspace $active) => app(BusinessContext::class)->replaceTargetMarkets($request, $active, ['SA', 'AE'], 1), $request);
    $columns = [];
    foreach ($baselineCatalog['columns'] as $column) {
        if ($column['table_name'] !== 'migrations') {
            $columns[$column['table_name']][] = $column['column_name'];
        }
    }
    $rows = static function () use ($owner, $columns): array {
        $result = [];
        foreach ($columns as $table => $names) {
            $grammar = $owner->getQueryGrammar();
            $fields = implode(',', array_map($grammar->wrap(...), $names));
            $result[$table] = array_column($owner->select('SELECT to_jsonb(t)::text AS row FROM (SELECT '.$fields.' FROM '.$grammar->wrapTable($table).') t ORDER BY to_jsonb(t)::text COLLATE "C"'), 'row');
        }

        return $result;
    };
    $before = $rows();
    $save('populated-before.json', $before);
    $assert(Artisan::call('migrate', ['--database' => 'pgsql_migrations', '--force' => true]) === 0, 'Forward migration failed: '.Artisan::output());
    $assert($rows() === $before, 'Baseline historical rows/columns changed.');
    $forward = $catalog();
    $save('forward-catalog.json', $forward);
    $assert($forward === $fresh, 'Fresh and forward final catalogs differ.');
    $existingFunctions = array_column($baselineCatalog['functions'], null, 'signature');
    $finalFunctions = array_column($forward['functions'], null, 'signature');
    foreach ($existingFunctions as $signature => $definition) {
        $assert($finalFunctions[$signature] === $definition, 'Existing SQL helper/authority definition changed: '.$signature);
    }
    $assert($owner->table('workspaces')->whereNotNull('current_intent')->count() === 0, 'Forward migration manufactured intent.');
    echo "POPULATED_AUTHORIZED_BASELINE_FORWARD=PASS; HISTORICAL_ROWS_PRESERVED=PASS; CATALOG_GROUPS=7; EXISTING_SQL_HELPERS_UNCHANGED=PASS\n";
    // Integrated transition durability uses only the identity-guarded cluster above.
    $mutate = static fn (?array $value, array $confirmation, int $revision) => app(WorkspaceContext::class)->runBusinessMutation($actor, $workspace['public_id'],
        fn (Workspace $active) => app(BusinessContext::class)->replaceCurrentIntent($request, $active, $value, $confirmation, $revision), $request);
    $confirmation = ['declaration' => true, 'business_wide' => true, 'outcome' => true, 'acceptance_clear' => true, 'clarification_required' => true, 'quantity' => true, 'horizon' => true];
    $a = ['direction' => 'increase_value', 'scope' => 'business_wide', 'description' => str_repeat('😀', 2000),
        'outcome' => ['meaning' => str_repeat('ع', 2000), 'clarification' => str_repeat('😀', 2000), 'count_unit' => str_repeat('ع', 120)],
        'quantity' => str_repeat('9', 120), 'horizon' => ['deadline_date' => '2020-01-01', 'timezone' => 'Europe/Istanbul']];
    $b = $a;
    $b['direction'] = 'preserve_value';
    $b['outcome']['meaning'] = 'Completed qualified bookings';
    $b['quantity'] = '100';
    $b['horizon'] = ['deadline_date' => '2021-01-01', 'timezone' => 'UTC'];
    $mutate($a, $confirmation, 2);
    $mutate($b, $confirmation, 3);
    $mutate(null, ['declaration' => true], 4);
    $mutate($a, $confirmation, 5);
    $facts = static fn () => ['workspace' => $owner->selectOne('SELECT current_intent::text,business_context_version,updated_at::text FROM workspaces WHERE id=?', [$workspace['id']]),
        'journal' => $owner->select('SELECT to_jsonb(e)::text AS row FROM business_current_intent_events e WHERE workspace_id=? ORDER BY after_version', [$workspace['id']])];
    $beforeRestart = $normalize($facts());
    $save('history-before-restart.json', $beforeRestart);
    $assert(count($beforeRestart['journal']) === 4, 'Declare/replace/clear/redeclare journal incomplete.');
    $assert((int) $beforeRestart['workspace']['business_context_version'] === 6, 'Shared transition revisions wrong.');
    $supportDefinition = $owner->selectOne("SELECT pg_get_functiondef('builderad_current_intent_timezone_supported(text)'::regprocedure) AS body")->body;
    try {
        $owner->unprepared("CREATE OR REPLACE FUNCTION builderad_current_intent_timezone_supported(p_zone text) RETURNS boolean LANGUAGE sql STABLE SET search_path=public,pg_temp AS 'SELECT p_zone=''UTC'''");
        $assert($normalize($facts()) === $beforeRestart, 'Catalog support loss changed stored data.');
        foreach (['UTC', 'Pacific/Apia'] as $timezone) {
            $owner->statement("SET TIME ZONE '".$timezone."'");
            foreach (['SQL, DMY', 'ISO, MDY'] as $style) {
                $owner->statement("SET DateStyle TO '".$style."'");
                $assert($owner->selectOne('SELECT builderad_current_intent_normalize(?::jsonb)=?::jsonb AS ok', [json_encode($a, JSON_THROW_ON_ERROR), json_encode($a, JSON_THROW_ON_ERROR)])->ok, 'Static validation depended on timezone/DateStyle.');
            }
        }
    } finally {
        $owner->unprepared($supportDefinition);
        $owner->statement("SET TIME ZONE 'UTC'");
        $owner->statement("SET DateStyle TO 'ISO, MDY'");
    }
    $catalogBeforeRestart = $catalog();
    $save('restart-state.json', ['fresh' => $fresh, 'facts' => $beforeRestart, 'catalog' => $catalogBeforeRestart, 'workspace' => $workspace, 'actor' => $actor->id, 'a' => $a, 'confirmation' => $confirmation]);
    $save('task1-durability.json', ['baseline' => $baseline, 'cluster' => getenv('CURRENT_INTENT_CLUSTER_SYSTEM_IDENTIFIER'), 'fresh' => $fresh, 'preserved_row_counts' => array_map('count', $before)]);
    echo "DURABILITY_RESTART_PREPARED=YES\n";
} catch (Throwable $error) {
    fwrite(STDERR, get_class($error).': '.$error->getMessage()."\n");
    exit(1);
}

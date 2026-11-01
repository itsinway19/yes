<?php

// Explicit synthetic operational sessions and source rights, only in the guarded disposable harness.
use App\MarketKnowledge\KnowledgeCapture;
use App\MarketKnowledge\KnowledgePublisher;
use App\MarketKnowledge\MarketEvidence;
use App\Models\User;
use App\Security\DisposableTestDatabaseGuard;
use App\Security\SessionAuthority;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\MarketProcessingExamples;

$mode = $argv[1] ?? 'setup';
if (in_array($mode, ['setup', 'employee'], true)) {
    ob_start();
    require __DIR__.'/AdminUiBrowserFixture.php';
    ob_end_clean();
} else {
    require dirname(__DIR__, 2).'/vendor/autoload.php';
    $app = require dirname(__DIR__, 2).'/bootstrap/app.php';
    $app->make(Kernel::class)->bootstrap();
}
app(DisposableTestDatabaseGuard::class)->assertSafe();
if (config('session.driver') !== 'redis' || (int) config('database.redis.default.port') !== 6397) {
    throw new RuntimeException('Dedicated isolated Redis required.');
}
$db = DB::connection('pgsql_migrations');
$file = getenv('GOALS_SPA_RUN').'/market-knowledge-fixture.json';
$admit = static fn (string $action, string $source, int $revision, array $payload) => json_decode($db->scalar(
    'SELECT builderad_v2_market_admit(?::uuid,?,?::uuid,?,?::jsonb)',
    [(string) Str::uuid7(), $action, $source, $revision, json_encode($payload, JSON_THROW_ON_ERROR)],
), true, flags: JSON_THROW_ON_ERROR);
if ($mode === 'setup') {
    $db->table('platform_maintenance_commands')->delete();
    $db->table('platform_maintenance')->where('id', 1)->update(['active' => false, 'revision' => 0]);
    $sources = [];
    foreach (['current', 'restricted', 'expired'] as $kind) {
        $source = (string) Str::uuid7();
        $sources[$kind] = $source;
        $admit('provision_source', $source, 0, [
            'family' => 'synthetic', 'namespace' => 'synthetic_operations_browser', 'external_id' => $source,
            'label' => 'PRIVATE_SOURCE_LABEL_MUST_NOT_BE_DISCLOSED',
            'policy_id' => 'synthetic.operations.browser', 'policy_version' => 1,
            'evidence_ref' => 'PRIVATE_LICENCE_REF_MUST_NOT_BE_DISCLOSED', 'evidence_digest' => hash('sha256', 'synthetic operations only'),
            'allowed_operations' => ['ingest', 'correct', 'retained_read', 'process', 'display'],
            'allowed_purposes' => ['market_research'], 'allowed_fields' => ['subject', 'geography', 'price', 'currency'],
            'valid_until' => $kind === 'expired' ? now()->addSecond()->toIso8601String() : null,
        ]);
        if ($kind === 'restricted') {
            $admit('restrict_source', $source, 1, ['reason' => 'SYNTHETIC restricted source']);
        }
    }
    file_put_contents($file, json_encode(['sources' => $sources], JSON_THROW_ON_ERROR));
} elseif ($mode === 'employee') {
    $user = $db->table('users')->where('email', 'admin-ui-employee@example.test')->sole();
    $db->table('security_action_grants')->updateOrInsert([
        'subject_user_id' => $user->id, 'subject_generation' => 1, 'action' => 'market.knowledge.observe', 'scope_kind' => 'platform',
    ], [
        'source' => 'founder_bootstrap', 'action' => 'market.knowledge.observe', 'scope_kind' => 'platform',
        'reason' => 'SYNTHETIC explicit operational-source metadata grant; not a default',
        'status' => 'active', 'revoked_at' => null, 'expires_at' => null, 'version' => 1, 'created_at' => now(), 'updated_at' => now(),
    ]);
} elseif ($mode === 'g3-seed') {
    $operator = User::where('email', 'admin-ui-employee@example.test')->sole();
    foreach (['market.knowledge.capture', 'market.knowledge.publish'] as $action) {
        $db->table('security_action_grants')->insert(['subject_user_id' => $operator->id, 'subject_generation' => 1, 'source' => 'founder_bootstrap', 'action' => $action, 'scope_kind' => 'platform',
            'reason' => 'SYNTHETIC explicit browser production-authorization fixture', 'status' => 'active', 'version' => 1, 'created_at' => now(), 'updated_at' => now()]);
    }
    $requestFor = static function ($user, string $method) {
        $store = clone app('session')->driver();
        $store->flush();
        $store->setId(Str::random(40));
        $r = Request::create('/');
        $r->setLaravelSession($store);
        $r->setUserResolver(fn () => $user);
        app(SessionAuthority::class)->issue($r, $user, $method);

        return $r;
    };
    $operatorRequest = $requestFor($operator, 'internal_passkey');
    $writer = User::factory()->create(['email' => 'knowledge-browser-writer@example.test']);
    $writerRequest = $requestFor($writer, 'password');
    $source = (string) Str::uuid7();
    $admit('provision_source', $source, 0, ['family' => 'synthetic', 'namespace' => 'synthetic_knowledge_browser', 'external_id' => $source, 'label' => 'PRIVATE_SOURCE_LABEL_MUST_NOT_BE_DISCLOSED',
        'policy_id' => 'synthetic.knowledge.browser', 'policy_version' => 1, 'evidence_ref' => 'PRIVATE_LICENCE_REF_MUST_NOT_BE_DISCLOSED', 'evidence_digest' => hash('sha256', 'synthetic browser only'),
        'allowed_operations' => ['ingest', 'correct', 'retained_read', 'process', 'display', 'forward', 'retain'], 'allowed_purposes' => ['market_research'],
        'allowed_fields' => ['subject', 'geography', 'currency', 'price', 'inclusions', 'product', 'service', 'metric', 'value', 'comparison_basis', 'finding'], 'valid_until' => null]);
    $selection = static fn (array $rights) => ['realm_kind' => 'direct', 'realm_workspace_public_id' => null, 'requirements' => array_map(fn ($right) => ['requirement_kind' => 'data_use', 'leaf_grant_id' => $right['grant_id']], $rights)];
    $operatorRight = static fn (string $key, string $resource, string $operation, array $fields) => json_decode($db->scalar('SELECT builderad_v2_market_knowledge_enroll(?::uuid,?::uuid,1,?,?::jsonb)', [
        (string) Str::uuid(), $source, $resource, json_encode(['scope_id' => (string) Str::uuid7(), 'subject_ref' => $operator->public_id, 'subject_generation' => 1, 'operation' => $operation, 'purpose' => 'market_research',
            'selection' => ['native_namespaces' => [$resource], 'native_keys' => [$key], 'field_keys' => $fields, 'partition_ref' => null], 'valid_until' => null, 'evidence_ref' => 'test:synthetic-browser-licence', 'evidence_digest' => hash('sha256', 'synthetic')], JSON_THROW_ON_ERROR),
    ]), true, flags: JSON_THROW_ON_ERROR);
    $records = [];
    foreach (range(0, 9) as $number) {
        $key = (string) Str::uuid7();
        $isSignal = $number >= 4 && $number <= 7;
        $fields = $isSignal ? MarketProcessingExamples::signal($number % 2 === 1) : MarketProcessingExamples::offer();
        if ($number === 6) {
            $fields['value'] = 0;
        }
        if ($number === 8) {
            $fields['product']['model'] = null;
        }
        if ($number === 9) {
            $fields['price'] = null;
        }
        $payload = ['record_key' => $key, 'evidence_type' => $isSignal ? 'market.interest_signal:2' : 'market.offer:2', 'fields' => $fields, 'coverage' => 'COMPLETE', 'provenance' => ['synthetic' => true],
            'observed_at' => '2020-01-01T00:00:00Z', 'effective_at' => '2020-01-01T00:00:00Z', 'valid_until' => '2090-01-01T00:00:00Z', 'reason' => 'SYNTHETIC browser evidence; no market claim'];
        $right = $admit('enroll_data_use', $source, 1, ['scope_id' => (string) Str::uuid7(), 'subject_ref' => $writer->public_id, 'subject_generation' => 1, 'operation' => 'ingest', 'purpose' => 'market_research',
            'selection' => ['native_namespaces' => ['market.evidence'], 'native_keys' => [$key], 'field_keys' => array_keys($fields), 'partition_ref' => null], 'valid_until' => null, 'evidence_ref' => 'test:synthetic-intake', 'evidence_digest' => hash('sha256', 'synthetic')]);
        $written = app(MarketEvidence::class)->write($writerRequest, $key, (string) Str::uuid(), $right['scope_id'], 0, json_decode(json_encode($payload, JSON_THROW_ON_ERROR)), $selection([$right]));
        $records[] = ['key' => $key, 'payload' => $payload, 'ref' => $written['material_ref']];
    }
    $make = static function (int|array $record, string $question, bool $publish = true) use ($records, $operatorRight, $selection, $operatorRequest) {
        $entries = array_map(fn ($index) => $records[$index], (array) $record);
        $process = array_map(fn ($entry) => $operatorRight($entry['key'], 'market.evidence', 'process', array_keys($entry['payload']['fields'])), $entries);
        $capture = ['command_id' => (string) Str::uuid(), 'question' => $question, 'material_refs' => array_column($entries, 'ref'), 'purpose' => 'market_research', 'authority_selection' => $selection($process)];
        app(KnowledgeCapture::class)->capture($operatorRequest, $capture);
        if (! $publish) {
            return ['capture' => $capture];
        }
        $forwards = array_map(fn ($entry) => $operatorRight($entry['key'], 'market.evidence', 'forward', array_keys($entry['payload']['fields'])), $entries);
        $retains = array_map(fn ($entry) => $operatorRight($entry['key'], 'market.evidence', 'retain', array_keys($entry['payload']['fields'])), $entries);
        $output = $operatorRight($capture['command_id'], 'market.knowledge', 'display', ['finding']);
        $body = ['command_id' => (string) Str::uuid(), 'run_ref' => $capture['command_id'], 'output_scope_id' => $output['scope_id'], 'process_selection' => $capture['authority_selection'], 'publication_selection' => $selection([...$forwards, ...$retains, $output])];
        $published = app(KnowledgePublisher::class)->publish($operatorRequest, $capture['command_id'], $body);
        $forward = $forwards[0];
        $retain = $retains[0];

        return compact('capture', 'forward', 'retain', 'output', 'published');
    };
    $state = json_decode(file_get_contents($file), true, flags: JSON_THROW_ON_ERROR);
    $state['g3'] = ['source' => $source, 'writer_id' => $writer->id, 'records' => $records,
        'prepared' => $make(0, 'record_geography', false), 'forward_target' => $make(0, 'record_geography'),
        'price_target' => $make(1, 'record_price'), 'retain_target' => $make(2, 'record_price'), 'independent' => $make(3, 'record_geography'),
        'comparison_completed' => $make([4, 5], 'compare_interest'), 'comparison_abstained' => $make([6, 7], 'compare_interest'),
        'unresolved' => $make([8, 0], 'compare_offers'), 'information_required' => $make(9, 'record_price')];
    file_put_contents($file, json_encode($state, JSON_THROW_ON_ERROR));
} elseif ($mode === 'g3-correct-price') {
    $state = json_decode(file_get_contents($file), true, flags: JSON_THROW_ON_ERROR);
    $g3 = $state['g3'];
    $record = $g3['records'][1];
    $writer = User::findOrFail($g3['writer_id']);
    $store = clone app('session')->driver();
    $store->flush();
    $store->setId(Str::random(40));
    $r = Request::create('/');
    $r->setLaravelSession($store);
    $r->setUserResolver(fn () => $writer);
    app(SessionAuthority::class)->issue($r, $writer, 'password');
    $payload = $record['payload'];
    $payload['fields']['price'] = 543.25;
    $payload['reason'] = 'SYNTHETIC actual price correction for browser validity state';
    $right = $admit('enroll_data_use', $g3['source'], 1, ['scope_id' => (string) Str::uuid7(), 'subject_ref' => $writer->public_id, 'subject_generation' => 1, 'operation' => 'correct', 'purpose' => 'market_research',
        'selection' => ['native_namespaces' => ['market.evidence'], 'native_keys' => [$record['key']], 'field_keys' => array_keys($payload['fields']), 'partition_ref' => null],
        'valid_until' => null, 'evidence_ref' => 'test:synthetic-correction', 'evidence_digest' => hash('sha256', 'synthetic')]);
    app(MarketEvidence::class)->write($r, $record['key'], (string) Str::uuid(), $right['scope_id'], 1, json_decode(json_encode($payload, JSON_THROW_ON_ERROR)),
        ['realm_kind' => 'direct', 'realm_workspace_public_id' => null, 'requirements' => [['requirement_kind' => 'data_use', 'leaf_grant_id' => $right['grant_id']]]]);
} elseif (in_array($mode, ['g3-retention-withdraw', 'g3-forward-withdraw'], true)) {
    $state = json_decode(file_get_contents($file), true, flags: JSON_THROW_ON_ERROR);
    $g3 = $state['g3'];
    $right = $mode === 'g3-retention-withdraw' ? $g3['retain_target']['retain'] : $g3['forward_target']['forward'];
    $db->scalar('SELECT builderad_v2_market_knowledge_withdraw(?::uuid,?::uuid,?,1,?)', [(string) Str::uuid(), $right['scope_id'], $right['grant_id'], 'SYNTHETIC actual licensed browser withdrawal']);
} elseif ($mode === 'restrict-current') {
    $state = json_decode(file_get_contents($file), true, flags: JSON_THROW_ON_ERROR);
    $source = $state['sources']['current'];
    $revision = $db->table('market_source_custody')->where('source_id', $source)->value('revision');
    $admit('restrict_source', $source, $revision, ['reason' => 'SYNTHETIC browser source restriction']);
} elseif (in_array($mode, ['revoke-observer', 'expire-observer', 'restore-observer'], true)) {
    $user = $db->table('users')->where('email', 'admin-ui-employee@example.test')->sole();
    $db->table('security_action_grants')->where('subject_user_id', $user->id)->where('action', 'market.knowledge.observe')->update(match ($mode) {
        'revoke-observer' => ['status' => 'revoked', 'revoked_at' => now()],
        'expire-observer' => ['expires_at' => now()->subMinute()],
        default => ['status' => 'active', 'revoked_at' => null, 'expires_at' => null],
    });
} else {
    throw new RuntimeException('Unsupported bounded fixture action.');
}
echo json_encode(['database' => $db->scalar('SELECT current_database()'), 'fixture' => 'synthetic market operations', 'action' => $mode], JSON_THROW_ON_ERROR), PHP_EOL;

<?php

namespace Tests\Feature;

use App\Foundation\Business\BusinessScope;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\DisposableDatabaseReset;
use Tests\Support\InquiryEvidenceFixture;
use Tests\TestCase;

final class InquiryEvidenceUpgradeTest extends TestCase
{
    use InquiryEvidenceFixture;

    public function test_legacy_unknown_definition_readback_and_exact_command_replay_survive_admission_correction(): void
    {
        // Isolated test only: install the previous validator, admit through the real writer,
        // then replace the validator as migration540 does. Never rewrite historical rows.
        $sql = file_get_contents(database_path('sql/inquiry_evidence/validation.sql'));
        $legacy = str_replace("'criterion_version','criterion_definition','cutoff'", "'criterion_version','cutoff'", $sql);
        $start = strpos($legacy, '  -- Abstract rule only:');
        $end = strpos($legacy, "  IF(a->>'cutoff')", $start);
        $legacy = substr($legacy, 0, $start).substr($legacy, $end);
        $install = function (string $body): void {
            $owner = DB::connection('pgsql_migrations');
            $owner->transaction(function () use ($owner, $body) {
                $owner->statement('GRANT CREATE ON SCHEMA public,builderad_private TO builderad_scope_executor');
                $owner->statement('SET LOCAL ROLE builderad_scope_executor');
                $owner->unprepared(str_replace('CREATE FUNCTION ', 'CREATE OR REPLACE FUNCTION ', $body));
                $owner->statement('RESET ROLE');
                $owner->statement('REVOKE CREATE ON SCHEMA public,builderad_private FROM builderad_scope_executor');
                $owner->unprepared(file_get_contents(database_path('sql/market_evidence/catalog_seal.sql')));
            });
        };
        $install($legacy);
        try {
            $f = $this->fixture();
            $record = $this->selection($f['enrolled']['grants']['business.inquiry_evidence.record']);
            $read = $this->selection($f['enrolled']['grants']['business.inquiry_evidence.read']);
            $value = $this->value();
            unset($value['acceptance']['criterion_definition']);
            $command = (string) Str::uuid();
            $first = $f['evidence']->write($f['request'], $f['business'], $command, null, 0, 'record', $value, $record);
        } finally {
            $install($sql);
        }
        $this->assertSame($first, $f['evidence']->write($f['request'], $f['business'], $command, null, 0, 'record', $value, $record));
        $this->assertArrayNotHasKey('criterion_definition', $f['evidence']->read($f['request'], $f['business'], $read)['items'][0]['value']['acceptance']);
        $this->assertArrayNotHasKey('criterion_definition', $f['evidence']->read($f['request'], $f['business'], $read, $first['declaration_id'])['items'][0]['value']['acceptance']);
        $this->denied(fn () => $f['evidence']->write($f['request'], $f['business'], (string) Str::uuid(), null, 0, 'record', $value, $record), '22023');
        $this->assertSame(1, DB::connection('pgsql_migrations')->table('inquiry_evidence_revisions')->count());
    }

    public function test_populated_530_upgrades_without_automatic_authority_and_empty_schema_can_roll_back(): void
    {
        $reset = app(DisposableDatabaseReset::class);
        $reset->clear();
        try {
            $paths = array_values(array_filter(glob(database_path('migrations/*.php')), fn ($p) => strcmp(basename($p), '0001_01_01_000540_create_inquiry_evidence.php') < 0));
            $this->assertSame(0, Artisan::call('migrate', ['--database' => 'pgsql_migrations', '--path' => $paths, '--realpath' => true, '--force' => true]));
            $request = $this->requestFor(User::factory()->create());
            $draft = app(BusinessScope::class)->create($request, (string) Str::uuid(), 'Existing pre540 activity', null);
            $owner = DB::connection('pgsql_migrations');
            $snapshots = [];
            foreach (['businesses', 'authority_qualification_receipts', 'security_action_grants', 'business_command_receipts', 'market_evidence_revisions'] as $table) {
                $snapshots[$table] = $owner->scalar("SELECT coalesce(jsonb_agg(to_jsonb(t) ORDER BY to_jsonb(t)::text),'[]')::text FROM {$table} t");
            }
            $this->assertSame(0, Artisan::call('migrate', ['--database' => 'pgsql_migrations', '--force' => true]));
            foreach ($snapshots as $table => $bytes) {
                $this->assertSame($bytes, $owner->scalar("SELECT coalesce(jsonb_agg(to_jsonb(t) ORDER BY to_jsonb(t)::text),'[]')::text FROM {$table} t"));
            }
            $this->assertSame(0, $owner->table('inquiry_evidence_enrollments')->count());
            $this->assertSame(0, $owner->table('security_action_grants')->where('action', 'like', 'business.inquiry_evidence.%')->count());
            $this->assertSame(0, Artisan::call('migrate', ['--database' => 'pgsql_migrations', '--force' => true]));
            $this->assertFalse($owner->scalar("SELECT has_schema_privilege('builderad_scope_executor','public','CREATE')"));
            $this->assertFalse($owner->scalar("SELECT has_function_privilege('builderad_owner','builderad_private.guard_effect()','EXECUTE')"));
            $migration = require database_path('migrations/0001_01_01_000540_create_inquiry_evidence.php');
            $owner->transaction(fn () => $migration->down());
            $this->assertNull($owner->scalar("SELECT to_regclass('inquiry_evidence_heads')::text"));
            DB::usingConnection('pgsql_migrations', fn () => $owner->transaction(fn () => $migration->up()));
            $this->assertSame(0, $owner->table('inquiry_evidence_enrollments')->count());
            $this->assertSame(7, count($draft['grant_refs']));
        } finally {
            $reset->install();
        }
    }

    public function test_populated_rollback_fails_closed_and_cannot_erase_assertions(): void
    {
        $f = $this->fixture();
        $f['evidence']->write($f['request'], $f['business'], (string) Str::uuid(), null, 0, 'record', $this->value(), $this->selection($f['enrolled']['grants']['business.inquiry_evidence.record']));
        $migration = require database_path('migrations/0001_01_01_000540_create_inquiry_evidence.php');
        try {
            DB::connection('pgsql_migrations')->transaction(fn () => $migration->down());
            $this->fail('Populated evidence was destructively rolled back.');
        } catch (\RuntimeException $error) {
            $this->assertStringContainsString('qualified forward recovery', $error->getMessage());
        }
        $this->assertSame(1, DB::connection('pgsql_migrations')->table('inquiry_evidence_revisions')->count());
    }
}

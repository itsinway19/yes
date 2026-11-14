<?php

namespace Tests\Feature;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\InquiryEvidenceFixture;
use Tests\TestCase;

final class InquiryEvidenceUpgradeTest extends TestCase
{
    use InquiryEvidenceFixture;

    public function test_populated_rollback_fails_closed_and_cannot_erase_assertions(): void
    {
        $f = $this->fixture();
        $f['evidence']->write($f['request'], $f['business'], (string) Str::uuid(), null, 0, 'record', $this->value(), $this->selection($f['enrolled']['grants']['business.inquiry_evidence.record']));
        $migration = require database_path('migrations/0001_01_01_000000_business_native_baseline.php');
        try {
            DB::usingConnection('pgsql_migrations', fn () => DB::connection('pgsql_migrations')->transaction(fn () => $migration->down()));
            $this->fail('Populated evidence was destructively rolled back.');
        } catch (QueryException $error) {
            $this->assertStringContainsString('forward recovery', $error->getMessage());
        }
        $this->assertSame(1, DB::connection('pgsql_migrations')->table('inquiry_evidence_revisions')->count());
    }
}

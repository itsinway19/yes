<?php

namespace Tests\Unit;

use Illuminate\Filesystem\Filesystem;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Support\MigrationInputIntegrity;

final class MigrationInputIntegrityTest extends TestCase
{
    private array $temporary = [];

    private function root(): string
    {
        return dirname(__DIR__, 2);
    }

    private function manifest(): array
    {
        return json_decode(file_get_contents(__DIR__.'/../Fixtures/migration-inputs.lock.json'), true, flags: JSON_THROW_ON_ERROR);
    }

    protected function tearDown(): void
    {
        foreach ($this->temporary as $directory) {
            (new Filesystem)->deleteDirectory($directory);
        }
        parent::tearDown();
    }

    public function test_actual_history_and_each_reader_match_the_independent_git_reference(): void
    {
        $guard = new MigrationInputIntegrity($this->root());
        $this->assertSame([], $guard->verify($this->manifest(), $guard->trustedSha()));
    }

    public function test_forward_migrations_admit_real_literal_and_own_version_sql_forms(): void
    {
        $name = '9999_01_01_000000_future.php';
        $path = 'migration-sql/9999_01_01_000000_future/definition.sql';
        foreach ([
            "DB::statement('SELECT 1');",
            "DB::unprepared(file_get_contents(database_path('".$path."')));",
            "DB::unprepared(<<<'SQL'\nSELECT 1;\nSQL);",
        ] as $body) {
            $source = '<?php use Illuminate\\Database\\Migrations\\Migration; use Illuminate\\Support\\Facades\\DB; return new class extends Migration { public function up(): void {'.$body.'} public function down(): void { DB::statement("SELECT 1"); } };';
            $inspection = MigrationInputIntegrity::inspectForward($name, $source);
            $this->assertSame([], $inspection['violations'], $source);
            $this->assertSame(str_contains($body, 'file_get_contents') ? [$path] : [], $inspection['inputs']);
        }
    }

    public static function unqualifiedReads(): array
    {
        return array_map(static fn ($source) => [$source], [
            '<?php shell_exec("cat shared.sql");',
            '<?php system("cat shared.sql");',
            '<?php exec("cat shared.sql");',
            '<?php passthru("cat shared.sql");',
            '<?php popen("cat shared.sql", "r");',
            '<?php proc_open("cat shared.sql", [], $pipes);',
            '<?php stream_get_contents($stream);',
            '<?php fread($stream, 100);',
            '<?php readgzfile("shared.sql");',
            '<?php curl_exec($handle);',
            '<?php `cat shared.sql`;',
            '<?php ("file_get_contents")("shared.sql");',
            '<?php $f="file_get_contents"; $f("shared.sql");',
            '<?php call_user_func("file_get_contents", "shared.sql");',
            '<?php (new ReflectionFunction("file_get_contents"))->invoke("shared.sql");',
            '<?php use Illuminate\\Support\\Facades\\File as F; F::get("shared.sql");',
            '<?php (new \\Illuminate\\Filesystem\\Filesystem)->get("shared.sql");',
            '<?php app("files")->get("shared.sql");',
            '<?php App\\Migrations\\MutableSql::read();',
            '<?php \\App\\Migrations\\MutableSql::read();',
            '<?php File::get("shared.sql");',
            '<?php \\File::get("shared.sql");',
            '<?php Vendor\\Package\\SqlLoader::read("shared.sql");',
            '<?php include __DIR__."/helper.php";',
            '<?php file_get_contents(database_path($dynamic));',
            '<?php file_get_contents(database_path("sql/expenditure_summary.sql"));',
            '<?php file_get_contents(database_path("php://filter/resource=shared.sql"));',
            '<?php file_get_contents(database_path("migration-sql/9999_01_01_000000_future/../other.sql"));',
            '<?php UnknownFutureReader("shared.sql");',
            '<?php DB::unknownMethod("shared.sql");',
            '<?php DB::{"statement"}("SELECT 1");',
            '<?php (DB::statement(...))("SELECT 1");',
            '<?php (function () { return "file_get_contents"; })()("shared.sql");',
            '<?php getenv("MIGRATION_SQL");',
        ]);
    }

    public static function promotedSqlLiterals(): array
    {
        $source = "<?php DB::unprepared(<<<'SQL'\n-- file_get_contents(database_path('sql/expenditure_summary.sql'))\nSELECT 1;\nSQL);";
        $path = 'migration-sql/9999_01_01_000000_future/definition.sql';

        return ['literal SQL only' => [$source, []],
            'literal SQL plus real PHP reader' => [$source." DB::unprepared(file_get_contents(database_path('".$path."')));", [$path]]];
    }

    #[DataProvider('promotedSqlLiterals')]
    public function test_an_admitted_migration_retains_its_real_readers_when_promoted_to_trusted_history(string $source, array $expected): void
    {
        $inspection = MigrationInputIntegrity::inspectForward('9999_01_01_000000_future.php', $source);
        $this->assertSame([], $inspection['violations']);
        $this->assertSame($expected, $inspection['inputs']);
        $extract = new \ReflectionMethod(MigrationInputIntegrity::class, 'historicalInputs');
        try {
            $actual = $extract->invoke(null, $source);
        } catch (\RuntimeException $error) {
            $this->fail('An admitted SQL migration failed trusted-history promotion: '.$error->getMessage());
        }
        $this->assertSame($expected, $actual);
    }

    #[DataProvider('unqualifiedReads')]
    public function test_unproved_calls_fail_closed_without_enumerating_php_reader_functions(string $source): void
    {
        $this->assertNotEmpty(MigrationInputIntegrity::inspectForward('9999_01_01_000000_future.php', $source)['violations'], $source);
    }

    public static function historicalTampering(): array
    {
        return [['migration and hash'], ['input and hash'], ['baseline'], ['reader relationships']];
    }

    #[DataProvider('historicalTampering')]
    public function test_joint_manifest_edits_cannot_reauthorize_trusted_history(string $case): void
    {
        [$database, $guard, $sha, $lock] = $this->candidate();
        if ($case === 'migration and hash') {
            $name = '0001_01_01_000710_correct_expenditure_preparation_and_selection.php';
            $source = file_get_contents($database.'/migrations/'.$name)."\n// drift\n";
            file_put_contents($database.'/migrations/'.$name, $source);
            $lock['historical'][$name] = MigrationInputIntegrity::digest($source);
        } elseif ($case === 'input and hash') {
            $input = 'sql/expenditure_summary.sql';
            $source = file_get_contents($database.'/'.$input)."\n-- changed historical SQL\n";
            file_put_contents($database.'/'.$input, $source);
            $lock['inputs'][$input] = MigrationInputIntegrity::digest($source);
        } elseif ($case === 'baseline') {
            $lock['baseline'] = str_repeat('0', 40);
        } else {
            $a = '0001_01_01_000640_add_business_native_source_custody.php';
            $b = '0001_01_01_000710_correct_expenditure_preparation_and_selection.php';
            [$lock['readers'][$a], $lock['readers'][$b]] = [$lock['readers'][$b], $lock['readers'][$a]];
        }
        $this->assertNotEmpty($guard->verify($lock, $sha));
    }

    public function test_forward_additions_are_accepted_but_missing_pins_readers_and_backdating_are_rejected(): void
    {
        [$database, $guard, $sha, $lock] = $this->candidate();
        $name = '9999_01_01_000000_future.php';
        $input = 'migration-sql/9999_01_01_000000_future/definition.sql';
        $source = "<?php DB::unprepared(file_get_contents(database_path('".$input."')));";
        mkdir(dirname($database.'/'.$input), 0777, true);
        file_put_contents($database.'/'.$input, 'SELECT 1;');
        file_put_contents($database.'/migrations/'.$name, $source);
        $this->assertNotEmpty($guard->verify($lock, $sha));
        $lock['historical'][$name] = MigrationInputIntegrity::digest($source);
        $lock['readers'][$name] = [$input];
        $this->assertNotEmpty($guard->verify($lock, $sha));
        $lock['inputs'][$input] = MigrationInputIntegrity::digest('SELECT 1;');
        $this->assertSame([], $guard->verify($lock, $sha));
        file_put_contents($database.'/migrations/'.$name, $source."\n// edited after registration");
        $this->assertNotEmpty($guard->verify($lock, $sha));
        $unsafe = '<?php shell_exec("cat shared.sql");';
        file_put_contents($database.'/migrations/'.$name, $unsafe);
        $lock['historical'][$name] = MigrationInputIntegrity::digest($unsafe);
        $this->assertNotEmpty($guard->verify($lock, $sha), 'Registration cannot exempt an unsafe forward migration.');
        file_put_contents($database.'/migrations/'.$name, $source);
        $lock['historical'][$name] = MigrationInputIntegrity::digest($source);
        $withoutReader = $lock;
        $withoutReader['readers'][$name] = [];
        $this->assertNotEmpty($guard->verify($withoutReader, $sha));
        file_put_contents($database.'/'.$input, 'SELECT 2;');
        $this->assertNotEmpty($guard->verify($lock, $sha));
        file_put_contents($database.'/'.$input, 'SELECT 1;');
        file_put_contents($database.'/sql/unconsumed-future.sql', 'SELECT 2;');
        $this->assertSame([], $guard->verify($lock, $sha), 'Unconsumed SQL is not historical input.');
        $backdated = '0001_01_01_000705_backdated.php';
        $inline = "<?php DB::statement('SELECT 1');";
        file_put_contents($database.'/migrations/'.$backdated, $inline);
        $lock['historical'][$backdated] = MigrationInputIntegrity::digest($inline);
        $lock['readers'][$backdated] = [];
        $this->assertNotEmpty($guard->verify($lock, $sha));
    }

    public function test_ci_cannot_fall_back_to_a_candidate_manifest_or_local_reference(): void
    {
        $actions = getenv('GITHUB_ACTIONS');
        $trusted = getenv('BUILDERAD_MIGRATION_TRUSTED_SHA');
        try {
            putenv('GITHUB_ACTIONS=true');
            putenv('BUILDERAD_MIGRATION_TRUSTED_SHA');
            $this->expectException(\RuntimeException::class);
            (new MigrationInputIntegrity($this->root()))->trustedSha();
        } finally {
            $actions === false ? putenv('GITHUB_ACTIONS') : putenv('GITHUB_ACTIONS='.$actions);
            $trusted === false ? putenv('BUILDERAD_MIGRATION_TRUSTED_SHA') : putenv('BUILDERAD_MIGRATION_TRUSTED_SHA='.$trusted);
        }
    }

    private function candidate(): array
    {
        $directory = $this->root().'/storage/framework/testing/migration-integrity-'.bin2hex(random_bytes(8));
        $this->temporary[] = $directory;
        (new Filesystem)->copyDirectory($this->root().'/database', $directory);
        $guard = new MigrationInputIntegrity($this->root(), $directory);
        $sha = $guard->trustedSha();
        $lock = $this->manifest();
        $this->assertSame([], $guard->verify($lock, $sha), 'The unmodified candidate must first qualify against Git.');

        return [$directory, $guard, $sha, $lock];
    }
}

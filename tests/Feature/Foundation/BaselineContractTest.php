<?php

namespace Tests\Feature\Foundation;

use Composer\InstalledVersions;
use Tests\TestCase;

class BaselineContractTest extends TestCase
{
    public function test_repository_runtime_contracts_are_frozen_to_approved_major_lines(): void
    {
        $composer = json_decode(file_get_contents(base_path('composer.json')), true, flags: JSON_THROW_ON_ERROR);
        $package = json_decode(file_get_contents(base_path('package.json')), true, flags: JSON_THROW_ON_ERROR);
        $bootstrap = file_get_contents(database_path('bootstrap/postgresql.sql'));

        $this->assertSame('>=8.5.0 <8.6.0', $composer['require']['php']);
        $this->assertSame('>=24 <25', $package['engines']['node']);
        $this->assertSame('engine-strict=true', trim(file_get_contents(base_path('.npmrc'))));
        $this->assertStringContainsString(
            "current_setting('server_version_num')::integer >= 180000",
            $bootstrap,
        );
        $this->assertStringContainsString(
            "current_setting('server_version_num')::integer < 190000",
            $bootstrap,
        );
        $this->assertStringContainsString('Builderad requires PostgreSQL 18.x.', $bootstrap);
        $this->assertStringNotContainsString('PostgreSQL 18 or newer', $bootstrap);
        $this->assertStringContainsString('SELECT rolsuper AS can_bootstrap', $bootstrap);
        $this->assertStringNotContainsString('rolcreaterole AND rolcreatedb', $bootstrap);
        $this->assertStringContainsString(
            'REVOKE ALL PRIVILEGES ON DATABASE builderad, builderad_test FROM PUBLIC, builderad_app;',
            $bootstrap,
        );
        $this->assertStringContainsString('FROM pg_auth_members', $bootstrap);
    }

    public function test_composer_and_npm_package_metadata_identifies_builderad(): void
    {
        $composer = json_decode(file_get_contents(base_path('composer.json')), true, flags: JSON_THROW_ON_ERROR);
        $package = json_decode(file_get_contents(base_path('package.json')), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame('42huss/builderad', $composer['name']);
        $this->assertSame('Builderad advertising intelligence application.', $composer['description']);
        $this->assertSame(['builderad', 'laravel'], $composer['keywords']);
        $this->assertSame('builderad', $package['name']);
        $this->assertTrue($package['private']);
    }

    public function test_builderad_application_identity_fallbacks_are_consistent(): void
    {
        $sources = [
            config_path('app.php') => "env('APP_NAME', 'Builderad')",
            config_path('cache.php') => "env('APP_NAME', 'Builderad')).'-cache-'",
            config_path('database.php') => "env('APP_NAME', 'Builderad')).'-database-'",
            config_path('session.php') => "env('APP_NAME', 'Builderad')).'-session'",
            config_path('mail.php') => "env('APP_NAME', 'Builderad')",
            config_path('logging.php') => "env('APP_NAME', 'Builderad')",
            resource_path('js/app.ts') => "import.meta.env.VITE_APP_NAME || 'Builderad'",
            resource_path('views/app.blade.php') => "config('app.name', 'Builderad')",
        ];

        foreach ($sources as $path => $expected) {
            $this->assertStringContainsString($expected, file_get_contents($path), $path);
        }
    }

    public function test_environment_example_and_readme_match_the_frozen_runtime(): void
    {
        $environment = file_get_contents(base_path('.env.example'));
        $readme = file_get_contents(base_path('README.md'));

        foreach ([
            'APP_NAME=Builderad',
            'DB_CONNECTION=pgsql',
            'DB_DATABASE=builderad',
            'DB_USERNAME=builderad_app',
            'CACHE_STORE=redis',
            'SESSION_DRIVER=redis',
            'QUEUE_CONNECTION=redis',
            'REDIS_CLIENT=predis',
        ] as $expected) {
            $this->assertStringContainsString($expected, $environment);
        }

        $this->assertStringContainsString('PHP 8.5.x', $readme);
        $this->assertStringContainsString('PostgreSQL 18.x', $readme);
        $this->assertStringContainsString('Node 24.x', $readme);
        $this->assertStringContainsString('PostgreSQL 18.x only', $readme);
    }

    public function test_server_and_client_inertia_adapters_use_major_version_three(): void
    {
        $server = InstalledVersions::getPrettyVersion('inertiajs/inertia-laravel');
        $this->assertNotNull($server);
        $this->assertStringStartsWith('v3.', $server);

        $package = json_decode(file_get_contents(base_path('package-lock.json')), true, flags: JSON_THROW_ON_ERROR);
        $this->assertStringStartsWith('3.', $package['packages']['node_modules/@inertiajs/vue3']['version']);
        $this->assertStringStartsWith('3.', $package['packages']['node_modules/@inertiajs/core']['version']);
    }

    public function test_builderad_configuration_fallbacks_are_postgresql_and_redis(): void
    {
        $database = file_get_contents(config_path('database.php'));
        $cache = file_get_contents(config_path('cache.php'));
        $session = file_get_contents(config_path('session.php'));
        $queue = file_get_contents(config_path('queue.php'));

        $this->assertStringContainsString("env('DB_CONNECTION', 'pgsql')", $database);
        $this->assertStringContainsString("env('CACHE_STORE', 'redis')", $cache);
        $this->assertStringContainsString("env('SESSION_DRIVER', 'redis')", $session);
        $this->assertStringContainsString("env('QUEUE_CONNECTION', 'redis')", $queue);
        $this->assertStringNotContainsString("env('DB_CONNECTION', 'sqlite')", $queue);
    }

    public function test_document_root_direction_follows_the_application_locale(): void
    {
        app()->setLocale('ar');
        $this->get('/login')->assertSee('<html lang="ar" dir="rtl">', false);

        app()->setLocale('en');
        $this->get('/login')->assertSee('<html lang="en" dir="ltr">', false);
    }

    public function test_reproducible_postgresql_bootstrap_artifact_is_present_without_embedded_secrets(): void
    {
        $path = database_path('bootstrap/postgresql.sql');

        $this->assertFileExists($path);
        $artifact = file_get_contents($path);
        $this->assertStringContainsString('builderad_owner', $artifact);
        $this->assertStringContainsString('builderad_app', $artifact);
        $this->assertStringContainsString('builderad_test', $artifact);
        $this->assertDoesNotMatchRegularExpression('/PASSWORD\s+[\x27\x22][^:\x27\x22]+[\x27\x22]/i', $artifact);
    }
}

<?php

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Tests\Support\CoreDependencyBoundary;

class CoreDependencyBoundaryTest extends TestCase
{
    public static function forbiddenReferences(): array
    {
        return [
            ['<?php use App\\Providers\\Ads\\Meta\\MetaOAuthClient;'],
            ['<?php use App\\Providers\\Ads\\Meta\\MetaOAuthClient as Client;'],
            ['<?php new \\App\\Providers\\Ads\\Google\\GoogleAdsClient;'],
            ['<?php use App\\Providers\\Ads\\Meta\\{MetaOAuthClient, MetaReadSettings};'],
            ['<?php use App\\Providers\\Ads as Ads; new Ads\\Meta\\MetaOAuthClient;'],
            ['<?php use app\\providers\\ads\\ProviderAccessStore;'],
            ['<?php use App\\Providers\\{Ads\\Meta\\MetaOAuthClient}; new MetaOAuthClient;'],
            ['<?php use App\\{Providers\\Ads\\Meta\\MetaOAuthClient as Client}; new Client;'],
            ['<?php use App\\Providers as Providers; new Providers\\Ads\\Meta\\MetaOAuthClient;'],
            ['<?php use App as Root; new Root\\Providers\\Ads\\Meta\\MetaOAuthClient;'],
            ['<?php use App\\Providers\\{function Ads\\Meta\\read};'],
        ];
    }

    #[DataProvider('forbiddenReferences')]
    public function test_named_provider_dependency_is_rejected(string $source): void
    {
        $this->assertNotEmpty(CoreDependencyBoundary::violations($source));
    }

    public function test_comments_are_not_dependencies_and_exceptions_are_exact(): void
    {
        $this->assertSame([], CoreDependencyBoundary::violations('<?php /* App\\Providers\\Ads\\Meta\\MetaOAuthClient */ use App\\Models\\Workspace;'));
        $this->assertSame([], CoreDependencyBoundary::violations('<?php use App\\Providers\\{AppServiceProvider};'));
        $this->assertSame([], CoreDependencyBoundary::violations('<?php use App\\Models as Models; new Models\\Workspace;'));
        $this->assertSame([], CoreDependencyBoundary::violations('<?php use App\\Providers\\Ads\\Provider;', ['App\\Providers\\Ads\\Provider']));
        $this->assertNotEmpty(CoreDependencyBoundary::violations('<?php use App\\Providers\\Ads\\ProviderAccessStore;', ['App\\Providers\\Ads\\Provider']));
    }

    public function test_current_neutral_responsibilities_do_not_acquire_provider_implementation_dependencies(): void
    {
        $root = dirname(__DIR__, 2);
        // Existing presentation/catalog and evidence-classification references, not live integration authority.
        $exceptions = [
            'app/Business/BusinessContext.php' => ['App\\Providers\\Ads\\Provider'],
            'app/Business/PromotionProduct.php' => ['App\\Providers\\Ads\\Provider'],
            'app/Sources/EffectiveCapability.php' => ['App\\Providers\\Ads\\ProviderEvidenceValidity'],
        ];
        $violations = [];
        foreach (['app/Business', 'app/Foundation', 'app/Sources', 'app/Commercial', 'app/MarketKnowledge'] as $directory) {
            $this->assertDirectoryExists($root.'/'.$directory);
            foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/'.$directory)) as $file) {
                if (! $file->isFile() || $file->getExtension() !== 'php') {
                    continue;
                }
                $path = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));
                foreach (CoreDependencyBoundary::violations(file_get_contents($file->getPathname()), $exceptions[$path] ?? []) as $reference) {
                    $violations[] = $path.': '.$reference;
                }
            }
        }
        $this->assertSame([], $violations, "INV-001/INV-006 forbidden provider dependency:\n".implode("\n", $violations));
    }
}

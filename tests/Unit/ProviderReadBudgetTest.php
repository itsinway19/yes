<?php

namespace Tests\Unit;

use App\Providers\Ads\Provider;
use App\Providers\Ads\ProviderReadBudget;
use App\Providers\Ads\ProviderScope;
use App\Providers\Ads\ReadBudgetExhausted;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

class ProviderReadBudgetTest extends TestCase
{
    private function budget(Provider $provider): ProviderReadBudget
    {
        return new ProviderReadBudget(new ProviderScope(1, '019a0022-0000-7000-8000-000000000001', $provider,
            'synthetic-account', 'report.read', 'advertising_analysis'), 'synthetic-report', 3, 2, 2, 5,
            new DateTimeImmutable('2026-10-01T09:00:00Z'), 2, 8);
    }

    public function test_every_provider_exhausts_its_own_budget_without_consuming_siblings(): void
    {
        $at = new DateTimeImmutable('2026-10-01T08:00:00Z');
        foreach (Provider::cases() as $failed) {
            $budgets = array_map($this->budget(...), Provider::cases());
            $target = $budgets[array_search($failed, Provider::cases(), true)];
            $target->claimRequest($at);
            $target->claimRequest($at, retry: true);
            $target->claimRequest($at, retry: true);
            try {
                $target->claimRequest($at);
                $this->fail('Unbounded request accepted.');
            } catch (ReadBudgetExhausted $error) {
                $this->assertSame('request_budget_exhausted', $error->reason);
            }
            foreach ($budgets as $sibling) {
                if ($sibling !== $target) {
                    $this->assertSame(0, $sibling->usage()['requests']);
                    $this->assertNotSame($target->key, $sibling->key);
                }
            }
        }
    }

    public function test_retry_hint_is_never_shortened_and_cannot_run_past_deadline(): void
    {
        $budget = $this->budget(Provider::TikTok);
        $at = new DateTimeImmutable('2026-10-01T08:00:00Z');
        $budget->claimRequest($at);
        $this->assertSame('08:00:02', $budget->retryAt($at)->format('H:i:s'));
        $this->assertSame('08:00:30', $budget->retryAt($at, 30)->format('H:i:s'));
        $budget->claimRequest($at, retry: true);
        $this->assertSame('08:00:04', $budget->retryAt($at)->format('H:i:s'));
        try {
            $budget->retryAt($at, 3600);
            $this->fail('Retry beyond the deadline accepted.');
        } catch (ReadBudgetExhausted $error) {
            $this->assertSame('deadline_exhausted', $error->reason);
        }
        $this->assertSame(2, $budget->usage()['requests']);
    }

    public function test_oversized_pages_are_rejected_without_publishing_complete_coverage(): void
    {
        $budget = $this->budget(Provider::Meta);
        $at = new DateTimeImmutable('2026-10-01T08:00:00Z');
        $budget->claimRequest($at);
        $budget->acceptPage(3, $at);
        try {
            $budget->acceptPage(3, $at);
            $this->fail('Oversized page accepted.');
        } catch (ReadBudgetExhausted $error) {
            $this->assertSame('row_budget_exhausted', $error->reason);
        }
        $budget->acceptPage(2, $at);
        $this->assertSame(5, $budget->usage()['rows']);
        try {
            $budget->acceptPage(0, $at);
            $this->fail('Unbounded empty pages accepted.');
        } catch (ReadBudgetExhausted $error) {
            $this->assertSame('page_budget_exhausted', $error->reason);
        }
    }
}

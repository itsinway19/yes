<?php

namespace App\Providers\Ads;

use DateTimeImmutable;
use InvalidArgumentException;

/** One run's local safety limits. These values never assert a provider's official quota. */
final class ProviderReadBudget
{
    private int $requests = 0;

    private int $retries = 0;

    private int $pages = 0;

    private int $rows = 0;

    public readonly string $key;

    public function __construct(
        public readonly ProviderScope $scope,
        string $stream,
        private readonly int $maxRequests,
        private readonly int $maxRetries,
        private readonly int $maxPages,
        private readonly int $maxRows,
        public readonly DateTimeImmutable $deadline,
        private readonly int $baseBackoffSeconds,
        private readonly int $maxBackoffSeconds,
    ) {
        if ($maxRequests < 1 || $maxRetries < 0 || $maxRetries >= $maxRequests
            || $maxPages < 1 || $maxRows < 1 || $baseBackoffSeconds < 1
            || $maxBackoffSeconds < $baseBackoffSeconds || $maxBackoffSeconds > 86400) {
            throw new InvalidArgumentException('Explicit bounded provider run limits are required.');
        }
        $this->key = $scope->key('run:'.$stream);
    }

    /** Call immediately before each provider request, including polling and retries. */
    public function claimRequest(DateTimeImmutable $at, bool $retry = false): void
    {
        $this->requireTime($at);
        if ($this->requests >= $this->maxRequests) {
            throw new ReadBudgetExhausted('request_budget_exhausted');
        }
        if ($retry && ($this->requests === 0 || $this->retries >= $this->maxRetries)) {
            throw new ReadBudgetExhausted('retry_budget_exhausted');
        }
        $this->requests++;
        $this->retries += $retry ? 1 : 0;
    }

    /** An adapter must retain PARTIAL coverage when a page cannot fit. */
    public function acceptPage(int $rows, DateTimeImmutable $at): void
    {
        $this->requireTime($at);
        if ($rows < 0) {
            throw new InvalidArgumentException('Negative report row count.');
        }
        if ($this->pages >= $this->maxPages) {
            throw new ReadBudgetExhausted('page_budget_exhausted');
        }
        if ($rows > $this->maxRows - $this->rows) {
            throw new ReadBudgetExhausted('row_budget_exhausted');
        }
        $this->pages++;
        $this->rows += $rows;
    }

    /** Provider-specific code supplies a verified retry hint; this class never parses arbitrary headers. */
    public function retryAt(DateTimeImmutable $at, ?int $providerDelaySeconds = null): DateTimeImmutable
    {
        $this->requireTime($at);
        if ($providerDelaySeconds !== null && ($providerDelaySeconds < 0 || $providerDelaySeconds > 31536000)) {
            throw new InvalidArgumentException('Invalid provider retry delay.');
        }
        if ($this->requests === 0 || $this->retries >= $this->maxRetries) {
            throw new ReadBudgetExhausted('retry_budget_exhausted');
        }
        if ($this->requests >= $this->maxRequests) {
            throw new ReadBudgetExhausted('request_budget_exhausted');
        }
        $delay = $this->baseBackoffSeconds;
        for ($i = 0; $i < $this->retries && $delay < $this->maxBackoffSeconds; $i++) {
            $delay = min($this->maxBackoffSeconds, $delay * 2);
        }
        // Never clamp the provider's longer delay down to our local backoff ceiling.
        $next = $at->modify('+'.max($delay, $providerDelaySeconds ?? 0).' seconds');
        $this->requireTime($next);

        return $next;
    }

    public function usage(): array
    {
        return ['key' => $this->key, 'requests' => $this->requests, 'retries' => $this->retries,
            'pages' => $this->pages, 'rows' => $this->rows];
    }

    private function requireTime(DateTimeImmutable $at): void
    {
        if ($at >= $this->deadline) {
            throw new ReadBudgetExhausted('deadline_exhausted');
        }
    }
}

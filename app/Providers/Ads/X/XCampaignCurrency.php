<?php

namespace App\Providers\Ads\X;

use InvalidArgumentException;

/** Verified native campaign metadata, not an account-wide currency or accepted report. */
final readonly class XCampaignCurrency
{
    public const BASIS = 'https://docs.x.com/x-ads-api/campaign-management/reference#campaigns';

    private string $binding;

    private array $campaigns;

    public function __construct(XCampaignReport $report, array $body)
    {
        $ids = explode(',', $report->query()['entity_ids']);
        $echo = $body['request']['params']['campaign_ids'] ?? null;
        $rows = $body['data'] ?? null;
        if (($body['request']['params']['account_id'] ?? null) !== $report->scope->accountId
            || ! is_array($echo) || ! array_is_list($echo) || ! is_array($rows) || ! array_is_list($rows)
            || ! array_key_exists('next_cursor', $body) || $body['next_cursor'] !== null || count($rows) !== count($ids) || isset($body['errors'])) {
            throw new InvalidArgumentException('Complete exact X campaign currency metadata required.');
        }
        foreach ($echo as $id) {
            if (! is_string($id)) {
                throw new InvalidArgumentException('Invalid X campaign currency echo.');
            }
        }
        sort($echo, SORT_STRING);
        if ($echo !== $ids) {
            throw new InvalidArgumentException('X currency metadata changed its campaign batch.');
        }
        $campaigns = [];
        $funding = [];
        foreach ($rows as $row) {
            $id = $row['id'] ?? null;
            $instrument = $row['funding_instrument_id'] ?? null;
            $currency = $row['currency'] ?? null;
            if (! is_array($row) || ! is_string($id) || ! in_array($id, $ids, true) || isset($campaigns[$id])
                || ! is_string($instrument) || ! preg_match('/^[a-zA-Z0-9]{1,180}$/D', $instrument)
                || ! is_string($currency) || ! preg_match('/^[A-Z]{3}$/D', $currency)
                || (isset($funding[$instrument]) && $funding[$instrument] !== $currency)) {
                throw new InvalidArgumentException('X campaign currency or funding identity is unverified.');
            }
            $funding[$instrument] = $currency;
            $campaigns[$id] = ['currency' => $currency, 'funding_instrument_id' => $instrument];
        }
        $this->binding = $report->binding();
        $this->campaigns = $campaigns;
    }

    public function forCampaign(XCampaignReport $report, string $id): array
    {
        if ($report->binding() !== $this->binding || ! isset($this->campaigns[$id])) {
            throw new InvalidArgumentException('X currency proof cannot change report scope or campaign.');
        }

        return $this->campaigns[$id];
    }
}

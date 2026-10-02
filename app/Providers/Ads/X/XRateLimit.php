<?php

namespace App\Providers\Ads\X;

use DateTimeImmutable;
use Illuminate\Http\Client\Response;

/** Native header facts only: account headers take precedence even when malformed (no user fallback). */
final class XRateLimit
{
    public static function exhausted(Response $response, DateTimeImmutable $at): ?array
    {
        $native = $response->toPsrResponse();
        $account = false;
        foreach (['limit', 'remaining', 'reset'] as $field) {
            $account = $account || $native->hasHeader('x-account-rate-limit-'.$field);
        }
        $prefix = $account ? 'x-account-rate-limit-' : 'x-rate-limit-';
        $values = [];
        foreach (['limit', 'remaining', 'reset'] as $field) {
            $raw = $native->getHeader($prefix.$field);
            if (count($raw) !== 1 || ! preg_match('/^[0-9]{1,10}$/D', $raw[0])) {
                return null;
            }
            $values[$field] = (int) $raw[0];
        }
        if ($values['limit'] < 1 || $values['remaining'] !== 0 || $values['reset'] <= $at->getTimestamp()
            || $values['reset'] - $at->getTimestamp() > 31536000) {
            return null;
        }

        return ['level' => $account ? 'account' : 'user', 'until' => $values['reset'], 'seconds' => $values['reset'] - $at->getTimestamp()];
    }
}

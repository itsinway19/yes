<?php

namespace App\Security;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class ServiceBusinessContext
{
    public function metadata(string $service, string $credential, string $business, string $purpose): array
    {
        if (! Str::isUuid($service) || ! Str::isUuid($business) || strlen($credential) < 40 || strlen($credential) > 128 || strlen($purpose) > 128) {
            throw new AuthorizationException('Exact service authority required.');
        }

        try {
            return DB::transaction(fn () => json_decode(DB::scalar(
                'SELECT builderad_service_business_metadata(?::uuid,?,?::uuid,?)',
                [$service, $credential, $business, $purpose],
            ), true, flags: JSON_THROW_ON_ERROR));
        } catch (QueryException $error) {
            if ($error->getCode() === '42501') {
                throw new AuthorizationException('Current service authority required.', previous: $error);
            }
            throw $error;
        }
    }
}

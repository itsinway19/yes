<?php

namespace App\Providers\Ads\Meta;

/** Separate documented Login for Business configurations, never inferred from generic OAuth TTL. */
enum MetaTokenKind: string
{
    case User = 'USER';
    case BusinessIntegration = 'BUSINESS_INTEGRATION_SYSTEM_USER';
}

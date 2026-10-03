<?php

namespace App\Business;

use App\Providers\Ads\Provider;

/** Advisory product identity only: not a connection, reporting capability or spending authority. */
enum PromotionProduct: string
{
    case GoogleSearch = 'google.search';

    public function provider(): Provider
    {
        return Provider::Google;
    }

    public function labels(): array
    {
        return ['ar' => 'إعلانات بحث Google', 'en' => 'Google Search ads'];
    }
}

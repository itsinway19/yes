<?php

namespace App\Providers\Ads;

enum Provider: string
{
    case Meta = 'meta';
    case Google = 'google';
    case Snapchat = 'snapchat';
    case TikTok = 'tiktok';
    case X = 'x';
}

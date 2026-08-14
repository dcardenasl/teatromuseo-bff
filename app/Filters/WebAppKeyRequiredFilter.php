<?php

declare(strict_types=1);

namespace App\Filters;

use dcardenasl\Ci4ApiCore\Http\Filters\AbstractWebAppKeyRequiredFilter;

/** Validates the BFF's server-to-server public-read key. */
final class WebAppKeyRequiredFilter extends AbstractWebAppKeyRequiredFilter
{
    protected function webAppKey(): string
    {
        return (string) (env('BFF_API_KEY') ?: env('WEB_API_KEY', ''));
    }
}

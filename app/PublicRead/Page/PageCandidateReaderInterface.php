<?php

declare(strict_types=1);

namespace App\PublicRead\Page;

use dcardenasl\Ci4ApiCore\Support\ApiResult;

/** Optional set-based read seam for resolving several equivalent page paths. */
interface PageCandidateReaderInterface
{
    /**
     * Resolve candidates in the order supplied by the caller.
     *
     * @param list<string> $paths
     * @param list<string> $fields
     */
    public function showAny(string $locale, array $paths, array $fields, bool $preview = false): ApiResult;
}

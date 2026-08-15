<?php

declare(strict_types=1);

namespace App\PublicRead\Page;

use dcardenasl\Ci4ApiCore\Support\ApiResult;

/** Shared public-detail seam used by full-page domain route resolution. */
interface DomainDetailReaderInterface
{
    /** @param list<string> $fields */
    public function show(string $locale, string $idOrSlug, array $fields): ApiResult;
}

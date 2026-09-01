<?php

declare(strict_types=1);

namespace App\PublicRead\Page;

use dcardenasl\Ci4ApiCore\Support\ApiResult;

/** Read seam used by PageResolver and its unit tests. */
interface PageReaderInterface
{
    /** @param list<string> $fields */
    public function show(string $locale, string $path, array $fields, bool $preview = false): ApiResult;

    public function byType(string $locale, string $type): ApiResult;
}

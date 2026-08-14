<?php

declare(strict_types=1);

namespace App\PublicRead\Page;

use dcardenasl\Ci4ApiCore\Support\ApiResult;

/** Entry read seam consumed by the public page resolver. */
interface EntryReaderInterface
{
    /** @param list<string> $fields */
    public function show(string $locale, string $collectionKey, string $slug, array $fields): ApiResult;

    /**
     * @param array<string, mixed> $entry
     * @return list<array<string, mixed>>
     */
    public function related(string $locale, string $collectionKey, array $entry, int $limit = 3): array;
}

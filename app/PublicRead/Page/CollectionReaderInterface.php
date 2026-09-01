<?php

declare(strict_types=1);

namespace App\PublicRead\Page;

/** Supplies the localized public collection prefixes used by page routing. */
interface CollectionReaderInterface
{
    /** @return list<array<string, mixed>> */
    public function list(string $locale): array;
}

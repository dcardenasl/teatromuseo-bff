<?php

declare(strict_types=1);

namespace App\PublicRead\Page;

/** Read seam used by PageResolver and its unit tests. */
interface RedirectReaderInterface
{
    /**
     * @param list<string> $segments
     * @return array<string, mixed>
     */
    public function resolve(array $segments): array;
}

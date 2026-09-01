<?php

declare(strict_types=1);

namespace App\AdminRead\Contracts;

/** Bounded Event lookup projection for the event list screen. */
interface AdminEventListSourceInterface
{
    /**
     * @param list<string> $permissions
     * @return array<string, mixed>
     */
    public function bootstrap(array $permissions): array;
}

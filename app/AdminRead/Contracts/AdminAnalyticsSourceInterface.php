<?php

declare(strict_types=1);

namespace App\AdminRead\Contracts;

interface AdminAnalyticsSourceInterface
{
    /**
     * @param list<string> $permissions
     * @return array<string, mixed>
     */
    public function read(array $permissions, string $period): array;
}

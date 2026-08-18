<?php

declare(strict_types=1);

namespace App\AdminRead\Contracts;

/** Hub-owned Metrics summary + timeseries projection for one period. */
interface AdminMetricsWorkspaceSourceInterface
{
    /** @return array<string, mixed> */
    public function workspace(string $period, string $bearerToken): array;
}

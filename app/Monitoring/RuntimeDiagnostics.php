<?php

declare(strict_types=1);

namespace App\Monitoring;

/**
 * Cheap local-host diagnostics that do not open a database connection or
 * perform a network request. Used by already-authenticated dashboard reads.
 */
final class RuntimeDiagnostics
{
    /** @return array{disk: array<string, mixed>, writable: array<string, mixed>} */
    public function check(): array
    {
        return [
            'disk' => $this->checkDisk(),
            'writable' => $this->checkWritable(),
        ];
    }

    /** @return array<string, mixed> */
    private function checkDisk(): array
    {
        $freeSpace = disk_free_space(WRITEPATH);
        $totalSpace = disk_total_space(WRITEPATH);

        if ($freeSpace === false || $totalSpace === false || $totalSpace <= 0) {
            return [
                'status' => 'unknown',
                'message' => 'Disk capacity is unavailable.',
            ];
        }

        $usedPercentage = round((($totalSpace - $freeSpace) / $totalSpace) * 100, 2);
        $status = match (true) {
            $usedPercentage > 90 => 'critical',
            $usedPercentage > 80 => 'warning',
            default => 'healthy',
        };

        return [
            'status' => $status,
            'free_space_mb' => round($freeSpace / 1024 / 1024, 2),
            'total_space_mb' => round($totalSpace / 1024 / 1024, 2),
            'used_percentage' => $usedPercentage,
        ];
    }

    /** @return array<string, mixed> */
    private function checkWritable(): array
    {
        $folders = [
            WRITEPATH,
            WRITEPATH . 'cache',
            WRITEPATH . 'logs',
            WRITEPATH . 'session',
        ];
        $nonWritable = [];

        foreach ($folders as $folder) {
            if (! is_writable($folder)) {
                $nonWritable[] = $folder;
            }
        }

        return $nonWritable === []
            ? ['status' => 'healthy']
            : ['status' => 'unhealthy', 'non_writable' => $nonWritable];
    }
}

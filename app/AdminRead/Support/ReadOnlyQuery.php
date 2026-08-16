<?php

declare(strict_types=1);

namespace App\AdminRead\Support;

use CodeIgniter\Database\BaseBuilder;
use RuntimeException;

/**
 * Fail-closed helpers for authenticated dashboard projections.
 *
 * Public readers may turn a failed optional query into an empty public result.
 * An administrative projection must not do that: zero is a valid count and
 * must never hide a broken schema or unavailable database.
 */
final class ReadOnlyQuery
{
    /**
     * @return list<array<string, mixed>>
     */
    public static function rows(BaseBuilder $builder, string $label): array
    {
        $result = $builder->get();
        if ($result === false) {
            throw new RuntimeException(sprintf('Admin dashboard query failed for %s.', $label));
        }

        return array_values($result->getResultArray());
    }

    public static function count(BaseBuilder $builder, string $label): int
    {
        $rows = self::rows($builder->select('COUNT(*) AS total', false), $label);
        if (! isset($rows[0]['total'])) {
            throw new RuntimeException(sprintf('Admin dashboard count missing for %s.', $label));
        }

        return (int) $rows[0]['total'];
    }
}

<?php

declare(strict_types=1);

namespace App\AdminRead\Support;

use CodeIgniter\Database\BaseBuilder;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Database\BaseResult;
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

    /**
     * Execute one bounded SQL projection and fail closed on schema/database
     * errors. Table names passed to this helper must come from source-owned
     * constants; values belong in the bindings array.
     *
     * @param BaseConnection<mixed,mixed> $db
     * @param list<mixed> $bindings
     * @return list<array<string, mixed>>
     */
    public static function sql(BaseConnection $db, string $sql, array $bindings, string $label): array
    {
        $result = $db->query($sql, $bindings);
        // `query()` returns `BaseResult|bool|Query`; a SELECT always yields a
        // `BaseResult` here (this class only ever runs read queries) — `bool`
        // means the query failed, and `Query` is CI4's non-executed-query
        // shape, neither of which carries a result set to read.
        if (! $result instanceof BaseResult) {
            throw new RuntimeException(sprintf('Admin dashboard query failed for %s.', $label));
        }

        return array_values($result->getResultArray());
    }

    /**
     * Execute one bounded projection and report the time spent by the
     * database operation, including result materialization. This reuses the
     * query that already feeds the dashboard; it never issues a second probe.
     *
     * @param BaseConnection<mixed,mixed> $db
     * @param list<mixed> $bindings
     * @return array{rows: list<array<string, mixed>>, duration_ms: float}
     */
    public static function timedSql(BaseConnection $db, string $sql, array $bindings, string $label): array
    {
        $startedAt = hrtime(true);
        $rows = self::sql($db, $sql, $bindings, $label);

        return [
            'rows' => $rows,
            'duration_ms' => round((hrtime(true) - $startedAt) / 1_000_000, 2),
        ];
    }
}

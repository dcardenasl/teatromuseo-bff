<?php

declare(strict_types=1);

namespace App\AdminRead\Cms;

use App\AdminRead\Contracts\AdminAnalyticsSourceInterface;
use App\AdminRead\Support\ReadOnlyQuery;
use CodeIgniter\Database\BaseConnection;
use dcardenasl\Ci4ApiCore\Exceptions\AuthorizationException;
use InvalidArgumentException;

/** Complete, bounded CMS analytics projection for the Admin. */
final class CmsAnalyticsSource implements AdminAnalyticsSourceInterface
{
    /** @var list<string> */
    public const ALLOWED_PERIODS = ['1h', '24h', '7d', '30d'];

    private const TOP_LIMIT = 10;

    public function __construct(private readonly BaseConnection $db)
    {
    }

    /**
     * @param list<string> $permissions
     * @return array<string, mixed>
     */
    public function read(array $permissions, string $period): array
    {
        if (! in_array('cms.analytics.read', $permissions, true)) {
            throw new AuthorizationException('The cms.analytics.read permission is required.');
        }
        if (! in_array($period, self::ALLOWED_PERIODS, true)) {
            throw new InvalidArgumentException('Unsupported analytics period.');
        }

        $since = $this->periodSince($period);
        $hourly = in_array($period, ['1h', '24h'], true);
        $bucketExpression = $this->bucketExpression($hourly);
        $rows = ReadOnlyQuery::sql(
            $this->db,
            sprintf(
                <<<'SQL'
                WITH filtered AS (
                    SELECT url, page_title, referrer_domain, device_type,
                           session_id, created_at
                    FROM page_views
                    WHERE created_at >= ?
                ), metrics AS (
                    SELECT 'total' AS metric, COUNT(*) AS metric_value,
                           NULL AS metric_text, NULL AS metric_text_2,
                           NULL AS metric_aux
                    FROM filtered
                    UNION ALL
                    SELECT 'unique' AS metric, COUNT(DISTINCT session_id) AS metric_value,
                           NULL AS metric_text, NULL AS metric_text_2,
                           NULL AS metric_aux
                    FROM filtered
                    WHERE session_id IS NOT NULL
                    UNION ALL
                    SELECT 'page' AS metric, views AS metric_value, url AS metric_text,
                           page_title AS metric_text_2, NULL AS metric_aux
                    FROM (
                        SELECT url, page_title, COUNT(*) AS views
                        FROM filtered
                        GROUP BY url, page_title
                        ORDER BY views DESC, url ASC
                        LIMIT %d
                    ) top_pages
                    UNION ALL
                    SELECT 'referrer' AS metric, views AS metric_value, domain AS metric_text,
                           NULL AS metric_text_2, NULL AS metric_aux
                    FROM (
                        SELECT referrer_domain AS domain, COUNT(*) AS views
                        FROM filtered
                        WHERE referrer_domain IS NOT NULL
                        GROUP BY referrer_domain
                        ORDER BY views DESC, domain ASC
                        LIMIT %d
                    ) top_referrers
                    UNION ALL
                    SELECT 'device' AS metric, total AS metric_value, device AS metric_text,
                           NULL AS metric_text_2, NULL AS metric_aux
                    FROM (
                        SELECT device_type AS device, COUNT(*) AS total
                        FROM filtered
                        GROUP BY device_type
                    ) devices
                    UNION ALL
                    SELECT 'timeseries' AS metric, views AS metric_value, label AS metric_text,
                           NULL AS metric_text_2, unique_visitors AS metric_aux
                    FROM (
                        SELECT %s AS label, COUNT(*) AS views,
                               COUNT(DISTINCT session_id) AS unique_visitors
                        FROM filtered
                        GROUP BY %s
                        ORDER BY label ASC
                    ) timeseries
                )
                SELECT metric, metric_value, metric_text, metric_text_2, metric_aux
                FROM metrics
                SQL,
                self::TOP_LIMIT,
                self::TOP_LIMIT,
                $bucketExpression,
                $bucketExpression,
            ),
            [$since],
            'CMS analytics projection',
        );

        $total = 0;
        $uniqueVisitors = 0;
        $rawPages = [];
        $rawReferrers = [];
        $devices = ['desktop' => 0, 'mobile' => 0, 'tablet' => 0, 'bot' => 0, 'unknown' => 0];
        $timeseries = [];

        foreach ($rows as $row) {
            $metric = (string) ($row['metric'] ?? '');
            $value = (int) ($row['metric_value'] ?? 0);
            $text = isset($row['metric_text']) ? (string) $row['metric_text'] : '';

            switch ($metric) {
                case 'total':
                    $total = $value;
                    break;
                case 'unique':
                    $uniqueVisitors = $value;
                    break;
                case 'page':
                    $rawPages[] = [
                        'url' => $text,
                        'page_title' => isset($row['metric_text_2']) ? (string) $row['metric_text_2'] : null,
                        'views' => $value,
                    ];
                    break;
                case 'referrer':
                    $rawReferrers[] = ['domain' => $text, 'views' => $value];
                    break;
                case 'device':
                    if (array_key_exists($text, $devices)) {
                        $devices[$text] = $value;
                    }
                    break;
                case 'timeseries':
                    $timeseries[] = [
                        'label' => $text,
                        'views' => $value,
                        'unique_visitors' => (int) ($row['metric_aux'] ?? 0),
                    ];
                    break;
            }
        }

        $pages = array_map(
            fn (array $row): array => $row + ['percentage' => $this->percentageOf($row['views'], $total)],
            $rawPages,
        );
        $referrers = array_map(
            fn (array $row): array => $row + ['percentage' => $this->percentageOf($row['views'], $total)],
            $rawReferrers,
        );

        return [
            'overview' => [
                'total_views' => $total,
                'unique_visitors' => $uniqueVisitors,
                'top_page' => $pages[0]['url'] ?? null,
                'top_page_title' => $pages[0]['page_title'] ?? null,
                'top_referrer' => $referrers[0]['domain'] ?? null,
                'period' => $period,
            ],
            'pages' => ['data' => $pages, 'period' => $period],
            'referrers' => ['data' => $referrers, 'period' => $period],
            'devices' => $devices + ['period' => $period],
            'timeseries' => ['data' => $timeseries, 'period' => $period],
        ];
    }

    private function periodSince(string $period): string
    {
        return match ($period) {
            '1h' => date('Y-m-d H:i:s', strtotime('-1 hour')),
            '24h' => date('Y-m-d H:i:s', strtotime('-24 hours')),
            '7d' => date('Y-m-d H:i:s', strtotime('-7 days')),
            '30d' => date('Y-m-d H:i:s', strtotime('-30 days')),
            default => throw new InvalidArgumentException('Unsupported analytics period.'),
        };
    }

    private function bucketExpression(bool $hourly): string
    {
        if (! $hourly) {
            return 'DATE(created_at)';
        }

        return $this->db->DBDriver === 'SQLite3'
            ? "strftime('%Y-%m-%d %H:00', created_at)"
            : "DATE_FORMAT(created_at, '%Y-%m-%d %H:00')";
    }

    private function percentageOf(int $count, int $total): float
    {
        return $total > 0 ? round($count / $total * 100, 1) : 0.0;
    }
}

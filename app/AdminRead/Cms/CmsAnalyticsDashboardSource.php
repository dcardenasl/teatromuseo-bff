<?php

declare(strict_types=1);

namespace App\AdminRead\Cms;

use App\AdminRead\Contracts\AdminDashboardSourceInterface;
use App\AdminRead\Support\ReadOnlyQuery;
use CodeIgniter\Database\BaseConnection;

/** Permission-aware, seven-day CMS analytics projection for the dashboard. */
final class CmsAnalyticsDashboardSource implements AdminDashboardSourceInterface
{
    private const PERIOD = '7d';

    public function __construct(private readonly BaseConnection $db)
    {
    }

    /**
     * @param list<string> $permissions
     * @return array{sections: array<string, mixed>}
     */
    public function read(array $permissions): array
    {
        if (! in_array('cms.analytics.read', $permissions, true)) {
            return ['sections' => ['analytics' => []]];
        }

        $since = date('Y-m-d H:i:s', strtotime('-7 days'));
        $rows = ReadOnlyQuery::sql(
            $this->db,
            <<<'SQL'
            WITH filtered AS (
                SELECT url, page_title, referrer_domain, session_id
                FROM page_views
                WHERE created_at >= ?
            ), metrics AS (
                SELECT 'total_views' AS metric, COUNT(*) AS metric_value,
                       NULL AS metric_text, NULL AS metric_text_2
                FROM filtered
                UNION ALL
                SELECT 'unique_visitors' AS metric, COUNT(DISTINCT session_id) AS metric_value,
                       NULL AS metric_text, NULL AS metric_text_2
                FROM filtered
                WHERE session_id IS NOT NULL
                UNION ALL
                SELECT 'top_page' AS metric, views AS metric_value, url AS metric_text,
                       page_title AS metric_text_2
                FROM (
                    SELECT url, page_title, COUNT(*) AS views
                    FROM filtered
                    GROUP BY url, page_title
                    ORDER BY views DESC, url ASC
                    LIMIT 1
                ) top_page
                UNION ALL
                SELECT 'top_referrer' AS metric, views AS metric_value, domain AS metric_text,
                       NULL AS metric_text_2
                FROM (
                    SELECT referrer_domain AS domain, COUNT(*) AS views
                    FROM filtered
                    WHERE referrer_domain IS NOT NULL
                    GROUP BY referrer_domain
                    ORDER BY views DESC, domain ASC
                    LIMIT 1
                ) top_referrer
            )
            SELECT metric, metric_value, metric_text, metric_text_2
            FROM metrics
            SQL,
            [$since],
            'CMS analytics projection',
        );

        $analytics = [
            'total_views' => 0,
            'unique_visitors' => 0,
            'top_page' => null,
            'top_page_title' => null,
            'top_referrer' => null,
            'period' => self::PERIOD,
        ];
        foreach ($rows as $row) {
            switch ((string) ($row['metric'] ?? '')) {
                case 'total_views':
                    $analytics['total_views'] = (int) ($row['metric_value'] ?? 0);
                    break;
                case 'unique_visitors':
                    $analytics['unique_visitors'] = (int) ($row['metric_value'] ?? 0);
                    break;
                case 'top_page':
                    $analytics['top_page'] = isset($row['metric_text']) ? (string) $row['metric_text'] : null;
                    $analytics['top_page_title'] = isset($row['metric_text_2']) ? (string) $row['metric_text_2'] : null;
                    break;
                case 'top_referrer':
                    $analytics['top_referrer'] = isset($row['metric_text']) ? (string) $row['metric_text'] : null;
                    break;
            }
        }

        return ['sections' => ['analytics' => $analytics]];
    }
}

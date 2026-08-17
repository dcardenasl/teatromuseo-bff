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
        $total = $this->countSince($since);
        $pages = $this->topPagesSince($since, $total);
        $referrers = $this->topReferrersSince($since, $total);

        return [
            'overview' => [
                'total_views' => $total,
                'unique_visitors' => $this->uniqueVisitorsSince($since),
                'top_page' => $pages[0]['url'] ?? null,
                'top_page_title' => $pages[0]['page_title'] ?? null,
                'top_referrer' => $referrers[0]['domain'] ?? null,
                'period' => $period,
            ],
            'pages' => ['data' => $pages, 'period' => $period],
            'referrers' => ['data' => $referrers, 'period' => $period],
            'devices' => array_merge($this->deviceBreakdownSince($since), ['period' => $period]),
            'timeseries' => [
                'data' => $this->timeseriesSince($since, in_array($period, ['1h', '24h'], true)),
                'period' => $period,
            ],
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

    private function countSince(string $since): int
    {
        return ReadOnlyQuery::count(
            $this->db->table('page_views')->where('created_at >=', $since),
            'CMS analytics total views',
        );
    }

    private function uniqueVisitorsSince(string $since): int
    {
        $rows = ReadOnlyQuery::rows(
            $this->db->table('page_views')
                ->select('COUNT(DISTINCT session_id) AS total', false)
                ->where('created_at >=', $since)
                ->where('session_id IS NOT NULL', null, false),
            'CMS analytics unique visitors',
        );

        return (int) ($rows[0]['total'] ?? 0);
    }

    /** @return list<array{url: string, page_title: string|null, views: int, percentage: float}> */
    private function topPagesSince(string $since, int $total): array
    {
        $rows = ReadOnlyQuery::rows(
            $this->db->table('page_views')
                ->select('url, page_title, COUNT(*) AS views', false)
                ->where('created_at >=', $since)
                ->groupBy('url, page_title')
                ->orderBy('views', 'DESC')
                ->limit(self::TOP_LIMIT),
            'CMS analytics top pages',
        );

        return array_map(function (array $row) use ($total): array {
            $views = (int) ($row['views'] ?? 0);

            return [
                'url' => (string) ($row['url'] ?? ''),
                'page_title' => isset($row['page_title']) ? (string) $row['page_title'] : null,
                'views' => $views,
                'percentage' => $this->percentageOf($views, $total),
            ];
        }, $rows);
    }

    /** @return list<array{domain: string, views: int, percentage: float}> */
    private function topReferrersSince(string $since, int $total): array
    {
        $rows = ReadOnlyQuery::rows(
            $this->db->table('page_views')
                ->select('referrer_domain AS domain, COUNT(*) AS views', false)
                ->where('created_at >=', $since)
                ->where('referrer_domain IS NOT NULL', null, false)
                ->groupBy('referrer_domain')
                ->orderBy('views', 'DESC')
                ->limit(self::TOP_LIMIT),
            'CMS analytics top referrers',
        );

        return array_map(function (array $row) use ($total): array {
            $views = (int) ($row['views'] ?? 0);

            return [
                'domain' => (string) ($row['domain'] ?? ''),
                'views' => $views,
                'percentage' => $this->percentageOf($views, $total),
            ];
        }, $rows);
    }

    /** @return array{desktop: int, mobile: int, tablet: int, bot: int, unknown: int} */
    private function deviceBreakdownSince(string $since): array
    {
        $breakdown = ['desktop' => 0, 'mobile' => 0, 'tablet' => 0, 'bot' => 0, 'unknown' => 0];
        $rows = ReadOnlyQuery::rows(
            $this->db->table('page_views')
                ->select('device_type, COUNT(*) AS total', false)
                ->where('created_at >=', $since)
                ->groupBy('device_type'),
            'CMS analytics devices',
        );

        foreach ($rows as $row) {
            $device = (string) ($row['device_type'] ?? '');
            if (array_key_exists($device, $breakdown)) {
                $breakdown[$device] = (int) ($row['total'] ?? 0);
            }
        }

        return $breakdown;
    }

    /** @return list<array{label: string, views: int, unique_visitors: int}> */
    private function timeseriesSince(string $since, bool $hourly): array
    {
        $expression = $hourly
            ? "DATE_FORMAT(created_at, '%Y-%m-%d %H:00')"
            : 'DATE(created_at)';
        $rows = ReadOnlyQuery::rows(
            $this->db->table('page_views')
                ->select($expression . ' AS label, COUNT(*) AS views, COUNT(DISTINCT session_id) AS unique_visitors', false)
                ->where('created_at >=', $since)
                ->groupBy($expression)
                ->orderBy('label', 'ASC'),
            'CMS analytics timeseries',
        );

        return array_map(static fn (array $row): array => [
            'label' => (string) ($row['label'] ?? ''),
            'views' => (int) ($row['views'] ?? 0),
            'unique_visitors' => (int) ($row['unique_visitors'] ?? 0),
        ], $rows);
    }

    private function percentageOf(int $count, int $total): float
    {
        return $total > 0 ? round($count / $total * 100, 1) : 0.0;
    }
}

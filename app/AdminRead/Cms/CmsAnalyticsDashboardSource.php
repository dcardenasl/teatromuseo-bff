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
        $totalViews = $this->countSince($since);
        $topPages = $this->topPagesSince($since);
        $topReferrers = $this->topReferrersSince($since);

        return ['sections' => [
            'analytics' => [
                'total_views' => $totalViews,
                'unique_visitors' => $this->uniqueVisitorsSince($since),
                'top_page' => $topPages[0]['url'] ?? null,
                'top_page_title' => $topPages[0]['page_title'] ?? null,
                'top_referrer' => $topReferrers[0]['domain'] ?? null,
                'period' => self::PERIOD,
            ],
        ]];
    }

    private function countSince(string $since): int
    {
        return ReadOnlyQuery::count(
            $this->db->table('page_views')->where('created_at >=', $since),
            'CMS page views',
        );
    }

    private function uniqueVisitorsSince(string $since): int
    {
        $rows = ReadOnlyQuery::rows(
            $this->db->table('page_views')
                ->select('COUNT(DISTINCT session_id) AS total', false)
                ->where('created_at >=', $since)
                ->where('session_id IS NOT NULL', null, false),
            'CMS unique page-view visitors',
        );

        return (int) ($rows[0]['total'] ?? 0);
    }

    /** @return list<array{url: string, page_title: string|null, views: int}> */
    private function topPagesSince(string $since): array
    {
        $rows = ReadOnlyQuery::rows(
            $this->db->table('page_views')
                ->select('url, page_title, COUNT(*) AS views', false)
                ->where('created_at >=', $since)
                ->groupBy(['url', 'page_title'])
                ->orderBy('views', 'DESC')
                ->limit(1),
            'CMS top pages',
        );

        return array_map(static fn (array $row): array => [
            'url' => (string) ($row['url'] ?? ''),
            'page_title' => isset($row['page_title']) ? (string) $row['page_title'] : null,
            'views' => (int) ($row['views'] ?? 0),
        ], $rows);
    }

    /** @return list<array{domain: string, views: int}> */
    private function topReferrersSince(string $since): array
    {
        $rows = ReadOnlyQuery::rows(
            $this->db->table('page_views')
                ->select('referrer_domain AS domain, COUNT(*) AS views', false)
                ->where('created_at >=', $since)
                ->where('referrer_domain IS NOT NULL', null, false)
                ->groupBy('referrer_domain')
                ->orderBy('views', 'DESC')
                ->limit(1),
            'CMS top referrers',
        );

        return array_map(static fn (array $row): array => [
            'domain' => (string) ($row['domain'] ?? ''),
            'views' => (int) ($row['views'] ?? 0),
        ], $rows);
    }
}

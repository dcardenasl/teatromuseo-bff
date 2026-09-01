<?php

declare(strict_types=1);

namespace Tests\Unit\AdminRead;

use App\AdminRead\Cms\CmsAnalyticsDashboardSource;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Test\CIUnitTestCase;
use Config\Database;

final class CmsAnalyticsDashboardSourceTest extends CIUnitTestCase
{
    private BaseConnection $readDb;

    protected function setUp(): void
    {
        parent::setUp();
        $this->readDb = Database::connect('tests', false);
    }

    protected function tearDown(): void
    {
        $this->readDb->query('DROP TABLE IF EXISTS page_views');
        $this->readDb->close();
        parent::tearDown();
    }

    public function testMissingPermissionDoesNotTouchTheDatabase(): void
    {
        $result = (new CmsAnalyticsDashboardSource($this->readDb))->read([]);

        $this->assertSame(['analytics' => []], $result['sections']);
    }

    public function testReturnsSevenDayOverviewWithExplicitProjection(): void
    {
        $this->readDb->query(
            'CREATE TABLE page_views ('
            . 'id INTEGER PRIMARY KEY AUTOINCREMENT, '
            . 'url TEXT, page_title TEXT, referrer_domain TEXT, '
            . 'device_type TEXT, session_id TEXT, created_at TEXT)'
        );
        $this->readDb->table('page_views')->insertBatch([
            [
                'url' => '/es',
                'page_title' => 'Home',
                'referrer_domain' => 'google.com',
                'device_type' => 'desktop',
                'session_id' => 'visitor-1',
                'created_at' => date('Y-m-d H:i:s'),
            ],
            [
                'url' => '/es',
                'page_title' => 'Home',
                'referrer_domain' => 'google.com',
                'device_type' => 'mobile',
                'session_id' => 'visitor-2',
                'created_at' => date('Y-m-d H:i:s'),
            ],
            [
                'url' => '/old',
                'page_title' => 'Old',
                'referrer_domain' => 'other.example',
                'device_type' => 'desktop',
                'session_id' => 'visitor-old',
                'created_at' => date('Y-m-d H:i:s', strtotime('-8 days')),
            ],
        ]);

        $result = (new CmsAnalyticsDashboardSource($this->readDb))->read(['cms.analytics.read']);

        $this->assertSame([
            'total_views' => 2,
            'unique_visitors' => 2,
            'top_page' => '/es',
            'top_page_title' => 'Home',
            'top_referrer' => 'google.com',
            'period' => '7d',
        ], $result['sections']['analytics']);
    }
}

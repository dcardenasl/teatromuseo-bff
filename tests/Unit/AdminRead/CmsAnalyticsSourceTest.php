<?php

declare(strict_types=1);

namespace Tests\Unit\AdminRead;

use App\AdminRead\Cms\CmsAnalyticsSource;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Test\CIUnitTestCase;
use Config\Database;
use dcardenasl\Ci4ApiCore\Exceptions\AuthorizationException;
use InvalidArgumentException;

final class CmsAnalyticsSourceTest extends CIUnitTestCase
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

    public function testRequiresAnalyticsPermission(): void
    {
        $this->expectException(AuthorizationException::class);

        (new CmsAnalyticsSource($this->readDb))->read([], '7d');
    }

    public function testRejectsUnknownPeriodInsteadOfFallingBack(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new CmsAnalyticsSource($this->readDb))->read(['cms.analytics.read'], '90d');
    }

    public function testReturnsBoundedAnalyticsContractAndZeroFilledDevices(): void
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
                'url' => '/es/contacto',
                'page_title' => 'Contact',
                'referrer_domain' => null,
                'device_type' => 'unknown',
                'session_id' => null,
                'created_at' => date('Y-m-d H:i:s'),
            ],
        ]);

        $result = (new CmsAnalyticsSource($this->readDb))->read(['cms.analytics.read'], '7d');

        $this->assertSame(3, $result['overview']['total_views']);
        $this->assertSame(2, $result['overview']['unique_visitors']);
        $this->assertSame('/es', $result['overview']['top_page']);
        $this->assertSame(66.7, $result['pages']['data'][0]['percentage']);
        $this->assertSame([
            'desktop' => 1,
            'mobile' => 1,
            'tablet' => 0,
            'bot' => 0,
            'unknown' => 1,
            'period' => '7d',
        ], $result['devices']);
        $this->assertSame('7d', $result['timeseries']['period']);
        $this->assertNotEmpty($result['timeseries']['data']);
    }
}

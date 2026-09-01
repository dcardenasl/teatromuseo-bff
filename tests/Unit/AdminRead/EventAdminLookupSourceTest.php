<?php

declare(strict_types=1);

namespace Tests\Unit\AdminRead;

use App\AdminRead\Event\AdminEventLookupSource;
use CodeIgniter\Cache\CacheInterface;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Test\CIUnitTestCase;
use dcardenasl\Ci4ApiCore\Exceptions\AuthorizationException;
use InvalidArgumentException;

final class EventAdminLookupSourceTest extends CIUnitTestCase
{
    public function testRejectsUnknownContextBeforeConsultingCacheOrDatabase(): void
    {
        $cache = $this->createMock(CacheInterface::class);
        $cache->expects($this->never())->method('get');
        $db = $this->createMock(BaseConnection::class);

        $this->expectException(InvalidArgumentException::class);
        (new AdminEventLookupSource($db, $cache))->read('unknown', []);
    }

    public function testRequiresEveryPermissionForTheSelectedContextBeforeReading(): void
    {
        $cache = $this->createMock(CacheInterface::class);
        $cache->expects($this->never())->method('get');
        $db = $this->createMock(BaseConnection::class);

        $this->expectException(AuthorizationException::class);
        (new AdminEventLookupSource($db, $cache))->read('occurrence', ['event.events.read']);
    }

    public function testUsesShortLivedCacheScopedByContextAndPermissionSet(): void
    {
        $cached = ['events' => [], 'venues' => []];
        $cache  = $this->createMock(CacheInterface::class);
        $cache->expects($this->once())
            ->method('get')
            ->with($this->stringStartsWith('admin_event_lookup_occurrence_'))
            ->willReturn($cached);
        $cache->expects($this->never())->method('save');
        $db = $this->createMock(BaseConnection::class);

        $result = (new AdminEventLookupSource($db, $cache))->read('occurrence', [
            'event.venues.read',
            'event.events.read',
        ]);

        $this->assertSame($cached, $result);
    }
}

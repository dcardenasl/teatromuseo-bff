<?php

declare(strict_types=1);

namespace Tests\Unit\AdminRead;

use App\AdminRead\Cms\AdminCmsBootstrapSource;
use App\Libraries\Domain\DomainClient;
use CodeIgniter\Cache\CacheInterface;
use CodeIgniter\Test\CIUnitTestCase;
use dcardenasl\Ci4ApiCore\Exceptions\AuthorizationException;

final class CmsAdminBootstrapSourceTest extends CIUnitTestCase
{
    public function testRequiresTheBaseEntryPermissionsBeforeReading(): void
    {
        $cache = $this->createMock(CacheInterface::class);
        $cache->expects($this->never())->method('get');
        $client = $this->createMock(DomainClient::class);

        $this->expectException(AuthorizationException::class);
        (new AdminCmsBootstrapSource($client, $cache))->entryFormOptions(null, ['cms.entries.read'], 'token');
    }

    public function testComposesEntryOptionsAndScopesTheShortCache(): void
    {
        $permissions = [
            'cms.entries.read',
            'cms.languages.read',
            'cms.collections.read',
            'cms.categories.read',
            'cms.tags.read',
        ];
        $cache = $this->createMock(CacheInterface::class);
        $cache->expects($this->once())
            ->method('get')
            ->with($this->stringStartsWith('admin_cms_bootstrap_entry-form-options_7_'))
            ->willReturn(null);
        $cache->expects($this->once())
            ->method('save')
            ->with($this->stringStartsWith('admin_cms_bootstrap_entry-form-options_7_'), $this->isType('array'), 30);

        $client = $this->getMockBuilder(DomainClient::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['get'])
            ->getMock();
        $client->expects($this->exactly(4))
            ->method('get')
            ->willReturn(['data' => []]);

        $result = (new AdminCmsBootstrapSource($client, $cache))->entryFormOptions(7, $permissions, 'token');

        $this->assertSame(['languages', 'collections', 'categories', 'tags'], array_keys($result));
    }

    public function testReturnsCachedPageOptionsWithoutCallingTheDomain(): void
    {
        $cached = ['languages' => [], 'pages' => [], 'collections' => []];
        $cache = $this->createMock(CacheInterface::class);
        $cache->expects($this->once())
            ->method('get')
            ->with($this->stringStartsWith('admin_cms_bootstrap_page-form-options_base_'))
            ->willReturn($cached);
        $cache->expects($this->never())->method('save');
        $client = $this->createMock(DomainClient::class);
        $client->expects($this->never())->method('get');

        $result = (new AdminCmsBootstrapSource($client, $cache))->pageFormOptions(null, [
            'cms.pages.read',
            'cms.languages.read',
            'cms.collections.read',
        ], 'token');

        $this->assertSame($cached, $result);
    }
}

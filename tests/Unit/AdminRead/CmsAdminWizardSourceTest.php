<?php

declare(strict_types=1);

namespace Tests\Unit\AdminRead;

use App\AdminRead\Cms\AdminCmsWizardSource;
use App\Libraries\Domain\DomainClient;
use CodeIgniter\Cache\CacheInterface;
use CodeIgniter\Test\CIUnitTestCase;

final class CmsAdminWizardSourceTest extends CIUnitTestCase
{
    public function testComposesWizardConfigAndBlockTypesWithScopedCache(): void
    {
        $permissions = ['cms.entries.read'];
        $cache = $this->createMock(CacheInterface::class);
        $cache->expects($this->once())
            ->method('get')
            ->willReturn(null);
        $cache->expects($this->once())
            ->method('save')
            ->with($this->stringStartsWith('admin_cms_wizard_'), $this->isType('array'), 30);
        $client = $this->getMockBuilder(DomainClient::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['get'])
            ->getMock();
        $client->expects($this->once())
            ->method('get')
            ->with('/api/v1/cms/wizard/config', 'token')
            ->willReturn(['data' => [
                'languages' => [],
                'block_types' => [
                    'rich_text' => [
                        'id' => 5,
                        'name' => 'Rich Text',
                        'category' => 'content',
                        'schema_definition' => ['allowed_children' => ['inline_note']],
                    ],
                ],
            ]]);

        $result = (new AdminCmsWizardSource($client, $cache))->bootstrap($permissions, 'token');

        $this->assertSame([], $result['config']['languages']);
        $this->assertSame('rich_text', $result['blockTypes'][0]['block_key']);
        $this->assertSame('content', $result['blockTypes'][0]['category']);
        $this->assertSame(['inline_note'], $result['blockTypes'][0]['schema_definition']['allowed_children']);
    }
}

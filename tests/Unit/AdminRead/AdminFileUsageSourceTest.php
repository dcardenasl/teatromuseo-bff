<?php

declare(strict_types=1);

namespace Tests\Unit\AdminRead;

use App\AdminRead\Files\AdminFileUsageSource;
use App\Libraries\Hub\HubClient;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Test\CIUnitTestCase;
use Config\Database;
use dcardenasl\Ci4ApiCore\Exceptions\AuthorizationException;

final class AdminFileUsageSourceTest extends CIUnitTestCase
{
    private BaseConnection $readDb;

    protected function setUp(): void
    {
        parent::setUp();
        $this->readDb = Database::connect('tests', false);
    }

    protected function tearDown(): void
    {
        foreach (['cms_file_references', 'cms_block_instances', 'cms_content_blocks'] as $table) {
            $this->readDb->query('DROP TABLE IF EXISTS ' . $table);
        }
        $this->readDb->close();
        parent::tearDown();
    }

    public function testMissingHubPermissionFailsBeforeCallingHub(): void
    {
        $hub = $this->createMock(HubClient::class);
        $hub->expects($this->never())->method('get');

        $this->expectException(AuthorizationException::class);
        (new AdminFileUsageSource($hub, $this->readDb))->readHub(7, 'token', []);
    }

    public function testCmsProjectionPreservesBlockContext(): void
    {
        $this->createSchema();
        $this->readDb->table('cms_content_blocks')->insert(['id' => 3, 'block_key' => 'hero', 'name' => 'Hero']);
        $this->readDb->table('cms_block_instances')->insert(['id' => 11, 'owner_type' => 'page', 'owner_id' => 42, 'block_id' => 3]);
        $this->readDb->table('cms_file_references')->insert([
            'hub_file_id' => 7,
            'resource_type' => 'block_instance',
            'resource_id' => 11,
            'block_instance_id' => 11,
            'role' => 'image',
            'label' => 'Hero image',
        ]);
        $hub = $this->createMock(HubClient::class);

        $usages = (new AdminFileUsageSource($hub, $this->readDb))->readCms(7, ['cms.entries.read']);

        $this->assertSame('block_instances', $usages[0]['resource']);
        $this->assertSame('page', $usages[0]['context']['owner_type']);
        $this->assertSame(42, $usages[0]['context']['owner_id']);
        $this->assertSame('hero', $usages[0]['context']['block_key']);
    }

    public function testMergeDeduplicatesAndPrefersCmsContext(): void
    {
        $hub = $this->createMock(HubClient::class);
        $source = new AdminFileUsageSource($hub, $this->readDb);
        $hubUsage = [[
            'source' => 'domain', 'resource' => 'block_instances', 'resource_id' => 11,
            'role' => 'image', 'label' => 'Hub label',
        ]];
        $cmsUsage = [[
            'source' => 'domain', 'resource' => 'block_instances', 'resource_id' => 11,
            'role' => 'image', 'label' => 'CMS label', 'context' => ['owner_id' => 42],
        ]];

        $merged = $source->merge($hubUsage, $cmsUsage);

        $this->assertCount(1, $merged);
        $this->assertSame('CMS label', $merged[0]['label']);
        $this->assertSame(['owner_id' => 42], $merged[0]['context']);
    }

    public function testEmptyRegistryIsACompleteEmptySource(): void
    {
        $this->createSchema();
        $hub = $this->createMock(HubClient::class);

        $this->assertSame([], (new AdminFileUsageSource($hub, $this->readDb))->readCms(99, ['cms.entries.read']));
    }

    private function createSchema(): void
    {
        $this->readDb->query('CREATE TABLE cms_content_blocks (id INTEGER PRIMARY KEY, block_key TEXT, name TEXT)');
        $this->readDb->query('CREATE TABLE cms_block_instances (id INTEGER PRIMARY KEY, owner_type TEXT, owner_id INTEGER, block_id INTEGER)');
        $this->readDb->query('CREATE TABLE cms_file_references (id INTEGER PRIMARY KEY AUTOINCREMENT, hub_file_id INTEGER, resource_type TEXT, resource_id INTEGER, block_instance_id INTEGER, role TEXT, label TEXT)');
    }
}

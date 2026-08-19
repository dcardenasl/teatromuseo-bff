<?php

declare(strict_types=1);

namespace Tests\Unit\AdminRead;

use App\AdminRead\Files\AdminFileUsageSource;
use App\Libraries\Hub\HubClient;
use CodeIgniter\Test\CIUnitTestCase;
use dcardenasl\Ci4ApiCore\Exceptions\AuthorizationException;

final class AdminFileUsageSourceTest extends CIUnitTestCase
{
    public function testMissingHubPermissionFailsBeforeCallingHub(): void
    {
        $hub = $this->createMock(HubClient::class);
        $hub->expects($this->never())->method('get');

        $this->expectException(AuthorizationException::class);
        (new AdminFileUsageSource($hub))->readSnapshot(7, 'token', []);
    }

    public function testSnapshotPreservesContextAndSourceHealth(): void
    {
        $hub = $this->createMock(HubClient::class);
        $hub->expects($this->once())
            ->method('get')
            ->with('/api/v1/files/7/usage-snapshot', 'token')
            ->willReturn([
                'complete' => true,
                'source' => ['hub' => 'ok', 'cms' => 'ok', 'state' => 'ok'],
                'usages' => [[
                    'source' => 'domain',
                    'resource' => 'block_instances',
                    'resource_id' => 11,
                    'role' => 'image',
                    'label' => 'Hero image',
                    'context' => ['owner_type' => 'page', 'owner_id' => 42],
                ]],
            ]);

        $snapshot = (new AdminFileUsageSource($hub))->readSnapshot(7, 'token', ['files.read']);

        $this->assertTrue($snapshot['complete']);
        $this->assertSame('ok', $snapshot['source']['cms']);
        $this->assertSame('block_instances', $snapshot['usages'][0]['resource']);
        $this->assertSame('page', $snapshot['usages'][0]['context']['owner_type']);
        $this->assertSame(42, $snapshot['usages'][0]['context']['owner_id']);
    }

    public function testSnapshotDeduplicatesRowsByStableUsageIdentity(): void
    {
        $hub = $this->createMock(HubClient::class);
        $hub->method('get')->willReturn([
            'complete' => false,
            'source' => ['hub' => 'ok', 'cms' => 'unavailable', 'state' => 'partial'],
            'usages' => [
                ['source' => 'domain', 'resource' => 'block_instances', 'resource_id' => 11, 'role' => 'image', 'label' => 'Hub label'],
                ['source' => 'domain', 'resource' => 'block_instances', 'resource_id' => 11, 'role' => 'image', 'label' => 'CMS label', 'context' => ['owner_id' => 42]],
            ],
        ]);

        $snapshot = (new AdminFileUsageSource($hub))->readSnapshot(7, 'token', ['files.read']);

        $this->assertFalse($snapshot['complete']);
        $this->assertCount(1, $snapshot['usages']);
        $this->assertSame('CMS label', $snapshot['usages'][0]['label']);
        $this->assertSame(['owner_id' => 42], $snapshot['usages'][0]['context']);
    }
}

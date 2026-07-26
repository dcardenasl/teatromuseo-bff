<?php

declare(strict_types=1);

namespace Tests\Feature\Controllers;

use Tests\Support\ApiTestCase;

/**
 * Audit B7.2 (2026-05-06): pin the contract of `GET /api/versions`.
 *
 * @internal
 */
final class ApiVersionsEndpointTest extends ApiTestCase
{
    public function testListsCurrentVersionWithLifecycleMetadata(): void
    {
        $result = $this->get('/api/versions');

        $result->assertStatus(200);
        $payload = json_decode((string) $result->getJSON(), true);

        $this->assertIsArray($payload);
        $this->assertArrayHasKey('current', $payload);
        $this->assertArrayHasKey('versions', $payload);
        $this->assertSame('v1', $payload['current']);

        $this->assertIsArray($payload['versions']);
        $this->assertNotEmpty($payload['versions']);

        $v1 = null;
        foreach ($payload['versions'] as $entry) {
            if (($entry['version'] ?? null) === 'v1') {
                $v1 = $entry;
                break;
            }
        }
        $this->assertIsArray($v1, 'v1 must be present in the listing.');
        $this->assertSame('current', $v1['status']);
        $this->assertArrayHasKey('deprecated_at', $v1);
        $this->assertArrayHasKey('sunset_at', $v1);
        $this->assertArrayHasKey('successor', $v1);
    }

    public function testEndpointDoesNotRequireAuthentication(): void
    {
        // No Authorization header, no session — the version catalog must be public.
        $result = $this->get('/api/versions');

        $result->assertStatus(200);
        $result->assertHeaderContains('Content-Type', 'application/json');
    }
}

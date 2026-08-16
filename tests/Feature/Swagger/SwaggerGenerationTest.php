<?php

declare(strict_types=1);

namespace Tests\Feature\Swagger;

use Tests\Support\ApiTestCase;

/**
 * BFF-109: verify the OpenAPI spec covers every endpoint shipped by the BFF.
 * Running the generator inside the test suite means a missing annotation on a
 * new controller will break CI, not silently ship.
 */
class SwaggerGenerationTest extends ApiTestCase
{
    public function testSpecCoversEveryShippedEndpoint(): void
    {
        $openapi = (new \OpenApi\Generator())->generate([
            APPPATH . 'Config/OpenApi.php',
            APPPATH . 'Controllers/',
            APPPATH . 'Documentation/',
        ]);

        $this->assertNotNull($openapi);

        $json    = json_decode($openapi->toJson(), true);
        $this->assertIsArray($json);
        $this->assertArrayHasKey('paths', $json);

        $paths = $json['paths'];
        foreach (['/ping', '/live', '/ready', '/health', '/api/v1/users/{id}', '/api/v1/me/dashboard', '/api/v1/me/admin-dashboard'] as $expected) {
            $this->assertArrayHasKey($expected, $paths, "Missing OpenAPI path: $expected");
        }

        $this->assertSame('3.0.0', $json['openapi']);
        $this->assertSame('http://localhost:8188', $json['servers'][0]['url']);

        $tags = array_column($json['tags'], 'name');
        $this->assertContains('System', $tags);
        $this->assertContains('Users', $tags);
        $this->assertContains('Me', $tags);
    }

    public function testProxyAndAggregatorRequireBearerAuth(): void
    {
        $openapi = (new \OpenApi\Generator())->generate([
            APPPATH . 'Config/OpenApi.php',
            APPPATH . 'Documentation/',
        ]);

        $json = json_decode($openapi->toJson(), true);

        $this->assertSame(
            [['bearerAuth' => []]],
            $json['paths']['/api/v1/users/{id}']['get']['security'],
        );
        $this->assertSame(
            [['bearerAuth' => []]],
            $json['paths']['/api/v1/me/dashboard']['get']['security'],
        );
        $this->assertSame(
            [['bearerAuth' => []]],
            $json['paths']['/api/v1/me/admin-dashboard']['get']['security'],
        );
    }
}

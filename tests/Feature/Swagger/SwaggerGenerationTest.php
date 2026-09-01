<?php

declare(strict_types=1);

namespace Tests\Feature\Swagger;

use CodeIgniter\Router\DefinedRouteCollector;
use Config\Services;
use Tests\Support\ApiTestCase;

/**
 * BFF-109: verify the OpenAPI spec covers every endpoint shipped by the BFF.
 *
 * `testSpecCoversEveryShippedEndpoint()` derives its expected paths from the
 * real registered route table (`DefinedRouteCollector`), not a hardcoded
 * list — a controller shipped without an `#[OA\Get]` annotation now fails
 * this test instead of silently shipping undocumented. Two categories of
 * registered GET route are excluded, both because they cannot be expressed
 * as one meaningful OpenAPI path by design, not because they're
 * uninteresting:
 *
 *  - Closure-handled routes (`/`, `/api/versions`, `/api/docs` in
 *    non-production) — framework/discovery routes, not domain endpoints.
 *  - `PublicProxyController::forward` — the template-generated wildcard
 *    passthrough (`public-proxy/(.*)`) forwards an arbitrary upstream path
 *    verbatim; it has no fixed request/response contract to document.
 *
 * Route placeholders (`([0-9]+)`, `([^/]+)`, `(.*)`, …) and OpenAPI path
 * parameters (`{id}`, `{locale}`, …) are compared by shape, not literal
 * name, since CI4 route patterns carry no parameter names.
 */
class SwaggerGenerationTest extends ApiTestCase
{
    /** @var list<string> Handler suffixes with no fixed OpenAPI contract by design. */
    private const EXCLUDED_HANDLER_SUFFIXES = [
        'PublicProxyController::forward',
    ];

    public function testSpecCoversEveryShippedEndpoint(): void
    {
        $openapi = (new \OpenApi\Generator())->generate([
            APPPATH . 'Config/OpenApi.php',
            APPPATH . 'Controllers/',
            APPPATH . 'Documentation/',
        ]);

        $this->assertNotNull($openapi);

        $json = json_decode($openapi->toJson(), true);
        $this->assertIsArray($json);
        $this->assertArrayHasKey('paths', $json);

        $documentedTemplates = [];
        foreach (array_keys($json['paths']) as $path) {
            $documentedTemplates[$this->normalizeTemplate((string) $path)] = $path;
        }

        foreach ($this->registeredGetRouteTemplates() as $rawRoute => $template) {
            $this->assertArrayHasKey(
                $template,
                $documentedTemplates,
                "Missing OpenAPI path for registered route '{$rawRoute}' (normalized: {$template}). "
                . 'Add an #[OA\Get(...)] annotation under app/Documentation/**.',
            );
        }

        $this->assertSame('3.0.0', $json['openapi']);
        $this->assertSame('http://localhost:8188', $json['servers'][0]['url']);

        $tags = array_column($json['tags'], 'name');
        $this->assertContains('System', $tags);
        $this->assertContains('Users', $tags);
        $this->assertContains('Me', $tags);
        $this->assertContains('PublicRead', $tags);
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
        $this->assertSame(
            [['bearerAuth' => []]],
            $json['paths']['/api/v1/me/admin-analytics']['get']['security'],
        );
        $this->assertSame(
            [['bearerAuth' => []]],
            $json['paths']['/api/v1/me/admin-files/{fileId}/usages']['get']['security'],
        );
        $this->assertSame(
            [['bearerAuth' => []]],
            $json['paths']['/api/v1/me/admin-event-lookups/{context}']['get']['security'],
        );
    }

    /**
     * Every registered GET route, keyed by its raw CI4 pattern and mapped to
     * its normalized template, excluding closures and routes with no fixed
     * OpenAPI contract by design.
     *
     * @return array<string, string>
     */
    private function registeredGetRouteTemplates(): array
    {
        $collection = Services::routes(true);
        $collection->loadRoutes();
        $collector = new DefinedRouteCollector($collection);

        $templates = [];
        foreach ($collector->collect() as $route) {
            if ($route['method'] !== 'GET') {
                continue;
            }
            if ($route['handler'] === '(Closure)' || str_starts_with($route['handler'], '(View)')) {
                continue;
            }
            if ($this->isExcludedHandler($route['handler'])) {
                continue;
            }

            $templates[$route['route']] = $this->normalizeTemplate($route['route']);
        }

        return $templates;
    }

    private function isExcludedHandler(string $handler): bool
    {
        foreach (self::EXCLUDED_HANDLER_SUFFIXES as $suffix) {
            if (str_ends_with($handler, $suffix) || str_contains($handler, $suffix . '/')) {
                return true;
            }
        }

        return false;
    }

    /**
     * Reduces a CI4 route pattern or an OpenAPI path to a comparable shape:
     * leading slash, every parenthesized CI4 placeholder group or `{name}`
     * OpenAPI parameter collapsed to the same generic token.
     *
     * Placeholders are collapsed *before* splitting on `/`, not after: a
     * compiled CI4 placeholder can itself contain a literal `/` (`(:segment)`
     * compiles to `([^/]+)`), which a naive split-then-match-per-segment
     * approach would mistake for a path separator and cut the group in half.
     */
    private function normalizeTemplate(string $path): string
    {
        $collapsed = preg_replace('/\([^()]*\)/', '{param}', $path);
        $collapsed = preg_replace('/\{[^{}]*\}/', '{param}', (string) $collapsed);

        return '/' . trim((string) $collapsed, '/');
    }
}

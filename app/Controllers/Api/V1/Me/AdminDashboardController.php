<?php

declare(strict_types=1);

namespace App\Controllers\Api\V1\Me;

use App\Controllers\BaseProxyController;
use CodeIgniter\HTTP\ResponseInterface;
use Config\Services;
use dcardenasl\Ci4ApiCore\Exceptions\AuthenticationException;
use dcardenasl\Ci4ApiCore\Http\ApiResponse;
use dcardenasl\Ci4ApiCore\Http\ContextHolder;

/**
 * Real Admin dashboard aggregator: `GET /api/v1/me/admin-dashboard`.
 *
 * Unlike the canonical `/me/dashboard` example, each source is independent:
 * the Hub, CMS, Catalog and Event summaries are allowed to degrade separately
 * so the Admin can keep rendering the healthy sections.
 */
final class AdminDashboardController extends BaseProxyController
{
    public function index(): ResponseInterface
    {
        $context = ContextHolder::get();
        $bearer  = $this->extractBearerToken();

        if ($context?->user_id === null || $bearer === null) {
            throw new AuthenticationException('Missing authenticated user context.');
        }

        $hubClient          = Services::hubDashboardClient();
        $permissions        = $context->permissions;
        $cmsReader          = Services::adminReadCmsDashboard();
        $analyticsReader    = Services::adminReadCmsAnalyticsDashboard();
        $translationsReader = Services::adminReadCmsTranslationsDashboard();
        $catalogReader      = Services::adminReadCatalogDashboard();
        $eventReader        = Services::adminReadEventDashboard();

        $partial = $this->aggregatePartialData([
            'hub' => static fn (): array => $hubClient->get(
                '/api/v1/admin/dashboard/summary',
                $bearer,
            ),
            'cms' => static fn (): array => $cmsReader->read($permissions),
            'analytics' => static fn (): array => $analyticsReader->read($permissions),
            'translations' => static fn (): array => $translationsReader->read($permissions, $bearer),
            'catalog' => static fn (): array => $catalogReader->read($permissions),
            'event' => static fn (): array => $eventReader->read($permissions),
        ]);

        $sections = [];
        $source   = [];
        $diagnostics = [];
        $states   = [];

        foreach ($partial as $key => $result) {
            $payload          = $result['data'];
            $sections[$key]   = is_array($payload['sections'] ?? null) ? $payload['sections'] : [];
            $source[$key]     = $result['state'];
            $latencyMs        = round(max(0.0, (float) ($result['duration_ms'] ?? 0.0)), 2);
            $checkName        = $key === 'hub' ? 'upstream' : 'projection';
            $sourceDiagnostics = is_array($payload['diagnostics'] ?? null)
                ? $payload['diagnostics']
                : [];
            $sourceChecks = is_array($sourceDiagnostics['checks'] ?? null)
                ? $sourceDiagnostics['checks']
                : [];
            $diagnostics[$key] = [
                'latency_ms' => $latencyMs,
                'checks'     => array_merge([
                    $checkName => [
                        'status'           => $result['state'] === 'ok' ? 'healthy' : 'unhealthy',
                        'response_time_ms' => $latencyMs,
                    ],
                ], $sourceChecks),
            ];
            $states[]         = $result['state'];
        }

        $diagnostics['hosting'] = [
            'checks' => Services::runtimeDiagnostics()->check(),
        ];

        $source['state'] = $this->overallState($states);

        return $this->response->setJSON(ApiResponse::success([
            'version'      => 1,
            'generated_at' => date(DATE_ATOM),
            'source'       => $source,
            'diagnostics'  => $diagnostics,
            'sections'     => $sections,
        ]));
    }
}

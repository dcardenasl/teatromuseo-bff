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

        $hubClient     = Services::hubDashboardClient();
        $cmsClient     = Services::domainClient('cms', false);
        $catalogClient = Services::domainClient('catalog', false);
        $eventClient   = Services::domainClient('event', false);

        $partial = $this->aggregatePartialData([
            'hub' => static fn (): array => $hubClient->get(
                '/api/v1/admin/dashboard/summary',
                $bearer,
            ),
            'cms' => static fn (): array => $cmsClient->get(
                '/api/v1/cms/dashboard/summary',
                $bearer,
            ),
            'catalog' => static fn (): array => $catalogClient->get(
                '/api/v1/catalog/dashboard/summary',
                $bearer,
            ),
            'event' => static fn (): array => $eventClient->get(
                '/api/v1/events/dashboard/summary',
                $bearer,
            ),
        ]);

        $sections = [];
        $source   = [];
        $states   = [];

        foreach ($partial as $key => $result) {
            $payload          = $result['data'];
            $sections[$key]   = is_array($payload['sections'] ?? null) ? $payload['sections'] : [];
            $source[$key]     = $result['state'];
            $states[]         = $result['state'];
        }

        $source['state'] = $this->overallState($states);

        return $this->response->setJSON(ApiResponse::success([
            'version'      => 1,
            'generated_at' => date(DATE_ATOM),
            'source'       => $source,
            'sections'     => $sections,
        ]));
    }

    private function extractBearerToken(): ?string
    {
        $header = $this->request->getHeaderLine('Authorization');
        if (preg_match('/^Bearer\s+(.+)$/i', $header, $matches)) {
            return trim($matches[1]);
        }

        return null;
    }

    /** @param list<string> $states */
    private function overallState(array $states): string
    {
        if ($states !== [] && count(array_unique($states)) === 1 && $states[0] === 'ok') {
            return 'ok';
        }

        if (in_array('ok', $states, true)) {
            return 'partial';
        }

        return 'unavailable';
    }
}

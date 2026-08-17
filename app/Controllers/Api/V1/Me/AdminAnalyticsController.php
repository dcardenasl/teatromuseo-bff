<?php

declare(strict_types=1);

namespace App\Controllers\Api\V1\Me;

use App\AdminRead\Cms\CmsAnalyticsSource;
use App\Controllers\BaseProxyController;
use CodeIgniter\HTTP\ResponseInterface;
use Config\Services;
use dcardenasl\Ci4ApiCore\Exceptions\AuthenticationException;
use dcardenasl\Ci4ApiCore\Exceptions\ValidationException;
use dcardenasl\Ci4ApiCore\Http\ApiResponse;
use dcardenasl\Ci4ApiCore\Http\ContextHolder;

/** Complete, bounded CMS analytics projection for the Admin. */
final class AdminAnalyticsController extends BaseProxyController
{
    public function index(): ResponseInterface
    {
        $context = ContextHolder::get();
        $bearer  = $this->extractBearerToken();

        if ($context?->user_id === null || $bearer === null) {
            throw new AuthenticationException('Missing authenticated user context.');
        }

        return $this->handleOperation(function () use ($context): ResponseInterface {
            $period    = $this->requestedPeriod();
            $analytics = Services::adminReadCmsAnalytics()->read($context->permissions, $period);

            return $this->response->setJSON(ApiResponse::success([
                'version'      => 1,
                'generated_at' => date(DATE_ATOM),
                'source'       => [
                    'cms'   => 'ok',
                    'state' => 'ok',
                ],
                'sections' => $analytics,
            ]));
        }, 'CMS analytics source');
    }

    private function requestedPeriod(): string
    {
        $rawPeriod = $this->request->getGet('period');
        $period    = trim(is_string($rawPeriod) ? $rawPeriod : '7d');

        if (! in_array($period, CmsAnalyticsSource::ALLOWED_PERIODS, true)) {
            throw new ValidationException('Unsupported analytics period.', [
                'period' => 'Allowed values: ' . implode(', ', CmsAnalyticsSource::ALLOWED_PERIODS) . '.',
            ]);
        }

        return $period;
    }

    private function extractBearerToken(): ?string
    {
        $header = $this->request->getHeaderLine('Authorization');
        if (preg_match('/^Bearer\s+(.+)$/i', $header, $matches)) {
            return trim($matches[1]);
        }

        return null;
    }
}

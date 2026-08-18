<?php

declare(strict_types=1);

namespace App\Controllers\Api\V1\Me;

use App\Controllers\BaseProxyController;
use App\Libraries\Hub\HubClient;
use CodeIgniter\HTTP\ResponseInterface;
use Config\Services;
use dcardenasl\Ci4ApiCore\Exceptions\AuthenticationException;
use dcardenasl\Ci4ApiCore\Exceptions\ValidationException;
use dcardenasl\Ci4ApiCore\Http\ApiResponse;
use dcardenasl\Ci4ApiCore\Http\ContextHolder;

/** Hub-owned Metrics summary + timeseries workspace for the Admin. */
final class AdminMetricsWorkspaceController extends BaseProxyController
{
    private const ALLOWED_PERIODS = ['1h', '24h', '7d', '30d'];

    public function index(): ResponseInterface
    {
        $period = $this->request->getGet('period');
        $period = is_string($period) && $period !== '' ? $period : '24h';
        if (! in_array($period, self::ALLOWED_PERIODS, true)) {
            throw new ValidationException('Invalid metrics period.', ['period' => 'Must be one of: 1h, 24h, 7d, 30d.']);
        }

        $context = ContextHolder::get();
        $bearer = $this->extractBearerToken();
        if ($context?->user_id === null || $bearer === null) {
            throw new AuthenticationException('Missing authenticated user context.');
        }

        return $this->handleOperation(function () use ($bearer, $period): ResponseInterface {
            /** @var HubClient $hubClient */
            $hubClient = Services::hubDashboardClient();
            $payload = $hubClient->get('/api/v1/admin/metrics/workspace?period=' . rawurlencode($period), $bearer);
            $data = is_array($payload['data'] ?? null) ? $payload['data'] : $payload;
            if (is_array($data['data'] ?? null) && ! array_key_exists('summary', $data)) {
                $data = $data['data'];
            }

            return $this->response->setJSON(ApiResponse::success([
                'version' => 1,
                'period' => $period,
                'summary' => is_array($data['summary'] ?? null) ? $data['summary'] : [],
                'timeseries' => is_array($data['timeseries'] ?? null) ? $data['timeseries'] : [],
            ]));
        }, 'Hub admin metrics workspace source');
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

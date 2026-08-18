<?php

declare(strict_types=1);

namespace App\Controllers\Api\V1\Me;

use App\Controllers\BaseProxyController;
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
            $sections = Services::adminReadMetricsWorkspace()->workspace($period, $bearer);

            return $this->response->setJSON(ApiResponse::success([
                'version' => 1,
                'period' => $period,
                ...$sections,
            ]));
        }, 'Hub admin metrics workspace source');
    }
}

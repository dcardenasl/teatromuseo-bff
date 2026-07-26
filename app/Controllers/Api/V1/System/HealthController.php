<?php

declare(strict_types=1);

namespace App\Controllers\Api\V1\System;

use CodeIgniter\Controller;
use CodeIgniter\HTTP\CURLRequest;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;
use Config\Bff as BffConfig;
use Config\Services;
use dcardenasl\Ci4ApiCore\Monitoring\HealthChecker;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Health Check Controller
 *
 * Infrastructure endpoint — kept thin (no `ApiController` overhead) because it
 * is called every 5–10s by orchestrators (Kubernetes, Docker Swarm).
 *
 * The BFF has no database, so the readiness/health probes ping the upstream
 * hub (`GET {hubUrl}/ping`) with a tight timeout instead of probing a DB. This
 * matches the architectural invariant declared in `CLAUDE.md`.
 */
class HealthController extends Controller
{
    private const HUB_PROBE_TIMEOUT_SECONDS  = 1;
    private const HUB_PROBE_CONNECT_TIMEOUT  = 1;

    private HealthChecker $healthChecker;
    private CURLRequest $http;
    private BffConfig $bff;

    public function initController(RequestInterface $request, ResponseInterface $response, LoggerInterface $logger): void
    {
        parent::initController($request, $response, $logger);
        $this->healthChecker = Services::healthChecker();
        $this->http          = Services::curlrequest();
        $this->bff           = config('Bff');
    }

    /**
     * GET /ping — lightweight availability check (no upstream probe).
     */
    public function ping(): ResponseInterface
    {
        return $this->response->setJSON([
            'status'    => 'ok',
            'timestamp' => date('Y-m-d H:i:s'),
        ])->setStatusCode(200);
    }

    /**
     * GET /live — liveness probe. Returns alive + uptime regardless of upstream state.
     */
    public function live(): ResponseInterface
    {
        return $this->response->setJSON([
            'status'         => 'alive',
            'timestamp'      => date('Y-m-d H:i:s'),
            'uptime_seconds' => (int) (microtime(true) - $_SERVER['REQUEST_TIME_FLOAT']),
        ])->setStatusCode(200);
    }

    /**
     * GET /ready — ready to serve traffic iff the hub is reachable.
     */
    public function ready(): ResponseInterface
    {
        $hubCheck = $this->probeHub();
        $isReady  = $hubCheck['status'] === 'healthy';

        return $this->response->setJSON([
            'status'    => $isReady ? 'ready' : 'not_ready',
            'timestamp' => date('Y-m-d H:i:s'),
            'hub'       => $hubCheck,
        ])->setStatusCode($isReady ? 200 : 503);
    }

    /**
     * GET /health — overall status: hub probe + local disk/writable checks.
     */
    public function index(): ResponseInterface
    {
        $checks = [
            'hub'      => $this->probeHub(),
            'disk'     => $this->healthChecker->checkDiskSpace(),
            'writable' => $this->healthChecker->checkWritableFolders(),
        ];

        $overall    = $this->healthChecker->getOverallStatus($checks);
        $statusCode = $overall === 'unhealthy' ? 503 : 200;

        return $this->response->setJSON([
            'status'    => $overall,
            'timestamp' => date('Y-m-d H:i:s'),
            'checks'    => $checks,
        ])->setStatusCode($statusCode);
    }

    /**
     * Tight ping of the hub's own `/ping` endpoint. Failure (including network
     * errors) is reported as `unhealthy` so probes can route around the BFF.
     *
     * @return array{status: string, response_time_ms?: float, message?: string, hub_url?: string}
     */
    private function probeHub(): array
    {
        $url = rtrim($this->bff->hubUrl, '/');
        if ($url === '') {
            return [
                'status'  => 'unhealthy',
                'message' => 'Config\\Bff::$hubUrl is empty.',
            ];
        }

        $start = microtime(true);

        try {
            $response = $this->http->request('GET', $url . '/ping', [
                'timeout'         => self::HUB_PROBE_TIMEOUT_SECONDS,
                'connect_timeout' => self::HUB_PROBE_CONNECT_TIMEOUT,
                'http_errors'     => false,
                'headers'         => ['Accept' => 'application/json'],
            ]);
        } catch (Throwable $e) {
            return [
                'status'   => 'unhealthy',
                'message'  => 'hub unreachable: ' . $e->getMessage(),
                'hub_url'  => $url,
            ];
        }

        $responseTimeMs = round((microtime(true) - $start) * 1000, 2);
        $status         = $response->getStatusCode();
        $healthy        = $status >= 200 && $status < 400;

        return [
            'status'           => $healthy ? 'healthy' : 'unhealthy',
            'response_time_ms' => $responseTimeMs,
            'hub_url'          => $url,
            'hub_status_code'  => $status,
        ];
    }
}

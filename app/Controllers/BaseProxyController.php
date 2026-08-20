<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Support\RequestTelemetry;
use CodeIgniter\Controller;
use CodeIgniter\HTTP\IncomingRequest;
use CodeIgniter\HTTP\ResponseInterface;
use dcardenasl\Ci4ApiCore\Exceptions\ApiException;
use dcardenasl\Ci4ApiCore\Http\ApiResponse;
use dcardenasl\Ci4ApiCore\Http\Client\AbstractServiceClient;
use dcardenasl\Ci4ApiCore\Support\ExceptionFormatter;
use LogicException;
use Throwable;

/**
 * Base controller for BFF proxy/aggregator endpoints.
 *
 * Subclasses pick the primitive that matches their endpoint's job:
 *
 *  - {@see proxy()} — transparent forward. Upstream status/body/content-type
 *    flow back unchanged. Use for one-to-one passthroughs.
 *  - {@see aggregate()} — combine N upstream calls into a single
 *    `ApiResponse::success([...])` envelope, fail-fast. Use when one client
 *    request fans out to multiple services and a degraded source should
 *    abort the whole response.
 *  - {@see aggregatePartialData()} — combine independent calls while
 *    isolating failures per source (`{state, data}` per key), for a
 *    controller that builds its own envelope shape around the per-source
 *    results. Use when a degraded source must not hide healthy sources.
 *  - {@see handleOperation()} — wrap a single operation (which may validate
 *    input before building its response) in the same sanitized error
 *    contract as the primitives above.
 *
 * Canonical {@see ApiException}s thrown by the underlying client are caught
 * here and rendered via {@see ExceptionFormatter} so the wire shape matches
 * the rest of the platform.
 */
abstract class BaseProxyController extends Controller
{
    /**
     * Headers to copy from the upstream response onto the BFF's response.
     * Subclasses can extend (e.g. `Link`, `ETag`) by overriding.
     *
     * @var list<string>
     */
    protected array $forwardResponseHeaders = ['Content-Type', 'Content-Language'];

    protected function proxy(AbstractServiceClient $client, string $upstreamPath): ResponseInterface
    {
        if (! $this->request instanceof IncomingRequest) {
            throw new LogicException('Proxy endpoints can only be reached via HTTP.');
        }

        try {
            $upstream = $client->forward($this->request, $upstreamPath);

            $contentType = $upstream->getHeaderLine('Content-Type') ?: 'application/json';

            $this->response
                ->setStatusCode($upstream->getStatusCode())
                ->setContentType($contentType)
                ->setBody((string) $upstream->getBody());

            foreach ($this->forwardResponseHeaders as $header) {
                if ($header === 'Content-Type') {
                    continue;
                }
                $value = $upstream->getHeaderLine($header);
                if ($value !== '') {
                    $this->response->setHeader($header, $value);
                }
            }

            return $this->response;
        } catch (ApiException $e) {
            return $this->respondWithException($e);
        }
    }

    /**
     * Run each call (a closure returning an array) sequentially and merge the
     * results under their key into a single success envelope. The first call
     * that throws an {@see ApiException} aborts the rest and is rendered as
     * the response — this matches the fail-fast semantics the kit already
     * uses for hub/domain errors. This primitive is intentionally sequential:
     * adding a second independent upstream call requires an explicit
     * concurrency review instead of assuming that `aggregate()` fans out in
     * parallel.
     *
     * @param array<string, callable(): array<string, mixed>> $calls
     */
    protected function aggregate(array $calls): ResponseInterface
    {
        try {
            $data = [];
            foreach ($calls as $key => $call) {
                $startedAt = hrtime(true);
                try {
                    $data[$key] = $call();
                    RequestTelemetry::recordSource($key, $this->elapsedSince($startedAt), 'ok', 200);
                } catch (ApiException $exception) {
                    RequestTelemetry::recordSource($key, $this->elapsedSince($startedAt), 'unavailable', $exception->getStatusCode());
                    throw $exception;
                } catch (Throwable $exception) {
                    RequestTelemetry::recordSource($key, $this->elapsedSince($startedAt), 'unavailable', 500);
                    throw $exception;
                }
            }

            return $this->response->setJSON(ApiResponse::success($data));
        } catch (ApiException $e) {
            return $this->respondWithException($e);
        } catch (Throwable $exception) {
            log_message('error', sprintf(
                'aggregate() call failed: %s: %s',
                $exception::class,
                $exception->getMessage(),
            ));

            return $this->respondWithException(new \dcardenasl\Ci4ApiCore\Exceptions\ServiceUnavailableException(
                'One or more upstream sources are unavailable.',
            ));
        }
    }

    /**
     * Run each call sequentially and isolate its result under the source key.
     *
     * Each successful source is returned as `state: ok` with its decoded array
     * under `data`; a source whose closure throws is returned as
     * `state: unavailable` with an empty `data` array. This primitive is
     * intentionally separate from {@see aggregate()}: that helper is the
     * fail-fast contract used by existing consumers such as `/me/dashboard`
     * and `UsersProxyController`, while this helper is for independent data
     * sources where partial degradation is the desired response. Every
     * current caller (`AdminDashboardController`, `AdminFileUsagesController`)
     * builds its own `source`/`complete` envelope around this data rather
     * than returning it as-is, so there is no plain wrapper here — build the
     * envelope in the controller.
     *
     * Calls remain sequential by design. Introducing concurrency would be a
     * separate change requiring an explicit review of ordering and failure
     * semantics.
     *
     * @param array<string, callable(): array<array-key, mixed>> $calls
     * @return array<string, array{state: 'ok'|'unavailable', data: array<string, mixed>, duration_ms: float}>
     */
    protected function aggregatePartialData(array $calls): array
    {
        $data = [];

        foreach ($calls as $key => $call) {
            $startedAt = hrtime(true);
            try {
                $data[$key] = [
                    'state'       => 'ok',
                    'data'        => $call(),
                    'duration_ms' => $this->elapsedSince($startedAt),
                ];
                RequestTelemetry::recordSource($key, $this->elapsedSince($startedAt), 'ok', 200);
            } catch (Throwable $exception) {
                $status = $exception instanceof ApiException ? $exception->getStatusCode() : 500;
                RequestTelemetry::recordSource($key, $this->elapsedSince($startedAt), 'unavailable', $status);
                log_message('error', sprintf(
                    'Partial aggregate source "%s" unavailable: %s: %s',
                    $key,
                    $exception::class,
                    $exception->getMessage(),
                ));

                $data[$key] = [
                    'state'       => 'unavailable',
                    'data'        => [],
                    'duration_ms' => $this->elapsedSince($startedAt),
                ];
            }
        }

        return $data;
    }

    protected function respondWithException(ApiException $e): ResponseInterface
    {
        $result = ExceptionFormatter::format($e);

        return $this->response
            ->setStatusCode($result->status)
            ->setJSON($result->body);
    }

    /**
     * Execute a controller operation with the same sanitized error contract
     * as proxy/aggregate. Keeping this boundary here preserves the thin
     * controller convention for dedicated projections that need to validate
     * input before building their response envelope.
     *
     * @param callable(): ResponseInterface $operation
     */
    protected function handleOperation(callable $operation, string $source): ResponseInterface
    {
        $startedAt = hrtime(true);
        try {
            $response = $operation();
            $status = $response->getStatusCode();
            RequestTelemetry::recordSource(
                $source,
                $this->elapsedSince($startedAt),
                $status >= 400 ? 'unavailable' : 'ok',
                $status,
            );

            return $response;
        } catch (ApiException $exception) {
            RequestTelemetry::recordSource($source, $this->elapsedSince($startedAt), 'unavailable', $exception->getStatusCode());
            return $this->respondWithException($exception);
        } catch (Throwable $exception) {
            RequestTelemetry::recordSource($source, $this->elapsedSince($startedAt), 'unavailable', 503);
            log_message('error', sprintf(
                '%s unavailable: %s: %s',
                $source,
                $exception::class,
                $exception->getMessage(),
            ));

            return $this->respondWithException(new \dcardenasl\Ci4ApiCore\Exceptions\ServiceUnavailableException(
                $source . ' unavailable.',
            ));
        }
    }

    /**
     * Reduces a list of per-source states (`'ok'`/`'unavailable'`) from an
     * `aggregatePartialData()` call into one overall state for the response
     * envelope's top-level `source.state`: `ok` when every source succeeded,
     * `partial` when at least one did, `unavailable` when none did.
     *
     * @param list<string> $states
     */
    protected function overallState(array $states): string
    {
        if ($states !== [] && count(array_unique($states)) === 1 && $states[0] === 'ok') {
            return 'ok';
        }

        if (in_array('ok', $states, true)) {
            return 'partial';
        }

        return 'unavailable';
    }

    /**
     * Extracts the bearer token from the incoming `Authorization` header.
     * Controllers that need the raw token to forward to an authenticated
     * upstream call (rather than only the `ContextHolder` identity/permission
     * projection populated by the auth filters) use this.
     */
    protected function extractBearerToken(): ?string
    {
        $header = $this->request->getHeaderLine('Authorization');
        if (preg_match('/^Bearer\s+(.+)$/i', $header, $matches)) {
            return trim($matches[1]);
        }

        return null;
    }

    private function elapsedSince(int $startedAt): float
    {
        return (hrtime(true) - $startedAt) / 1_000_000;
    }
}

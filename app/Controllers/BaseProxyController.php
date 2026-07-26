<?php

declare(strict_types=1);

namespace App\Controllers;

use CodeIgniter\Controller;
use CodeIgniter\HTTP\IncomingRequest;
use CodeIgniter\HTTP\ResponseInterface;
use dcardenasl\Ci4ApiCore\Exceptions\ApiException;
use dcardenasl\Ci4ApiCore\Http\ApiResponse;
use dcardenasl\Ci4ApiCore\Http\Client\AbstractServiceClient;
use dcardenasl\Ci4ApiCore\Support\ExceptionFormatter;
use LogicException;

/**
 * Base controller for BFF proxy/aggregator endpoints.
 *
 * Subclasses pick one of two primitives:
 *
 *  - {@see proxy()} — transparent forward. Upstream status/body/content-type
 *    flow back unchanged. Use for one-to-one passthroughs.
 *  - {@see aggregate()} — combine N upstream calls into a single
 *    `ApiResponse::success([...])` envelope. Use when one client request
 *    fans out to multiple services.
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
     * uses for hub/domain errors.
     *
     * @param array<string, callable(): array<string, mixed>> $calls
     */
    protected function aggregate(array $calls): ResponseInterface
    {
        try {
            $data = [];
            foreach ($calls as $key => $call) {
                $data[$key] = $call();
            }

            return $this->response->setJSON(ApiResponse::success($data));
        } catch (ApiException $e) {
            return $this->respondWithException($e);
        }
    }

    private function respondWithException(ApiException $e): ResponseInterface
    {
        $result = ExceptionFormatter::format($e);

        return $this->response
            ->setStatusCode($result->status)
            ->setJSON($result->body);
    }
}

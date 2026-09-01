<?php

declare(strict_types=1);

namespace App\Filters;

use App\Support\PublicReadCallerContext;
use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;
use Config\Services;

/**
 * Validates `X-App-Key` against the set of trusted server-side callers of
 * the public-read seam (teatromuseo-web, teatromuseo-totem-ci4) and records
 * which one matched in {@see PublicReadCallerContext}.
 *
 * Each trusted caller has its own key so access can be audited/rotated
 * independently; a request is authorized if it matches ANY configured key.
 * Fails closed (403) if no caller key is configured at all — an unconfigured
 * gate is a misconfiguration, not "no gate".
 */
final class WebAppKeyRequiredFilter implements FilterInterface
{
    /**
     * @param list<string>|null $arguments
     */
    public function before(RequestInterface $request, $arguments = null): ?ResponseInterface
    {
        $trustedCallers = $this->trustedCallers();

        if ($trustedCallers === []) {
            return $this->deny(403, 'Web app key is not configured.');
        }

        $incomingKey = (string) $request->getHeaderLine('X-App-Key');

        if ($incomingKey !== '') {
            foreach ($trustedCallers as $caller => $configuredKey) {
                if (hash_equals($configuredKey, $incomingKey)) {
                    PublicReadCallerContext::set($caller);

                    return null;
                }
            }
        }

        return $this->deny(401, 'Unauthorized');
    }

    /**
     * @param list<string>|null $arguments
     */
    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null): ?ResponseInterface
    {
        PublicReadCallerContext::flush();

        return null;
    }

    /**
     * @return array<string, string> caller name => configured key, unconfigured callers omitted
     */
    private function trustedCallers(): array
    {
        $callers = [
            'web'   => (string) (env('BFF_API_KEY') ?: env('WEB_API_KEY', '')),
            'totem' => (string) env('TOTEM_BFF_API_KEY', ''),
        ];

        return array_filter($callers, static fn (string $key): bool => $key !== '');
    }

    private function deny(int $status, string $message): ResponseInterface
    {
        return Services::response()
            ->setStatusCode($status)
            ->setJSON([
                'status'   => 'error',
                'messages' => [$message],
            ]);
    }
}

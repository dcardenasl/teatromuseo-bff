<?php

declare(strict_types=1);

namespace App\Libraries\Domain;

use CodeIgniter\HTTP\CURLRequest;
use CodeIgniter\HTTP\IncomingRequest;
use dcardenasl\Ci4ApiCore\Http\Client\AbstractServiceClient;

/**
 * Generic HTTP client for upstream domain apps (e.g., ci4-domain-starter apps).
 *
 * Inherits request routing, automatic linear retries on 5xx or network errors,
 * X-Request-Id distributed tracing propagation, and status code-to-exception mapping
 * from the core's {@see AbstractServiceClient}.
 */
class DomainClient extends AbstractServiceClient
{
    public function __construct(
        CURLRequest $http,
        string $baseUrl,
        int $timeoutSeconds = 5
    ) {
        parent::__construct(
            http: $http,
            baseUrl: $baseUrl,
            timeoutSeconds: $timeoutSeconds
        );
    }

    /**
     * Fetch a structured JSON resource from a domain with the visitor's bearer.
     *
     * This is deliberately separate from {@see forward()}: aggregators need
     * the decoded payload and canonical exceptions, while proxy endpoints need
     * to preserve the upstream response unchanged.
     *
     * @return array<string, mixed>
     */
    public function get(string $path, string $bearerToken): array
    {
        return $this->request('GET', $path, [
            'headers' => [
                'Authorization' => 'Bearer ' . $bearerToken,
            ],
        ]);
    }

    /**
     * Widens the core allow-list with the headers webhook providers use to
     * sign their payloads (e.g. SendGrid Signed Event Webhooks) plus the
     * generic shared-token header, so upstream domains can authenticate
     * proxied webhooks end-to-end.
     *
     * @return array<string, string>
     */
    protected function buildForwardedHeaders(IncomingRequest $incoming): array
    {
        $headers = parent::buildForwardedHeaders($incoming);

        $extra = [
            'X-Twilio-Email-Event-Webhook-Signature',
            'X-Twilio-Email-Event-Webhook-Timestamp',
            'X-Webhook-Token',
        ];

        foreach ($extra as $name) {
            $value = $incoming->getHeaderLine($name);
            if ($value !== '') {
                $headers[$name] = $value;
            }
        }

        return $headers;
    }
}

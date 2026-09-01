<?php

declare(strict_types=1);

namespace App\AdminRead\Hub;

use App\AdminRead\Contracts\AdminMetricsWorkspaceSourceInterface;
use App\Libraries\Hub\HubClient;

/**
 * Thin transport + normalization layer over the Hub's own Metrics workspace
 * endpoint. Metrics is Hub-owned: this source does not decide authorization
 * (the Hub's endpoint does, via the forwarded bearer token) and does not
 * read any database — it only shapes the response envelope.
 */
final class AdminMetricsWorkspaceSource implements AdminMetricsWorkspaceSourceInterface
{
    public function __construct(private readonly HubClient $client)
    {
    }

    /** @return array<string, mixed> */
    public function workspace(string $period, string $bearerToken): array
    {
        $data = $this->unwrap(
            $this->client->get('/api/v1/admin/metrics/workspace?period=' . rawurlencode($period), $bearerToken),
            'summary',
        );

        return [
            'summary' => is_array($data['summary'] ?? null) ? $data['summary'] : [],
            'timeseries' => is_array($data['timeseries'] ?? null) ? $data['timeseries'] : [],
        ];
    }

    /**
     * Hub responses may arrive double-wrapped (`{data: {data: {...}}}`)
     * depending on the calling client's envelope handling; unwrap once more
     * only when the outer `data` doesn't already carry the expected key.
     *
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function unwrap(array $payload, string $expectedKey): array
    {
        $data = is_array($payload['data'] ?? null) ? $payload['data'] : $payload;
        if (is_array($data['data'] ?? null) && ! array_key_exists($expectedKey, $data)) {
            $data = $data['data'];
        }

        return $data;
    }
}

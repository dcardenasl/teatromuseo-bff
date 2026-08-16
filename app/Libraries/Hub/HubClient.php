<?php

declare(strict_types=1);

namespace App\Libraries\Hub;

use dcardenasl\Ci4ApiCore\Http\Client\HubClient as CoreHubClient;

/**
 * BFF-specific extension of the shared Hub client.
 *
 * The core client keeps its structured request primitive protected and exposes
 * endpoint-specific methods such as getUser(). The BFF also needs one
 * authenticated, decoded GET for Hub-owned aggregate projections, so this
 * small extension exposes that same primitive without changing the shared
 * package or the transparent proxy contract.
 */
class HubClient extends CoreHubClient
{
    /**
     * Fetch a structured JSON resource from the Hub with the visitor's bearer.
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
}

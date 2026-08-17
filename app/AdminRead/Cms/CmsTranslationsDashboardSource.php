<?php

declare(strict_types=1);

namespace App\AdminRead\Cms;

use App\AdminRead\Contracts\AdminDashboardTranslationsSourceInterface;
use App\Libraries\Domain\DomainClient;

/** Adapts the CMS-owned translation audit without duplicating its algorithm. */
final class CmsTranslationsDashboardSource implements AdminDashboardTranslationsSourceInterface
{
    public function __construct(private readonly DomainClient $client)
    {
    }

    /**
     * @param list<string> $permissions
     * @return array{sections: array<string, mixed>}
     */
    public function read(array $permissions, string $bearerToken): array
    {
        if (! in_array('cms.languages.read', $permissions, true)) {
            return ['sections' => ['translations' => []]];
        }

        $payload = $this->client->get('/cms/translations/audit/stats', $bearerToken);
        $stats = is_array($payload['data'] ?? null) ? $payload['data'] : [];

        return ['sections' => ['translations' => array_values(array_filter(
            $stats,
            static fn (mixed $row): bool => is_array($row),
        ))]];
    }
}

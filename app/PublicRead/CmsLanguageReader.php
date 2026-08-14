<?php

declare(strict_types=1);

namespace App\PublicRead;

use CodeIgniter\Database\BaseConnection;

/** Read-only active locale projection used by the Web bootstrap. */
final class CmsLanguageReader
{
    /** @param BaseConnection<mixed, mixed> $db */
    public function __construct(private readonly BaseConnection $db)
    {
    }

    /** @return list<array<string, mixed>> */
    public function list(): array
    {
        $query = $this->db->table('cms_languages')
            ->select('code, name, native_name, is_default')
            ->where('is_active', 1)
            ->orderBy('sort_order', 'ASC')
            ->orderBy('id', 'ASC')
            ->get();

        return $query === false ? [] : array_values(array_map(
            static fn (array $row): array => [
                'code' => strtolower(trim((string) ($row['code'] ?? ''))),
                'name' => (string) ($row['name'] ?? $row['code'] ?? ''),
                'native_name' => (string) ($row['native_name'] ?? $row['name'] ?? $row['code'] ?? ''),
                'is_default' => (bool) ($row['is_default'] ?? false),
            ],
            $query->getResultArray(),
        ));
    }
}

<?php

declare(strict_types=1);

namespace App\PublicRead\Cms;

use CodeIgniter\Database\BaseConnection;

/** Resolves requested/default active CMS locales without model access. */
final class PublicLocaleResolver
{
    /** @param BaseConnection<mixed, mixed> $db */
    public function __construct(private readonly BaseConnection $db, private readonly string $fallback = 'es')
    {
    }

    /** @return array{by_code: array<string, array<string, mixed>>, by_id: array<int, string>, default: string} */
    public function all(): array
    {
        $query = $this->db->table('cms_languages')
            ->select('id, code, is_default, is_active')
            ->where('is_active', 1)
            ->orderBy('id', 'ASC')
            ->get();
        $rows = $query !== false ? $query->getResultArray() : [];
        $byCode = [];
        $byId = [];
        $default = strtolower($this->fallback);
        foreach ($rows as $row) {
            $code = strtolower(trim((string) ($row['code'] ?? '')));
            if ($code === '') {
                continue;
            }
            $id = (int) ($row['id'] ?? 0);
            $byCode[$code] = $row;
            $byId[$id] = $code;
            if ((int) ($row['is_default'] ?? 0) === 1) {
                $default = $code;
            }
        }

        return ['by_code' => $byCode, 'by_id' => $byId, 'default' => $default];
    }

    /** @return array{requested: int|null, default: int|null, codes: array<int, string>} */
    public function ids(string $locale): array
    {
        $all = $this->all();
        $requested = isset($all['by_code'][$locale]) ? (int) $all['by_code'][$locale]['id'] : null;
        $default = isset($all['by_code'][$all['default']]) ? (int) $all['by_code'][$all['default']]['id'] : null;
        $codes = array_values(array_map(static fn (array $row): string => strtolower((string) $row['code']), $all['by_code']));

        return ['requested' => $requested, 'default' => $default, 'codes' => $codes];
    }
}

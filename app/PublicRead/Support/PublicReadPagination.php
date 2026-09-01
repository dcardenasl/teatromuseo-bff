<?php

declare(strict_types=1);

namespace App\PublicRead\Support;

use CodeIgniter\Database\BaseBuilder;

/** Applies the shared page/per-page window used by public-read list queries. */
final class PublicReadPagination
{
    public static function apply(BaseBuilder $builder, int $page, int $perPage): void
    {
        $builder->limit($perPage, self::offset($page, $perPage));
    }

    public static function offset(int $page, int $perPage): int
    {
        return ($page - 1) * $perPage;
    }
}

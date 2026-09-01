<?php

declare(strict_types=1);

namespace App\AdminRead\Contracts;

/** Single CMS category projection for Admin list and editor screens. */
interface AdminCmsCategorySourceInterface
{
    /**
     * @param list<string> $permissions
     * @return array<string, mixed>
     */
    public function bootstrap(?int $categoryId, array $permissions): array;
}

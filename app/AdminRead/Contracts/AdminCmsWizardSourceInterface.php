<?php

declare(strict_types=1);

namespace App\AdminRead\Contracts;

/** Permission-aware composite read for the CMS structure wizard. */
interface AdminCmsWizardSourceInterface
{
    /**
     * @param list<string> $permissions
     * @return array<string, mixed>
     */
    public function bootstrap(array $permissions, string $bearerToken): array;
}

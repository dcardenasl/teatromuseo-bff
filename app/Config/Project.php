<?php

declare(strict_types=1);

namespace Config;

use CodeIgniter\Config\BaseConfig;

/**
 * Project metadata (single source of truth).
 */
class Project extends BaseConfig
{
    public const NAME        = 'CodeIgniter 4 BFF Starter';
    public const DESCRIPTION = 'Stateless Backend-for-Frontend over a ci4-api-starter hub and one or more ci4-domain-starter apps.';
    public const VERSION     = '1.2.1';

    public string $name        = self::NAME;
    public string $description = self::DESCRIPTION;
    public string $version     = self::VERSION;
}

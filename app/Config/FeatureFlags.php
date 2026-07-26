<?php

declare(strict_types=1);

namespace Config;

use CodeIgniter\Config\BaseConfig;

class FeatureFlags extends BaseConfig
{
    public bool $monitoringEnabled = true;
    public bool $metricsEnabled = true;

    public function __construct()
    {
        parent::__construct();

        $this->monitoringEnabled = filter_var($this->envValue('MONITORING_ENABLED', true), FILTER_VALIDATE_BOOLEAN);
        $this->metricsEnabled = filter_var($this->envValue('METRICS_ENABLED', true), FILTER_VALIDATE_BOOLEAN);
    }

    public function isEnabled(string $flag): bool
    {
        return match ($flag) {
            'monitoring' => $this->monitoringEnabled,
            'metrics' => $this->metricsEnabled,
            default => true,
        };
    }

    /**
     * Prefer getenv() (mutable via putenv) over env() which checks $_ENV/$_SERVER first.
     */
    private function envValue(string $key, $default = null)
    {
        $value = getenv($key);
        if ($value !== false) {
            return $value;
        }

        return env($key, $default);
    }
}

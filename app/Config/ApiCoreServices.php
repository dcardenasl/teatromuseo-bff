<?php

declare(strict_types=1);

namespace Config;

/**
 * API Core Services
 *
 * `requestDtoFactory()` is the only member of this trait the BFF actually
 * uses (every `PublicRead/**` request DTO goes through it). The sibling
 * factories the shared `ci4-api-core` template ships alongside it —
 * `auditService`, `requestAuditContextFactory`, `requestDataCollector`,
 * `responseDtoFactory`, `queueManager` — assume an app with its own audit
 * trail, request-body collection, response DTOs and job queue. The BFF is
 * stateless (no owned database, no writes, no queue) and none of those had
 * a real caller, so they were removed here rather than kept as dead surface
 * on `Services`. If a future BFF feature genuinely needs one, re-add it from
 * `vendor/dcardenasl/ci4-api-core`'s own service definitions rather than
 * resurrecting this file's git history.
 */
trait ApiCoreServices
{
    public static function requestDtoFactory(bool $getShared = true): \dcardenasl\Ci4ApiCore\Support\RequestDtoFactory
    {
        if ($getShared) {
            return static::getSharedInstance('requestDtoFactory');
        }

        return new \dcardenasl\Ci4ApiCore\Support\RequestDtoFactory();
    }
}

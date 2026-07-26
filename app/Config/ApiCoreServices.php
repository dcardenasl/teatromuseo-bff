<?php

declare(strict_types=1);

namespace Config;

/**
 * API Core Services
 *
 * Centralizes essential infrastructure services for the API lifecycle,
 * including request orchestration, DTO factories, and queue management.
 */
trait ApiCoreServices
{
    public static function auditService(bool $getShared = true): \dcardenasl\Ci4ApiCore\Services\AuditServiceInterface
    {
        if ($getShared) {
            return static::getSharedInstance('auditService');
        }

        // The BFF is stateless and does not persist an audit table.
        return new \dcardenasl\Ci4ApiCore\Services\Audit\NullAuditService();
    }

    public static function requestAuditContextFactory(bool $getShared = true): \dcardenasl\Ci4ApiCore\Support\RequestAuditContextFactory
    {
        if ($getShared) {
            return static::getSharedInstance('requestAuditContextFactory');
        }

        return new \dcardenasl\Ci4ApiCore\Support\RequestAuditContextFactory();
    }

    public static function requestDataCollector(bool $getShared = true): \dcardenasl\Ci4ApiCore\Support\RequestDataCollector
    {
        if ($getShared) {
            return static::getSharedInstance('requestDataCollector');
        }

        return new \dcardenasl\Ci4ApiCore\Support\RequestDataCollector();
    }

    public static function requestDtoFactory(bool $getShared = true): \dcardenasl\Ci4ApiCore\Support\RequestDtoFactory
    {
        if ($getShared) {
            return static::getSharedInstance('requestDtoFactory');
        }

        return new \dcardenasl\Ci4ApiCore\Support\RequestDtoFactory();
    }

    public static function responseDtoFactory(bool $getShared = true): \dcardenasl\Ci4ApiCore\Support\ResponseDtoFactory
    {
        if ($getShared) {
            return static::getSharedInstance('responseDtoFactory');
        }

        return new \dcardenasl\Ci4ApiCore\Support\ResponseDtoFactory();
    }

    public static function queueManager(bool $getShared = true): \dcardenasl\Ci4ApiCore\Queue\QueueManager
    {
        if ($getShared) {
            return static::getSharedInstance('queueManager');
        }

        return new \dcardenasl\Ci4ApiCore\Queue\QueueManager();
    }
}

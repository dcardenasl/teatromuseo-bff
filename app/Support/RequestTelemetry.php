<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Per-request telemetry for the BFF composition seam.
 *
 * The holder is deliberately limited to bounded metadata. It never accepts
 * request bodies, bearer tokens, cache keys, or upstream payloads.
 */
final class RequestTelemetry
{
    private const MAX_SOURCE_EVENTS = 64;

    private static ?string $requestId = null;
    private static ?float $startedAt = null;
    private static int $queryCount = 0;

    /** @var list<array{source:string,duration_ms:float,state:string,status:int|null}> */
    private static array $sources = [];

    /** @var array{hit:int,miss:int,bypass:int,stale:int} */
    private static array $cacheStates = [
        'hit' => 0,
        'miss' => 0,
        'bypass' => 0,
        'stale' => 0,
    ];

    public static function begin(string $requestId): void
    {
        self::$requestId = $requestId !== '' ? $requestId : null;
        self::$startedAt = microtime(true);
        self::$sources = [];
        self::$queryCount = 0;
        self::$cacheStates = [
            'hit' => 0,
            'miss' => 0,
            'bypass' => 0,
            'stale' => 0,
        ];
    }

    public static function reset(): void
    {
        self::$requestId = null;
        self::$startedAt = null;
        self::$sources = [];
        self::$queryCount = 0;
        self::$cacheStates = [
            'hit' => 0,
            'miss' => 0,
            'bypass' => 0,
            'stale' => 0,
        ];
    }

    public static function requestId(): ?string
    {
        return self::$requestId;
    }

    public static function elapsedMilliseconds(): float
    {
        return self::$startedAt === null ? 0.0 : (microtime(true) - self::$startedAt) * 1000;
    }

    public static function recordSource(
        string $source,
        float $durationMilliseconds,
        string $state,
        ?int $status = null,
    ): void {
        if (self::$startedAt === null || count(self::$sources) >= self::MAX_SOURCE_EVENTS) {
            return;
        }

        self::$sources[] = [
            'source' => self::safeLabel($source),
            'duration_ms' => round(max(0.0, $durationMilliseconds), 2),
            'state' => self::safeLabel($state),
            'status' => $status,
        ];
    }

    public static function recordQuery(): void
    {
        if (self::$startedAt !== null) {
            self::$queryCount++;
        }
    }

    public static function queryCount(): int
    {
        return self::$queryCount;
    }

    /** @param 'hit'|'miss'|'bypass'|'stale' $state */
    public static function recordCache(string $seam, string $state): void
    {
        if (self::$startedAt === null || $seam === '' || ! array_key_exists($state, self::$cacheStates)) {
            return;
        }

        self::$cacheStates[$state]++;
    }

    /**
     * @return array{
     *     count:int,
     *     duration_ms:float,
     *     states:array{ok:int,unavailable:int},
     *     events:list<array{source:string,duration_ms:float,state:string,status:int|null}>
     * }
     */
    public static function sourceSummary(): array
    {
        $duration = 0.0;
        $states = ['ok' => 0, 'unavailable' => 0];

        foreach (self::$sources as $source) {
            $duration += $source['duration_ms'];
            if (isset($states[$source['state']])) {
                $states[$source['state']]++;
            }
        }

        return [
            'count' => count(self::$sources),
            'duration_ms' => round($duration, 2),
            'states' => $states,
            'events' => self::$sources,
        ];
    }

    /** @return array{hit:int,miss:int,bypass:int,stale:int} */
    public static function cacheSummary(): array
    {
        return self::$cacheStates;
    }

    private static function safeLabel(string $value): string
    {
        $label = preg_replace('/[^A-Za-z0-9_.:-]/', '_', $value);

        return is_string($label) ? substr($label, 0, 120) : 'unknown';
    }
}

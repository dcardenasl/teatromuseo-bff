<?php

declare(strict_types=1);

namespace App\PublicRead\Page;

/**
 * Read-only copy of the public URL aliases owned by the Web application.
 *
 * The BFF needs these values to resolve a page in the same way as the legacy
 * Web resolver. They are deliberately pure configuration: no database or
 * HTTP dependency belongs in this class.
 */
final class PublicPagePaths
{
    /** @var array<string, string> */
    private const HOMEPAGE_ALIASES = [
        'home' => 'home',
        'inicio' => 'home',
        'accueil' => 'home',
    ];

    /** @var array<string, string> */
    private const ROUTE_ALIASES = [
        'cartelera' => 'events',
        'events' => 'events',
        'programme' => 'events',
        'eventos' => 'events',
        'programming' => 'events',
        'programmation' => 'events',
        'programacao' => 'events',
        'museo/coleccion' => 'catalog',
        'museum/collection' => 'catalog',
        'musee/collection' => 'catalog',
        'museu/colecao' => 'catalog',
        'contacto' => 'contact',
        'contact' => 'contact',
        'contato' => 'contact',
        'historia' => 'history',
        'history' => 'history',
        'histoire' => 'history',
        'nossa-historia' => 'history',
        'cursos' => 'theatre_school',
        'teatroescuela' => 'theatre_school',
        'theaterschool' => 'theatre_school',
        'theatreecole' => 'theatre_school',
        'escola-de-teatro' => 'theatre_school',
    ];

    public static function homepageSegment(string $locale): string
    {
        return match (strtolower(trim($locale))) {
            'en' => 'home',
            'fr' => 'accueil',
            default => 'inicio',
        };
    }

    public static function canonicalPath(string $path, string $locale): ?string
    {
        $normalized = self::normalizedPath($path);
        if ($normalized === '') {
            return self::homepageSegment($locale);
        }

        $lookup = strtolower($normalized);
        if (isset(self::HOMEPAGE_ALIASES[$lookup]) || $lookup === self::homepageSegment($locale)) {
            return self::homepageSegment($locale);
        }

        $routeKey = self::ROUTE_ALIASES[$lookup] ?? null;
        if ($routeKey === null) {
            return null;
        }

        return self::routePath($routeKey, $locale);
    }

    /** @return list<string> */
    public static function aliasesFor(string $path, string $locale): array
    {
        $normalized = self::normalizedPath($path);
        $canonical = self::canonicalPath($normalized, $locale);
        if ($canonical === null) {
            return [];
        }

        $lookup = strtolower($normalized);
        $aliases = [];
        if (isset(self::HOMEPAGE_ALIASES[$lookup]) || $lookup === self::homepageSegment($locale)) {
            $aliases = array_keys(self::HOMEPAGE_ALIASES);
        } else {
            foreach (self::ROUTE_ALIASES as $alias => $routeKey) {
                if (self::routePath($routeKey, $locale) === $canonical) {
                    $aliases[] = $alias;
                }
            }
        }

        return array_values(array_unique(array_filter(
            [$canonical, ...$aliases],
            static fn (string $candidate): bool => $candidate !== $normalized,
        )));
    }

    public static function isLegacyPublicBasePath(string $path, string $locale): bool
    {
        return strcasecmp(trim($path, '/'), 'public/' . strtolower(trim($locale, '/'))) === 0;
    }

    private static function normalizedPath(string $path): string
    {
        $parsed = parse_url(trim($path), PHP_URL_PATH);

        return trim(is_string($parsed) ? $parsed : '', '/');
    }

    public static function routePath(string $routeKey, string $locale): string
    {
        $locale = strtolower(trim($locale));

        return match ($routeKey) {
            'events' => match ($locale) {
                'en' => 'programming',
                'fr' => 'programmation',
                'pt' => 'programacao',
                default => 'cartelera',
            },
            'catalog' => match ($locale) {
                'en' => 'museum/collection',
                'fr' => 'musee/collection',
                'pt' => 'museu/colecao',
                default => 'museo/coleccion',
            },
            'contact' => match ($locale) {
                'en', 'fr' => 'contact',
                'pt' => 'contato',
                default => 'contacto',
            },
            'history' => match ($locale) {
                'en' => 'history',
                'fr' => 'histoire',
                'pt' => 'nossa-historia',
                default => 'historia',
            },
            'theatre_school' => match ($locale) {
                'en' => 'theaterschool',
                'fr' => 'theatreecole',
                'pt' => 'escola-de-teatro',
                default => 'teatroescuela',
            },
            default => '',
        };
    }
}

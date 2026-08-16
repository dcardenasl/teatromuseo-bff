<?php

declare(strict_types=1);

namespace Tests\Unit\PublicRead;

use App\PublicRead\Page\PublicMenuUrlResolver;
use PHPUnit\Framework\TestCase;

final class PublicMenuUrlResolverTest extends TestCase
{
    public function testResolvesCmsTargetsAndKnownRoutes(): void
    {
        $resolver = new PublicMenuUrlResolver();

        self::assertSame('/noticias/lanzamiento', $resolver->resolve([
            'navigation' => [
                'target_type' => 'entry',
                'collection_slug' => 'noticias',
                'slug' => 'lanzamiento',
            ],
        ], 'es', []));
        self::assertSame('/cartelera', $resolver->resolve([
            'navigation' => [
                'route_key' => 'events',
                'target_type' => 'event_listing',
            ],
        ], 'es', []));
        self::assertSame('/contacto', $resolver->resolve([
            'url' => '/contacto',
            'navigation' => [],
        ], 'es', []));
    }

    public function testMissingDestinationRemainsNonClickable(): void
    {
        $resolver = new PublicMenuUrlResolver();

        self::assertNull($resolver->resolve(['url' => null, 'navigation' => []], 'es', []));
        self::assertNull($resolver->resolve(['url' => '', 'navigation' => []], 'es', []));
        self::assertNull($resolver->resolve(['url' => '#', 'navigation' => []], 'es', []));
        self::assertNull($resolver->resolve(['url' => ['invalid' => true], 'navigation' => []], 'es', []));
    }
}

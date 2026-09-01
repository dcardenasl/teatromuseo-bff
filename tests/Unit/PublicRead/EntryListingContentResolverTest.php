<?php

declare(strict_types=1);

namespace Tests\Unit\PublicRead;

use App\PublicRead\Cms\BlockInstanceSerializer;
use App\PublicRead\Cms\EntryListingContentResolver;
use PHPUnit\Framework\TestCase;

final class EntryListingContentResolverTest extends TestCase
{
    public function testProjectsVideoFichaForThePublicListingContract(): void
    {
        $serializer = $this->createMock(BlockInstanceSerializer::class);
        $serializer->expects(self::once())
            ->method('forOwnersBatch')
            ->with('entry', [7], 'es')
            ->willReturn([
                7 => [[
                    'block_key' => 'video_ficha',
                    'block_data' => [
                        'provider' => 'YouTube',
                        'video_id' => 'dQw4w9WgXcQ',
                        'video_url' => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ',
                    ],
                ]],
            ]);

        $result = (new EntryListingContentResolver($serializer))->resolveBatch(
            [['id' => 7]],
            'es',
            [],
            ['video'],
        );

        self::assertSame([
            'provider' => 'youtube',
            'id' => 'dQw4w9WgXcQ',
            'url' => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ',
        ], $result[7]['video']);
    }
}

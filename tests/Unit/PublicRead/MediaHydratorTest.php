<?php

declare(strict_types=1);

namespace Tests\Unit\PublicRead;

use App\PublicRead\Support\FileMetaResolverInterface;
use App\PublicRead\Support\MediaHydrator;
use CodeIgniter\Test\CIUnitTestCase;

final class MediaHydratorTest extends CIUnitTestCase
{
    public function testResolvesUniqueIntegerFileIdsThroughTheSharedResolver(): void
    {
        $resolver = $this->createMock(FileMetaResolverInterface::class);
        $resolver->expects(self::once())
            ->method('resolveMany')
            ->with([3, 7])
            ->willReturn([
                3 => ['url' => '/uploads/three.webp', 'variants' => null],
                7 => ['url' => '/uploads/seven.webp', 'variants' => null],
            ]);

        self::assertSame(
            [
                3 => ['url' => '/uploads/three.webp', 'variants' => null],
                7 => ['url' => '/uploads/seven.webp', 'variants' => null],
            ],
            (new MediaHydrator($resolver))->resolve([3, '7', 3]),
        );
    }

    public function testProjectsSingleAndGalleryMediaWithThePublicShape(): void
    {
        $hydrator = new MediaHydrator($this->createMock(FileMetaResolverInterface::class));
        $media = [
            3 => ['url' => '/uploads/three.webp', 'variants' => '{"sm":{"url":"/uploads/three-sm.webp"}}'],
            7 => ['url' => '/uploads/seven.webp', 'variants' => null],
        ];

        self::assertSame([
            'source_kind' => 'hub_file',
            'file_id' => 3,
            'url' => '/uploads/three.webp',
            'variants' => ['sm' => ['url' => '/uploads/three-sm.webp']],
        ], $hydrator->item($media, 3));
        self::assertNull($hydrator->item($media, 99));
        self::assertSame([
            [
                'source_kind' => 'hub_file',
                'file_id' => 7,
                'url' => '/uploads/seven.webp',
                'variants' => null,
            ],
            [
                'source_kind' => 'hub_file',
                'file_id' => 3,
                'url' => '/uploads/three.webp',
                'variants' => ['sm' => ['url' => '/uploads/three-sm.webp']],
            ],
        ], $hydrator->gallery($media, '7, 99, 3'));
    }
}

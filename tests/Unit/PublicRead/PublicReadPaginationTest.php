<?php

declare(strict_types=1);

namespace Tests\Unit\PublicRead;

use App\PublicRead\Support\PublicReadPagination;
use CodeIgniter\Test\CIUnitTestCase;

final class PublicReadPaginationTest extends CIUnitTestCase
{
    /** @dataProvider offsetProvider */
    public function testCalculatesTheSharedOffset(int $page, int $perPage, int $expected): void
    {
        self::assertSame($expected, PublicReadPagination::offset($page, $perPage));
    }

    /** @return array<string, array{int, int, int}> */
    public static function offsetProvider(): array
    {
        return [
            'first page' => [1, 12, 0],
            'third page' => [3, 12, 24],
            'second page with small window' => [2, 5, 5],
        ];
    }
}

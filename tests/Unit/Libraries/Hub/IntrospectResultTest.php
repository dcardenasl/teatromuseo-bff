<?php

declare(strict_types=1);

namespace Tests\Unit\Libraries\Hub;

use CodeIgniter\Test\CIUnitTestCase;
use dcardenasl\Ci4ApiCore\Http\Client\IntrospectResult;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(IntrospectResult::class)]
final class IntrospectResultTest extends CIUnitTestCase
{
    public function testFromArrayParsesValidPayload(): void
    {
        $result = IntrospectResult::fromArray([
            'valid'       => true,
            'uid'         => 42,
            'permissions' => ['items.read', 'items.write'],
            'exp'         => 1735689600,
            'error'       => null,
        ]);

        $this->assertTrue($result->valid);
        $this->assertSame(42, $result->uid);
        $this->assertSame(['items.read', 'items.write'], $result->permissions);
        $this->assertSame(1735689600, $result->exp);
        $this->assertNull($result->error);
    }

    public function testFromArrayHandlesInvalidPayload(): void
    {
        $result = IntrospectResult::fromArray([
            'valid' => false,
            'error' => 'invalid_or_expired',
        ]);

        $this->assertFalse($result->valid);
        $this->assertNull($result->uid);
        $this->assertSame([], $result->permissions);
        $this->assertNull($result->exp);
        $this->assertSame('invalid_or_expired', $result->error);
    }

    public function testInvalidFactory(): void
    {
        $result = IntrospectResult::invalid('hub_unreachable');

        $this->assertFalse($result->valid);
        $this->assertSame('hub_unreachable', $result->error);
        $this->assertSame([], $result->permissions);
    }

    public function testFromArrayCoercesPermissionsToStringList(): void
    {
        $result = IntrospectResult::fromArray([
            'valid'       => true,
            'uid'         => 1,
            'permissions' => [123, 'items.read', true],
            'exp'         => '1735689600',
        ]);

        $this->assertTrue($result->valid);
        $this->assertSame(1735689600, $result->exp);
        // Booleans cast to '1', ints stringified — confirms we never trust the wire.
        $this->assertContainsOnly('string', $result->permissions);
    }
}

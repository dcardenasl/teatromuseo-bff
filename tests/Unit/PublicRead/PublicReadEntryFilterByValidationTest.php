<?php

declare(strict_types=1);

namespace Tests\Unit\PublicRead;

use App\PublicRead\Cms\PublicReadEntryRequestDTO;
use CodeIgniter\Test\CIUnitTestCase;
use Config\Services;
use dcardenasl\Ci4ApiCore\Exceptions\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Regression: `filter_by` used to accept any string up to 100 chars with no
 * shape allow-list, unlike `order_by` (which has a regex allow-list). SQL
 * injection was never actually possible — `PublicReadEntryReader::classifyField()`
 * already routes anything unrecognized into a safely-escaped facet lookup or
 * a deny-all filter — but the DTO layer should reject an obviously malformed
 * value with a clean 422 instead of relying entirely on that downstream
 * behavior. The allow-list mirrors the facet-key shape
 * `order_by`'s `field:` branch already accepts (same `classifyField()` input),
 * just without the `field:` prefix `filter_by` never uses.
 */
final class PublicReadEntryFilterByValidationTest extends CIUnitTestCase
{
    #[DataProvider('acceptedFilterByValues')]
    public function testAcceptsLegitimateFacetAndEntryColumnShapes(string $filterBy): void
    {
        $dto = Services::requestDtoFactory(false)->make(PublicReadEntryRequestDTO::class, [
            'locale' => 'es',
            'collection' => 'news',
            'filter_by' => $filterBy,
            'filter_value' => 'anything',
        ]);

        $this->assertSame($filterBy, $dto->filterBy);
    }

    /** @return iterable<string, list<string>> */
    public static function acceptedFilterByValues(): iterable
    {
        yield 'bare facet key' => ['color'];
        yield 'entry column reference' => ['entry.published_at'];
        yield 'block-namespaced facet key' => ['block.hero.date'];
        yield 'taxonomy-namespaced key' => ['taxonomy.region'];
    }

    #[DataProvider('rejectedFilterByValues')]
    public function testRejectsShapesThatDoNotMatchAnyRecognizedField(string $filterBy): void
    {
        $this->expectException(ValidationException::class);

        Services::requestDtoFactory(false)->make(PublicReadEntryRequestDTO::class, [
            'locale' => 'es',
            'collection' => 'news',
            'filter_by' => $filterBy,
            'filter_value' => 'anything',
        ]);
    }

    /** @return iterable<string, list<string>> */
    public static function rejectedFilterByValues(): iterable
    {
        yield 'sql-looking payload' => ["color' OR '1'='1"];
        yield 'uppercase not allowed' => ['Color'];
        yield 'whitespace' => ['not a field'];
        yield 'unrecognized namespace' => ['entry_meta.color'];
        yield 'too many segments' => ['block.hero.color.extra'];
    }
}

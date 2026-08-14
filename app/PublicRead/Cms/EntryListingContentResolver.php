<?php

declare(strict_types=1);

namespace App\PublicRead\Cms;

/**
 * Builds the small, stable editorial projection consumed by entry listings.
 *
 * The resolver keeps block storage details private: callers receive semantic
 * listing slots rather than a complete serialized block tree.
 */
final class EntryListingContentResolver
{
    public function __construct(private readonly BlockInstanceSerializer $blockSerializer)
    {
    }

    /** Every key this resolver can produce — the full, backward-compatible contract. */
    private const ALL_KEYS = [
        'rich_text', 'image', 'hover_image', 'secondary_action', 'documents',
        'publication_date', 'date_fields', 'fields', 'video',
    ];

    /**
     * @param list<array<string, mixed>> $entries
     * @param list<string> $projectionFields
     * @param list<string> $includeKeys Subset of self::ALL_KEYS to compute and
     *   return. Empty means "no sub-selection requested" — every key is
     *   computed and returned, same as before this parameter existed. A
     *   caller that only renders a card's image and date (e.g. collection_grid)
     *   passes `['image', 'date_fields']` and skips the cost/payload of
     *   rich_text, video, documents, and secondary_action entirely.
     * @return array<int, array<string, mixed>>
     */
    public function resolveBatch(array $entries, string $langCode, array $projectionFields = [], array $includeKeys = []): array
    {
        $keys = $includeKeys === [] ? self::ALL_KEYS : array_values(array_intersect(self::ALL_KEYS, $includeKeys));

        $entryIds = [];
        foreach ($entries as $entry) {
            $entryId = (int) ($entry['id'] ?? 0);
            if ($entryId > 0) {
                $entryIds[] = $entryId;
            }
        }

        $blocksByEntry = $this->blockSerializer->forOwnersBatch('entry', $entryIds, $langCode);
        $result = [];

        foreach ($entries as $entry) {
            $entryId = (int) ($entry['id'] ?? 0);
            if ($entryId <= 0) {
                continue;
            }

            $schemaListing = $this->schemaListing($entry['schema_data'] ?? null);
            $blocks = $blocksByEntry[$entryId] ?? [];

            $item = [];
            foreach ($keys as $key) {
                $item[$key] = match ($key) {
                    'rich_text' => $this->stringValue($schemaListing['rich_text'] ?? null)
                        ?: $this->richTextFromBlock($blocks),
                    'image' => $this->imageFromSchema($schemaListing['image'] ?? null)
                        ?? $this->imageFromBlock($blocks),
                    'hover_image' => $this->hoverImageFromBlock($blocks),
                    'secondary_action' => $this->actionFromSchema($schemaListing['secondary_action'] ?? null)
                        ?? $this->actionFromBlock($blocks),
                    'documents' => $this->documentsFromBlocks($blocks),
                    'publication_date' => $this->publicationDateFromBlocks($blocks)
                        ?: $this->publicationYearFromEntry($entry),
                    'date_fields' => $this->dateFieldsFromBlocks($blocks),
                    'fields' => $this->projectionValues($entry, $blocks, $projectionFields),
                    'video' => $this->videoFromBlocks($blocks),
                    default => null,
                };
            }

            $result[$entryId] = $item;
        }

        return $result;
    }

    /**
     * Resolve only fields explicitly requested by a listing projection.
     * Unknown values are omitted from the public contract. Scalar fields are
     * exposed as strings and media references keep their canonical metadata so
     * a card can use the same projection contract for text and images.
     *
     * @param array<string, mixed> $entry
     * @param list<array<string, mixed>> $blocks
     * @param list<string> $projectionFields
     * @return array<string, mixed>
     */
    private function projectionValues(array $entry, array $blocks, array $projectionFields): array
    {
        $values = [];
        foreach ($projectionFields as $reference) {
            $reference = trim($reference);
            if (str_starts_with($reference, 'entry.')) {
                $value = $entry[substr($reference, 6)] ?? null;
            } elseif ($reference === 'taxonomy.categories' || $reference === 'taxonomy.tags') {
                $taxonomy = $entry[$reference === 'taxonomy.categories' ? 'categories' : 'tags'] ?? [];
                $value = is_array($taxonomy)
                    ? implode(', ', array_values(array_filter(array_map(static fn (mixed $item): string => is_array($item) ? trim((string) ($item['name'] ?? $item['label'] ?? $item['slug'] ?? '')) : trim((string) $item), $taxonomy))))
                    : null;
            } elseif (str_starts_with($reference, 'block.')) {
                $parts = explode('.', $reference, 3);
                $value = null;
                if (count($parts) === 3) {
                    foreach ($blocks as $block) {
                        if ((string) ($block['block_key'] ?? '') !== $parts[1]) {
                            continue;
                        }
                        $declared = is_array($block['listing_fields'] ?? null) ? $block['listing_fields'] : [];
                        if (! isset($declared[$parts[2]])) {
                            continue;
                        }
                        $data = is_array($block['block_data'] ?? null) ? $block['block_data'] : [];
                        $value = $data[$parts[2]] ?? null;
                        break;
                    }
                }
            } else {
                $value = null;
            }

            if (is_array($value) && trim((string) ($value['url'] ?? '')) !== '') {
                $values[$reference] = $value;
            } elseif (is_scalar($value) && trim((string) $value) !== '') {
                $values[$reference] = trim((string) $value);
            }
        }

        return $values;
    }

    /**
     * Return only date fields explicitly declared by a block schema. This is
     * the stable listing contract; arbitrary block_data is never exposed.
     *
     * @param list<array<string, mixed>> $blocks
     * @return array<string, string>
     */
    private function dateFieldsFromBlocks(array $blocks): array
    {
        $fields = [];
        foreach ($blocks as $block) {
            $declared = is_array($block['listing_fields'] ?? null) ? $block['listing_fields'] : [];
            $data = is_array($block['block_data'] ?? null) ? $block['block_data'] : [];

            foreach ($declared as $field => $definition) {
                if (! is_array($definition) || ($definition['type'] ?? '') !== 'date') {
                    continue;
                }
                $value = $this->stringValue($data[$field] ?? null);
                if ($value !== '') {
                    $fields[(string) $field] = $value;
                }
            }
        }

        return $fields;
    }

    /** @return array<string, mixed> */
    private function schemaListing(mixed $schemaData): array
    {
        if (is_string($schemaData)) {
            $schemaData = json_decode($schemaData, true);
        }
        if (is_object($schemaData)) {
            $schemaData = (array) $schemaData;
        }

        $listing = is_array($schemaData) ? ($schemaData['listing'] ?? []) : [];
        if (is_object($listing)) {
            $listing = (array) $listing;
        }

        return is_array($listing) ? $listing : [];
    }

    /** @param list<array<string, mixed>> $blocks */
    private function richTextFromBlock(array $blocks): string
    {
        $data = $this->firstBlockData($blocks, 'rich_text');

        return $this->stringValue($data['content'] ?? null);
    }

    /**
     * @param list<array<string, mixed>> $blocks
     * @return array<string, mixed>|null
     */
    private function imageFromBlock(array $blocks): ?array
    {
        foreach ($blocks as $block) {
            if (($block['block_key'] ?? null) !== 'image') {
                continue;
            }

            $config = is_array($block['block_config'] ?? null) ? $block['block_config'] : [];
            $data = is_array($block['block_data'] ?? null) ? $block['block_data'] : [];
            $image = $this->imageFromSchema($config['image'] ?? null);
            if ($image !== null) {
                $image['alt'] = $this->stringValue($data['alt'] ?? $image['alt']);

                return $image;
            }
        }

        return null;
    }

    /**
     * @param list<array<string, mixed>> $blocks
     * @return array<string, mixed>|null
     */
    private function hoverImageFromBlock(array $blocks): ?array
    {
        foreach ($blocks as $block) {
            if (($block['block_key'] ?? null) !== 'persona_ficha') {
                continue;
            }

            $data = is_array($block['block_data'] ?? null) ? $block['block_data'] : [];
            $image = $this->imageFromSchema($data['hover_portrait'] ?? null);
            if ($image !== null) {
                return $image;
            }
        }

        return null;
    }

    /**
     * @param list<array<string, mixed>> $blocks
     * @return array{label: string, url: string}|null
     */
    private function actionFromBlock(array $blocks): ?array
    {
        $data = $this->firstBlockData($blocks, 'cta');

        return $this->actionFromSchema([
            'label' => $data['label'] ?? null,
            'url' => $data['url'] ?? null,
        ]);
    }

    /**
     * Resolve every document block, not only the first one. The current data
     * uses repeated document_download instances for some publications, while
     * document_gallery stores several files inside one repeater. Keeping both
     * shapes behind this projection preserves backwards compatibility and
     * gives public listings one stable multi-document contract.
     *
     * @param list<array<string, mixed>> $blocks
     * @return list<array{url: string, title: string, description: string, file_id: int|null}>
     */
    private function documentsFromBlocks(array $blocks): array
    {
        $documents = [];
        foreach ($blocks as $block) {
            $key = (string) ($block['block_key'] ?? '');
            $data = is_array($block['block_data'] ?? null) ? $block['block_data'] : [];
            $config = is_array($block['block_config'] ?? null) ? $block['block_config'] : [];

            if ($key === 'document_download') {
                $document = $this->documentFromReference(
                    $config['document'] ?? null,
                    $this->stringValue($data['title'] ?? null),
                    $this->stringValue($data['description'] ?? null),
                );
                if ($document !== null) {
                    $documents[] = $document;
                }
            }

            if ($key === 'document_gallery') {
                $items = is_array($data['documents'] ?? null) ? $data['documents'] : [];
                foreach ($items as $item) {
                    if (! is_array($item)) {
                        continue;
                    }
                    $document = $this->documentFromReference(
                        $item['file'] ?? null,
                        $this->stringValue($item['title'] ?? null),
                        $this->stringValue($item['description'] ?? null),
                    );
                    if ($document !== null) {
                        $documents[] = $document;
                    }
                }
            }

            if ($key === 'publicacion_metadata') {
                $document = $this->documentFromReference(
                    $data['document_link'] ?? null,
                    '',
                    '',
                );
                if ($document !== null) {
                    $documents[] = $document;
                }
            }
        }

        $unique = [];
        foreach ($documents as $document) {
            $identity = $document['file_id'] !== null
                ? 'file:' . $document['file_id']
                : 'url:' . $document['url'];
            if (! isset($unique[$identity])) {
                $unique[$identity] = $document;
            }
        }

        return array_values($unique);
    }

    /** @param list<array<string, mixed>> $blocks */
    private function publicationDateFromBlocks(array $blocks): string
    {
        foreach ($blocks as $block) {
            if (($block['block_key'] ?? null) !== 'publicacion_metadata') {
                continue;
            }
            $data = is_array($block['block_data'] ?? null) ? $block['block_data'] : [];
            $date = $this->stringValue($data['publication_date'] ?? null);
            if ($date !== '') {
                return $date;
            }
        }

        return '';
    }

    /** @param array<string, mixed> $entry */
    private function publicationYearFromEntry(array $entry): string
    {
        $title = $this->stringValue($entry['title'] ?? null);
        if ($title === '') {
            return '';
        }

        preg_match_all('/\b(?:19|20)\d{2}\b/', $title, $matches);
        $years = array_values(array_unique($matches[0]));
        if ($years === []) {
            return '';
        }

        return count($years) === 1
            ? $years[0]
            : $years[0] . '–' . $years[count($years) - 1];
    }

    /**
     * Expose only the small provider identity needed by public listing cards.
     * Keeping this projection here avoids loading/rendering the full block tree
     * for every card and keeps the listing N+1-safe.
     *
     * @param list<array<string, mixed>> $blocks
     * @return array{provider: string, id: string, url: string}|null
     */
    private function videoFromBlocks(array $blocks): ?array
    {
        foreach ($blocks as $block) {
            if (($block['block_key'] ?? null) !== 'video_ficha') {
                continue;
            }

            $data = is_array($block['block_data'] ?? null) ? $block['block_data'] : [];
            $provider = strtolower($this->stringValue($data['provider'] ?? null));
            $videoId = $this->stringValue($data['video_id'] ?? null);
            $videoUrl = $this->stringValue($data['video_url'] ?? null);

            if (! in_array($provider, ['youtube', 'vimeo'], true) || $videoId === '') {
                return null;
            }

            if ($videoUrl !== '' && ! preg_match('#^https?://#i', $videoUrl)) {
                $videoUrl = '';
            }

            return [
                'provider' => $provider,
                'id' => $videoId,
                'url' => $videoUrl,
            ];
        }

        return null;
    }

    /** @return array{url: string, title: string, description: string, file_id: int|null}|null */
    private function documentFromReference(mixed $value, string $title, string $description): ?array
    {
        if (is_string($value)) {
            $value = ['url' => $value];
        }
        if (is_object($value)) {
            $value = (array) $value;
        }
        if (! is_array($value)) {
            return null;
        }

        $fileId = is_numeric($value['file_id'] ?? null) && (int) $value['file_id'] > 0
            ? (int) $value['file_id']
            : null;
        $url = $this->stringValue($value['url'] ?? null);
        if ($url === '' || str_starts_with($url, '/files/')) {
            return null;
        }
        if ($fileId === null && ! str_starts_with($url, 'http') && ! str_starts_with($url, '/')) {
            return null;
        }

        return [
            'url' => $url,
            'title' => $title,
            'description' => $description,
            'file_id' => $fileId,
        ];
    }

    /**
     * @param list<array<string, mixed>> $blocks
     * @return array<string, mixed>
     */
    private function firstBlockData(array $blocks, string $blockKey): array
    {
        foreach ($blocks as $block) {
            if (($block['block_key'] ?? null) === $blockKey && is_array($block['block_data'] ?? null)) {
                return $block['block_data'];
            }
        }

        return [];
    }

    /** @return array<string, mixed>|null */
    private function imageFromSchema(mixed $value): ?array
    {
        if (is_string($value)) {
            $value = ['url' => $value];
        }
        if (is_object($value)) {
            $value = (array) $value;
        }
        if (!is_array($value)) {
            return null;
        }

        $url = $this->stringValue($value['url'] ?? null);
        if ($url === '') {
            return null;
        }

        $image = [
            'url' => $url,
            'alt' => $this->stringValue($value['alt'] ?? null),
        ];

        if (is_string($value['source_kind'] ?? null) && trim($value['source_kind']) !== '') {
            $image['source_kind'] = strtolower(trim($value['source_kind']));
        }
        if (is_numeric($value['file_id'] ?? null) && (int) $value['file_id'] > 0) {
            $image['file_id'] = (int) $value['file_id'];
        }
        if (is_array($value['variants'] ?? null) && $value['variants'] !== []) {
            $image['variants'] = $value['variants'];
        }

        return $image;
    }

    /** @return array{label: string, url: string}|null */
    private function actionFromSchema(mixed $value): ?array
    {
        if (is_object($value)) {
            $value = (array) $value;
        }
        if (!is_array($value)) {
            return null;
        }

        $label = $this->stringValue($value['label'] ?? null);
        $url = $this->stringValue($value['url'] ?? null);

        return $label !== '' && $url !== '' ? ['label' => $label, 'url' => $url] : null;
    }

    private function stringValue(mixed $value): string
    {
        return is_string($value) ? trim($value) : '';
    }
}

<?php

declare(strict_types=1);

namespace App\AdminRead\Cms;

use App\AdminRead\Contracts\AdminCmsWorkspaceSourceInterface;
use App\PublicRead\Cms\FileUrlResolver;
use CodeIgniter\Database\BaseConnection;
use dcardenasl\Ci4ApiCore\Exceptions\AuthorizationException;
use RuntimeException;

/**
 * Read-only CMS workspace projection used by the Admin page/block screens.
 *
 * The Admin makes one request to the BFF. The BFF reads the CMS and Hub
 * read-only connections directly, avoiding an HTTP fan-out through several
 * PHP processes on the constrained hosting plan.
 *
 * This class is the thin orchestrator: {@see CmsWorkspaceProjectionQuery}
 * builds and runs the single bounded SQL projection, {@see CmsWorkspaceRowHydrator}
 * decodes/types the raw row and hydrates media, and {@see CmsBlockOptionsResolver}
 * fills the block editor's dynamic form options from the hydrated sections.
 */
final class AdminCmsWorkspaceSource implements AdminCmsWorkspaceSourceInterface
{
    /** @param BaseConnection<mixed, mixed> $db */
    public function __construct(
        private readonly BaseConnection $db,
        private readonly FileUrlResolver $fileUrlResolver,
        private readonly ?BlockTranslationStatusProjection $blockTranslationStatusProjection = null,
        private readonly ?CmsWorkspaceRowHydrator $rowHydrator = null,
        private readonly ?CmsBlockOptionsResolver $blockOptionsResolver = null,
    ) {
    }

    /**
     * @param list<string> $permissions
     * @return array<string, mixed>
     */
    public function pageWorkspace(int $pageId, ?int $instanceId, array $permissions): array
    {
        return $this->workspace('page', $pageId, $instanceId, $permissions);
    }

    /**
     * @param list<string> $permissions
     * @return array<string, mixed>
     */
    public function entryWorkspace(int $entryId, ?int $instanceId, array $permissions): array
    {
        return $this->workspace('entry', $entryId, $instanceId, $permissions);
    }

    /**
     * @param 'page'|'entry' $ownerType
     * @param list<string> $permissions
     * @return array<string, mixed>
     */
    private function workspace(string $ownerType, int $ownerId, ?int $instanceId, array $permissions): array
    {
        if ($ownerId < 1) {
            throw new RuntimeException('A positive CMS owner identifier is required.');
        }
        $permission = $ownerType === 'entry' ? 'cms.entries.read' : 'cms.pages.read';
        if (! in_array($permission, $permissions, true)) {
            throw new AuthorizationException('The ' . $permission . ' permission is required.');
        }

        $row = CmsWorkspaceProjectionQuery::fetch($this->db, $ownerType, $ownerId, $permissions);
        if ($row === null) {
            throw new RuntimeException('CMS ' . $ownerType . ' not found.');
        }
        $rowHydrator = $this->rowHydrator ?? new CmsWorkspaceRowHydrator($this->fileUrlResolver);
        $projection = $rowHydrator->hydrate($row, $ownerType);

        $owner = $projection['owner'];
        $languages = $projection['languages'];
        $blocks = $projection['blocks'];
        $collections = $projection['collections'];
        $pages = $projection['pages'];
        $entries = $projection['entries'];
        $blockOptionsResolver = $this->blockOptionsResolver ?? new CmsBlockOptionsResolver();
        $blockTypes = $blockOptionsResolver->withDynamicOptions(
            $projection['blockTypes'],
            $collections,
            $permissions,
            $projection['forms'],
            $pages,
            $entries,
            $projection['categories'],
        );
        $translationStatus = ($this->blockTranslationStatusProjection ?? new BlockTranslationStatusProjection())
            ->project($blocks, $blockTypes, $languages);

        $sections = [
            $ownerType => $owner,
            'pages' => $pages,
            'collections' => $collections,
            'languages' => $languages,
            'blocks' => $blocks,
            'blockTypes' => $blockTypes,
            'collectionsMap' => $this->collectionsMap($collections),
            'listingFieldCatalog' => $this->listingFieldCatalog($collections, $blockTypes),
            'quality' => [],
            'blockTranslationStatus' => $translationStatus['blocks'],
            'blockTranslationSummary' => $translationStatus['summary'],
        ];
        if ($ownerType === 'entry') {
            $sections['entries'] = $entries;
        }

        if ($instanceId !== null && $instanceId > 0) {
            $selected = $this->findBlock($blocks, $instanceId);
            if ($selected !== null) {
                $sections['block'] = $selected;
                $sections['blockType'] = $blockTypes[(int) ($selected['block_id'] ?? 0)] ?? [];
                $parentId = (int) ($selected['parent_instance_id'] ?? 0);
                if ($parentId > 0) {
                    $sections['parentBlock'] = $this->findBlock($blocks, $parentId) ?? [];
                    $sections['parentType'] = $blockTypes[(int) ($sections['parentBlock']['block_id'] ?? 0)] ?? [];
                }
                $sections['children'] = array_values(array_filter(
                    $blocks,
                    static fn (array $block): bool => (int) ($block['parent_instance_id'] ?? 0) === $instanceId,
                ));
            }
        }

        return $sections;
    }

    /**
     * @param list<array<string, mixed>> $collections
     * @return array<string, int>
     */
    private function collectionsMap(array $collections): array
    {
        $map = [];
        foreach ($collections as $collection) {
            $key = trim((string) ($collection['collection_key'] ?? ''));
            $id = (int) ($collection['id'] ?? 0);
            if ($key !== '' && $id > 0) {
                $map[$key] = $id;
            }
        }

        return $map;
    }

    /**
     * @param list<array<string, mixed>> $collections
     * @param array<int, array<string, mixed>> $blockTypes
     * @return array<string|int, list<array<string, mixed>>>
     */
    private function listingFieldCatalog(array $collections, array $blockTypes): array
    {
        $base = [
            ['value' => 'entry.title', 'label' => 'Título', 'group' => 'Datos de la entrada', 'type' => 'text', 'sortable' => true, 'filterable' => true],
            ['value' => 'entry.excerpt', 'label' => 'Resumen', 'group' => 'Datos de la entrada', 'type' => 'text', 'sortable' => false, 'filterable' => true],
            ['value' => 'entry.slug', 'label' => 'Slug', 'group' => 'Datos de la entrada', 'type' => 'text', 'sortable' => true, 'filterable' => true],
            ['value' => 'entry.published_at', 'label' => 'Fecha de publicación', 'group' => 'Datos de la entrada', 'type' => 'date', 'sortable' => true, 'filterable' => true],
            ['value' => 'entry.created_at', 'label' => 'Fecha de creación', 'group' => 'Datos de la entrada', 'type' => 'date', 'sortable' => true, 'filterable' => true],
            ['value' => 'taxonomy.categories', 'label' => 'Categorías', 'group' => 'Taxonomía', 'type' => 'taxonomy', 'sortable' => false, 'filterable' => true],
            ['value' => 'taxonomy.tags', 'label' => 'Etiquetas', 'group' => 'Taxonomía', 'type' => 'taxonomy', 'sortable' => false, 'filterable' => true],
        ];
        $catalog = ['event_items' => $base, 'catalog_items' => $base];
        foreach ($collections as $collection) {
            $id = (int) ($collection['id'] ?? 0);
            $key = trim((string) ($collection['collection_key'] ?? ''));
            if ($id > 0) {
                $catalog[$id] = $base;
            }
            if ($key !== '') {
                $catalog[$key] = $base;
            }
        }

        return $catalog;
    }

    /**
     * @param list<array<string, mixed>> $blocks
     * @return array<string, mixed>|null
     */
    private function findBlock(array $blocks, int $instanceId): ?array
    {
        foreach ($blocks as $block) {
            if ((int) ($block['id'] ?? 0) === $instanceId) {
                return $block;
            }
        }

        return null;
    }
}

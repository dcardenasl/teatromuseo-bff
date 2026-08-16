<?php

declare(strict_types=1);

namespace Tests\Support;

use CodeIgniter\Database\BaseConnection;

/** Disposable SQL fixture for the localized public-slug projection contract. */
final class PublicReadSlugFixture
{
    /** @param BaseConnection<mixed, mixed> $db */
    public static function createEventSchema(BaseConnection $db): void
    {
        $db->query(<<<'SQL'
            CREATE TABLE events (
                id INTEGER PRIMARY KEY,
                uuid TEXT,
                title TEXT,
                event_type TEXT,
                description TEXT,
                cover_file_id INTEGER,
                gallery_file_ids TEXT,
                status TEXT NOT NULL,
                created_at TEXT,
                updated_at TEXT,
                deleted_at TEXT
            )
        SQL);
        $db->query(<<<'SQL'
            CREATE TABLE occurrences (
                id INTEGER PRIMARY KEY,
                event_id INTEGER NOT NULL,
                venue_id INTEGER,
                start_time TEXT,
                end_time TEXT,
                status TEXT,
                capacity INTEGER,
                available_spots INTEGER,
                created_at TEXT,
                updated_at TEXT,
                deleted_at TEXT
            )
        SQL);
        $db->query('CREATE TABLE venues (id INTEGER PRIMARY KEY, name TEXT)');
        $db->query(<<<'SQL'
            CREATE TABLE event_translations (
                translatable_type TEXT,
                translatable_id INTEGER,
                locale TEXT,
                field TEXT,
                value TEXT,
                updated_at TEXT,
                created_at TEXT
            )
        SQL);
        $db->query(<<<'SQL'
            CREATE TABLE event_public_slugs (
                resource_type TEXT,
                resource_id INTEGER,
                locale TEXT,
                slug TEXT,
                updated_at TEXT,
                created_at TEXT
            )
        SQL);
    }

    /** @param BaseConnection<mixed, mixed> $db */
    public static function insertEvent(BaseConnection $db): void
    {
        $db->table('events')->insert([
            'id' => 1,
            'uuid' => '00000000-0000-0000-0000-000000000001',
            'title' => 'Festival',
            'event_type' => 'show',
            'description' => 'Descripción',
            'status' => 'published',
            'created_at' => '2026-01-01 00:00:00',
            'updated_at' => '2026-01-01 00:00:00',
        ]);
        $db->table('occurrences')->insert([
            'id' => 1,
            'event_id' => 1,
            'start_time' => '2099-01-01 00:00:00',
            'created_at' => '2026-01-01 00:00:00',
            'updated_at' => '2026-01-01 00:00:00',
        ]);

        foreach (self::slugs('festival') as $locale => $slug) {
            $db->table('event_public_slugs')->insert([
                'resource_type' => 'event',
                'resource_id' => 1,
                'locale' => $locale,
                'slug' => $slug,
                'created_at' => '2026-01-01 00:00:00',
            ]);
        }
    }

    /** @param BaseConnection<mixed, mixed> $db */
    public static function createCatalogSchema(BaseConnection $db): void
    {
        $db->query(<<<'SQL'
            CREATE TABLE collection_items (
                id INTEGER PRIMARY KEY,
                name TEXT,
                category_id INTEGER,
                inventory_code TEXT,
                status TEXT,
                summary TEXT,
                curiosidad TEXT,
                contenido TEXT,
                origin TEXT,
                period TEXT,
                creator TEXT,
                ubicacion TEXT,
                materials TEXT,
                cover_file_id INTEGER,
                gallery_file_ids TEXT,
                collection_number TEXT,
                collection_group TEXT,
                physical_description TEXT,
                dimensions TEXT,
                ingress_type TEXT,
                donated_by TEXT,
                tags TEXT,
                links TEXT,
                company_history TEXT,
                is_active INTEGER,
                created_at TEXT,
                updated_at TEXT,
                deleted_at TEXT
            )
        SQL);
        $db->query(<<<'SQL'
            CREATE TABLE catalog_public_slugs (
                resource_type TEXT,
                resource_id INTEGER,
                locale TEXT,
                slug TEXT
            )
        SQL);
        $db->query(<<<'SQL'
            CREATE TABLE catalog_translations (
                translatable_type TEXT,
                translatable_id INTEGER,
                locale TEXT,
                field TEXT,
                value TEXT
            )
        SQL);
        $db->query('CREATE TABLE collection_item_technique (collection_item_id INTEGER, technique_id INTEGER)');
        $db->query('CREATE TABLE techniques (id INTEGER PRIMARY KEY, name TEXT, slug TEXT, summary TEXT, sort_order INTEGER, deleted_at TEXT)');
    }

    /** @param BaseConnection<mixed, mixed> $db */
    public static function insertCatalog(BaseConnection $db): void
    {
        $db->table('collection_items')->insert([
            'id' => 1,
            'name' => 'Pieza',
            'status' => 'published',
            'is_active' => 1,
            'created_at' => '2026-01-01 00:00:00',
            'updated_at' => '2026-01-01 00:00:00',
        ]);

        foreach (self::slugs('piece') as $locale => $slug) {
            $db->table('catalog_public_slugs')->insert([
                'resource_type' => 'collection_item',
                'resource_id' => 1,
                'locale' => $locale,
                'slug' => $slug,
            ]);
        }
    }

    /** @return array<string, string> */
    public static function slugs(string $prefix): array
    {
        return [
            'es' => $prefix . '-es',
            'en' => $prefix . '-en',
            'fr' => $prefix . '-fr',
            'pt' => $prefix . '-pt',
        ];
    }
}

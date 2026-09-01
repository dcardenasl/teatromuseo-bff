<?php

declare(strict_types=1);

namespace Tests\Unit\PublicRead;

use App\PublicRead\Cms\PublicReadFormReader;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Test\CIUnitTestCase;
use Config\Database;

/** @internal */
final class PublicReadFormReaderTest extends CIUnitTestCase
{
    /** @var BaseConnection<mixed, mixed> */
    private BaseConnection $readDb;

    protected function setUp(): void
    {
        parent::setUp();

        /** @var BaseConnection<mixed, mixed> $db */
        $db = Database::connect([
            'DSN' => '',
            'hostname' => '',
            'username' => '',
            'password' => '',
            'database' => ':memory:',
            'DBDriver' => 'SQLite3',
            'DBPrefix' => '',
            'pConnect' => false,
            'DBDebug' => true,
            'charset' => 'utf8',
            'DBCollat' => 'utf8_general_ci',
            'swapPre' => '',
            'encrypt' => false,
            'compress' => false,
            'strictOn' => false,
            'failover' => [],
            'port' => 0,
        ], false);
        $this->readDb = $db;

        $this->createSchema();
        $this->seedForm();
    }

    protected function tearDown(): void
    {
        $this->readDb->close();
        parent::tearDown();
    }

    public function testFieldTranslationsUseRequestedDefaultAndFirstAvailableFallbacks(): void
    {
        $result = (new PublicReadFormReader($this->readDb))->show('en', 'contact');

        self::assertSame('Contact form', $result['name']);
        self::assertSame('Send', $result['submit_label']);
        self::assertSame([
            [
                'value' => 'vip',
                'label' => 'VIP',
            ],
            [
                'value' => 'general',
                'label' => 'General',
            ],
        ], $result['fields'][0]['options']);
        self::assertSame('Type in English', $result['fields'][0]['label']);
        self::assertSame('Mensaje en español', $result['fields'][1]['label']);
        self::assertSame('Message in French', $result['fields'][2]['label']);
    }

    private function createSchema(): void
    {
        $this->readDb->query(
            'CREATE TABLE cms_languages (id INTEGER PRIMARY KEY, code TEXT, is_default INTEGER, is_active INTEGER)',
        );
        $this->readDb->query(
            'CREATE TABLE cms_forms (id INTEGER PRIMARY KEY, form_key TEXT, is_active INTEGER, has_captcha INTEGER, '
                . 'autoreply_enabled INTEGER, autoreply_email_field TEXT)',
        );
        $this->readDb->query(
            'CREATE TABLE cms_form_translations (id INTEGER PRIMARY KEY, form_id INTEGER, language_id INTEGER, '
                . 'name TEXT, description TEXT, submit_label TEXT, success_message TEXT, error_message TEXT)',
        );
        $this->readDb->query(
            'CREATE TABLE cms_form_fields (id INTEGER PRIMARY KEY, form_id INTEGER, field_key TEXT, field_type TEXT, '
                . 'options TEXT, display_order INTEGER, is_required INTEGER, is_active INTEGER)',
        );
        $this->readDb->query(
            'CREATE TABLE cms_form_field_translations (id INTEGER PRIMARY KEY, form_field_id INTEGER, language_id INTEGER, '
                . 'label TEXT, placeholder TEXT, help_text TEXT, option_labels TEXT, error_required TEXT, error_invalid TEXT)',
        );
    }

    private function seedForm(): void
    {
        $this->readDb->table('cms_languages')->insertBatch([
            ['id' => 1, 'code' => 'es', 'is_default' => 1, 'is_active' => 1],
            ['id' => 2, 'code' => 'en', 'is_default' => 0, 'is_active' => 1],
            ['id' => 3, 'code' => 'fr', 'is_default' => 0, 'is_active' => 1],
        ]);
        $this->readDb->table('cms_forms')->insert([
            'id' => 10,
            'form_key' => 'contact',
            'is_active' => 1,
            'has_captcha' => 0,
            'autoreply_enabled' => 0,
            'autoreply_email_field' => 'email',
        ]);
        $this->readDb->table('cms_form_translations')->insertBatch([
            [
                'id' => 20,
                'form_id' => 10,
                'language_id' => 1,
                'name' => 'Formulario de contacto',
                'description' => null,
                'submit_label' => 'Enviar',
                'success_message' => null,
                'error_message' => null,
            ],
            [
                'id' => 21,
                'form_id' => 10,
                'language_id' => 2,
                'name' => 'Contact form',
                'description' => null,
                'submit_label' => 'Send',
                'success_message' => null,
                'error_message' => null,
            ],
        ]);
        $this->readDb->table('cms_form_fields')->insertBatch([
            ['id' => 100, 'form_id' => 10, 'field_key' => 'type', 'field_type' => 'select', 'options' => '["vip","general"]', 'display_order' => 1, 'is_required' => 1, 'is_active' => 1],
            ['id' => 101, 'form_id' => 10, 'field_key' => 'name', 'field_type' => 'text', 'options' => null, 'display_order' => 2, 'is_required' => 1, 'is_active' => 1],
            ['id' => 102, 'form_id' => 10, 'field_key' => 'message', 'field_type' => 'textarea', 'options' => null, 'display_order' => 3, 'is_required' => 0, 'is_active' => 1],
        ]);
        $this->readDb->table('cms_form_field_translations')->insertBatch([
            [
                'id' => 200,
                'form_field_id' => 100,
                'language_id' => 2,
                'label' => 'Type in English',
                'placeholder' => null,
                'help_text' => null,
                'option_labels' => '{"vip":"VIP","general":"General"}',
                'error_required' => null,
                'error_invalid' => null,
            ],
            [
                'id' => 201,
                'form_field_id' => 101,
                'language_id' => 1,
                'label' => 'Mensaje en español',
                'placeholder' => null,
                'help_text' => null,
                'option_labels' => null,
                'error_required' => null,
                'error_invalid' => null,
            ],
            [
                'id' => 202,
                'form_field_id' => 102,
                'language_id' => 3,
                'label' => 'Message in French',
                'placeholder' => null,
                'help_text' => null,
                'option_labels' => null,
                'error_required' => null,
                'error_invalid' => null,
            ],
            [
                'id' => 203,
                'form_field_id' => 100,
                'language_id' => 1,
                'label' => 'Tipo',
                'placeholder' => null,
                'help_text' => null,
                'option_labels' => '{"vip":"VIP","general":"General"}',
                'error_required' => null,
                'error_invalid' => null,
            ],
        ]);
    }
}

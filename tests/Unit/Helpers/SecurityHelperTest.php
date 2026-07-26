<?php

declare(strict_types=1);

namespace Tests\Unit\Helpers;

use CodeIgniter\Test\CIUnitTestCase;
use dcardenasl\Ci4ApiCore\Security\Hasher;
use dcardenasl\Ci4ApiCore\Security\Token;

/**
 * Security utility tests using the namespaced classes (Token, Hasher).
 * The procedural security.php helper was removed in ci4-api-core v0.3.0.
 */
class SecurityHelperTest extends CIUnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        helper('security');
    }

    // ==================== sanitize_filename() (CI4 built-in) TESTS ====================

    public function testSanitizeFilenameAllowsValidFilename(): void
    {
        $result = sanitize_filename('my-document.pdf');

        $this->assertEquals('my-document.pdf', $result);
    }

    public function testSanitizeFilenameAllowsImageFiles(): void
    {
        $filenames = ['photo.jpg', 'image.png', 'graphic.gif', 'icon.svg'];

        foreach ($filenames as $filename) {
            $result = sanitize_filename($filename);
            $this->assertEquals($filename, $result);
        }
    }

    public function testSanitizeFilenameStripsPathTraversalWithDoubleDots(): void
    {
        $result = sanitize_filename('../etc/passwd');

        $this->assertStringNotContainsString('../', $result);
    }

    public function testSanitizeFilenameStripsDirectorySeparatorInStrictMode(): void
    {
        $result = sanitize_filename('subdir/file.txt');

        $this->assertStringNotContainsString('/', $result);
    }

    public function testSanitizeFilenameWithRelativePathAllowsSlashes(): void
    {
        $result = sanitize_filename('uploads/user/file.txt', true);

        $this->assertEquals('uploads/user/file.txt', $result);
    }

    public function testSanitizeFilenameAllowsUnderscoresAndDashes(): void
    {
        $result = sanitize_filename('my_file-name.pdf');

        $this->assertEquals('my_file-name.pdf', $result);
    }

    public function testSanitizeFilenameAllowsAlphanumeric(): void
    {
        $result = sanitize_filename('Document123.txt');

        $this->assertEquals('Document123.txt', $result);
    }

    // ==================== Token::generate() TESTS ====================

    public function testGenerateTokenReturnsHexString(): void
    {
        $token = Token::generate();

        $this->assertMatchesRegularExpression('/^[a-f0-9]+$/', $token);
    }

    public function testGenerateTokenDefaultsTo64Characters(): void
    {
        $token = Token::generate();

        $this->assertEquals(64, strlen($token)); // 32 bytes = 64 hex chars
    }

    public function testGenerateTokenAcceptsCustomLength(): void
    {
        $token = Token::generate(16);

        $this->assertEquals(32, strlen($token)); // 16 bytes = 32 hex chars
    }

    public function testGenerateTokenReturnsUniqueValues(): void
    {
        $token1 = Token::generate();
        $token2 = Token::generate();

        $this->assertNotEquals($token1, $token2);
    }

    // ==================== Hasher::password() TESTS ====================

    public function testHashPasswordReturnsHash(): void
    {
        $hash = Hasher::password('MyPassword123!');

        $this->assertNotEquals('MyPassword123!', $hash);
        $this->assertStringStartsWith('$2y$', $hash);
    }

    public function testHashPasswordVerifiesCorrectly(): void
    {
        $password = 'SecurePass456!';
        $hash = Hasher::password($password);

        $this->assertTrue(password_verify($password, $hash));
    }

    public function testHashPasswordRejectsDifferentPassword(): void
    {
        $hash = Hasher::password('CorrectPassword');

        $this->assertFalse(password_verify('WrongPassword', $hash));
    }

    // ==================== Token::constantTimeCompare() TESTS ====================

    public function testConstantTimeCompareReturnsTrueForEqualStrings(): void
    {
        $result = Token::constantTimeCompare('secret123', 'secret123');

        $this->assertTrue($result);
    }

    public function testConstantTimeCompareReturnsFalseForDifferentStrings(): void
    {
        $result = Token::constantTimeCompare('secret123', 'secret456');

        $this->assertFalse($result);
    }

    public function testConstantTimeCompareIsCaseSensitive(): void
    {
        $result = Token::constantTimeCompare('Secret', 'secret');

        $this->assertFalse($result);
    }

    public function testConstantTimeCompareHandlesEmptyStrings(): void
    {
        $this->assertTrue(Token::constantTimeCompare('', ''));
        $this->assertFalse(Token::constantTimeCompare('value', ''));
        $this->assertFalse(Token::constantTimeCompare('', 'value'));
    }
}

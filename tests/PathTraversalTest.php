<?php

declare(strict_types=1);

namespace Binapi\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Tests for path traversal protection.
 */
class PathTraversalTest extends BinapiTestCase
{
    private const PAGES_DIR = '/var/www/user/pages';

    public function testValidSimplePath(): void
    {
        $this->assertTrue($this->isValidFolderPath(self::PAGES_DIR, '02.blog'));
    }

    public function testValidNestedPath(): void
    {
        $this->assertTrue($this->isValidFolderPath(self::PAGES_DIR, '02.blog/my-post'));
    }

    public function testValidDeepNestedPath(): void
    {
        $this->assertTrue($this->isValidFolderPath(self::PAGES_DIR, '01.gallery/2026/04/my-album'));
    }

    public function testRejectsDoubleDotAtStart(): void
    {
        $this->assertFalse($this->isValidFolderPath(self::PAGES_DIR, '../etc'));
    }

    public function testRejectsDoubleDotInMiddle(): void
    {
        $this->assertFalse($this->isValidFolderPath(self::PAGES_DIR, '02.blog/../../../etc'));
    }

    public function testRejectsDoubleDotAtEnd(): void
    {
        $this->assertFalse($this->isValidFolderPath(self::PAGES_DIR, '02.blog/..'));
    }

    public function testRejectsUrlEncodedTraversal(): void
    {
        // Even if the path contains encoded .. it should be rejected
        // (preg_match catches the raw characters)
        $this->assertFalse($this->isValidFolderPath(self::PAGES_DIR, '02.blog%2F..'));
    }

    public function testRejectsBackslashTraversal(): void
    {
        $this->assertFalse($this->isValidFolderPath(self::PAGES_DIR, '02.blog\\..\\..\\etc'));
    }

    public function testRejectsMixedTraversal(): void
    {
        $this->assertFalse($this->isValidFolderPath(self::PAGES_DIR, '02.blog/..\\../etc'));
    }

    public function testRejectsRootEscape(): void
    {
        $this->assertFalse($this->isValidFolderPath(self::PAGES_DIR, '/etc'));
    }

    public function testAllowsNumericFolder(): void
    {
        $this->assertTrue($this->isValidFolderPath(self::PAGES_DIR, '02'));
    }

    public function testAllowsHyphenInFolder(): void
    {
        $this->assertTrue($this->isValidFolderPath(self::PAGES_DIR, '02.blog/my-post'));
    }

    public function testAllowsUnderscoreInFolder(): void
    {
        $this->assertTrue($this->isValidFolderPath(self::PAGES_DIR, '02.blog/my_post'));
    }
}

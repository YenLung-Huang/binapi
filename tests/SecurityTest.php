<?php

declare(strict_types=1);

namespace Binapi\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Tests for security utilities.
 */
class SecurityTest extends BinapiTestCase
{
    public function testSanitizeFilenameRemovesSpecialChars(): void
    {
        $this->assertSame('hero.jpg', $this->sanitizeFilename('hero.jpg'));
        $this->assertSame('my-post.md', $this->sanitizeFilename('my-post.md'));
        $this->assertSame('file123.txt', $this->sanitizeFilename('file123.txt'));
    }

    public function testSanitizeFilenameRemovesSpaces(): void
    {
        $this->assertSame('herophoto.jpg', $this->sanitizeFilename('hero photo.jpg'));
    }

    public function testSanitizeFilenameRemovesMostSpecialChars(): void
    {
        // Only a-z, 0-9, hyphen, underscore, dot preserved
        // / is removed, so ../test.md becomes ..test.md
        $this->assertNotSame('../test.md', $this->sanitizeFilename('../test.md'));
    }

    public function testSanitizeFilenamePreservesAllowedChars(): void
    {
        $this->assertSame('my-file_v1.md', $this->sanitizeFilename('my-file_v1.md'));
    }

    public function testEscapeTitleBasic(): void
    {
        $result = $this->escapeTitle('Hello World');
        $this->assertSame('Hello World', $result);
    }

    public function testEscapeTitlePreventsXss(): void
    {
        $result = $this->escapeTitle('<script>alert("xss")</script>');
        $this->assertSame('&lt;script&gt;alert(&quot;xss&quot;)&lt;/script&gt;', $result);
    }

    public function testEscapeTitleHandlesQuotes(): void
    {
        $result = $this->escapeTitle('He said "hello"');
        $this->assertSame('He said &quot;hello&quot;', $result);
    }

    public function testEscapeTitleHandlesSingleQuotes(): void
    {
        $result = $this->escapeTitle("It's a test");
        $this->assertSame('It&#039;s a test', $result);
    }

    public function testEscapeTitleHandlesUnicode(): void
    {
        $result = $this->escapeTitle('中文標題');
        $this->assertSame('中文標題', $result);
    }

    public function testEscapeTitleHandlesEmoji(): void
    {
        $result = $this->escapeTitle('標題 💫');
        $this->assertSame('標題 💫', $result);
    }
}

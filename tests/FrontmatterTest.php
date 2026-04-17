<?php

declare(strict_types=1);

namespace Binapi\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Tests for frontmatter parsing.
 */
class FrontmatterTest extends BinapiTestCase
{
    public function testParseFullFrontmatter(): void
    {
        $content = <<<MD
---
title: "Test Article"
date: "2026-04-17"
published: true
---
# Hello World

This is the body content.
MD;

        $result = $this->parseFrontmatter($content);

        $this->assertStringContainsString('title: "Test Article"', $result['frontmatter']);
        $this->assertStringContainsString('date: "2026-04-17"', $result['frontmatter']);
        $this->assertStringContainsString('published: true', $result['frontmatter']);
        $this->assertStringContainsString('# Hello World', $result['body']);
    }

    public function testParseEmptyFrontmatter(): void
    {
        $content = <<<MD
# Just a heading

Some content.
MD;

        $result = $this->parseFrontmatter($content);

        $this->assertSame('', $result['frontmatter']);
        $this->assertStringContainsString('# Just a heading', $result['body']);
    }

    public function testExtractMetadataWithAllFields(): void
    {
        $content = <<<MD
---
title: "My Article"
date: "2026-04-17"
published: false
---
# Body
MD;

        $metadata = $this->extractMetadata($content);

        $this->assertSame('My Article', $metadata['title']);
        $this->assertSame('2026-04-17', $metadata['date']);
        $this->assertFalse($metadata['published']);
    }

    public function testExtractMetadataDefaults(): void
    {
        $content = <<<MD
# Just a heading
MD;

        $metadata = $this->extractMetadata($content);

        $this->assertNull($metadata['title']);
        $this->assertNull($metadata['date']);
        $this->assertTrue($metadata['published']); // Default
    }

    public function testExtractMetadataWithSingleQuotes(): void
    {
        $content = <<<MD
---
title: 'Single Quote Title'
---
MD;

        $metadata = $this->extractMetadata($content);

        $this->assertSame('Single Quote Title', $metadata['title']);
    }

    public function testExtractMetadataWithNoQuotes(): void
    {
        $content = <<<MD
---
title: Unquoted Title
published: false
---
MD;

        $metadata = $this->extractMetadata($content);

        $this->assertSame('Unquoted Title', $metadata['title']);
        $this->assertFalse($metadata['published']);
    }

    public function testExtractMetadataPublishedTrue(): void
    {
        $content = "---\npublished: true\n---";

        $metadata = $this->extractMetadata($content);

        $this->assertTrue($metadata['published']);
    }

    public function testExtractMetadataPublishedFalse(): void
    {
        $content = "---\npublished: false\n---";

        $metadata = $this->extractMetadata($content);

        $this->assertFalse($metadata['published']);
    }

    public function testExtractMetadataPublishedCaseInsensitive(): void
    {
        $content = "---\npublished: TRUE\n---";

        $metadata = $this->extractMetadata($content);

        // PHP strtolower converts "TRUE" to "true"
        $this->assertTrue($metadata['published']);
    }
}

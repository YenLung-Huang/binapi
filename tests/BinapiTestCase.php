<?php

declare(strict_types=1);

namespace Binapi\Tests;

use Binapi\Tests\BinapiPluginTest;
use PHPUnit\Framework\TestCase;

/**
 * Base test case with helper methods.
 */
abstract class BinapiTestCase extends TestCase
{
    /**
     * Simulate rate limit check logic.
     *
     * @param array<string, array<int, int>> $store
     * @param string $clientIp
     * @param int $maxRequests
     * @param int $windowSeconds
     * @return bool True if allowed, false if rate limited
     */
    protected function simulateRateLimitCheck(
        array &$store,
        string $clientIp,
        int $maxRequests,
        int $windowSeconds
    ): bool {
        $now = time();
        $key = $clientIp;

        if (!isset($store[$key])) {
            $store[$key] = [];
        }

        // Remove expired entries
        $store[$key] = array_values(array_filter(
            $store[$key],
            static fn(int $timestamp): bool => ($now - $timestamp) < $windowSeconds
        ));

        // Check limit
        if (count($store[$key]) >= $maxRequests) {
            return false;
        }

        // Record this request
        $store[$key][] = $now;
        return true;
    }

    /**
     * Simulate path traversal validation.
     *
     * @param string $pagesDir
     * @param string $folder
     * @return bool True if valid, false if traversal detected
     */
    protected function isValidFolderPath(string $pagesDir, string $folder): bool
    {
        // Reject path containing ..
        if (preg_match('#(^|[\\/])\.\.($|[\\/])#', $folder)) {
            return false;
        }

        $folderPath = $pagesDir . '/' . $folder;

        // Check if realpath resolves within pagesDir
        if (file_exists($folderPath)) {
            $resolved = realpath($folderPath);
            if ($resolved === false || strpos($resolved, $pagesDir) !== 0) {
                return false;
            }
            return true;
        }

        // For non-existing paths, check parent
        if (realpath(dirname($folderPath)) !== false) {
            $parentResolved = realpath(dirname($folderPath));
            if ($parentResolved !== false && strpos($parentResolved, $pagesDir) !== 0 && $parentResolved !== $pagesDir) {
                return false;
            }
        }

        return true;
    }

    /**
     * Extract frontmatter from markdown content.
     *
     * @param string $content
     * @return array{frontmatter: string, body: string}
     */
    protected function parseFrontmatter(string $content): array
    {
        $frontmatter = '';
        $body = $content;

        if (preg_match('/^---\s*[\r\n]+(.+?)\s*---/s', $content, $matches)) {
            $frontmatter = $matches[1];
            $body = substr($content, strlen($matches[0]));
        }

        return ['frontmatter' => $frontmatter, 'body' => $body];
    }

    /**
     * Extract article metadata from markdown.
     *
     * @param string $raw
     * @return array{title: string|null, date: string|null, published: bool}
     */
    protected function extractMetadata(string $raw): array
    {
        $title = null;
        $date = null;
        $published = true;

        if (preg_match('/^---\s*[\r\n]+(.*?)[\r\n]+---/s', $raw, $fm)) {
            $block = $fm[1];

            if (preg_match('/^title:\s*["\']?(.+?)["\']?\s*$/m', $block, $m)) {
                $title = trim($m[1], "\"' ");
            }
            if (preg_match('/^date:\s*["\']?(.+?)["\']?\s*$/m', $block, $m)) {
                $date = trim($m[1], "\"' ");
            }
            if (preg_match('/^published:\s*(true|false)\s*$/mi', $block, $m)) {
                $published = strtolower($m[1]) === 'true';
            }
        }

        return ['title' => $title, 'date' => $date, 'published' => $published];
    }

    /**
     * Sanitize filename.
     *
     * @param string $filename
     * @return string
     */
    protected function sanitizeFilename(string $filename): string
    {
        return (string) preg_replace('/[^a-z0-9\-_\.]/i', '', $filename);
    }

    /**
     * Safe title escaping for frontmatter.
     *
     * @param string $title
     * @return string
     */
    protected function escapeTitle(string $title): string
    {
        return htmlspecialchars($title, ENT_QUOTES, 'UTF-8');
    }
}

<?php

declare(strict_types=1);

namespace Grav\Plugin;

use Grav\Common\Plugin;

/**
 * binapi Plugin v2
 * Provides secure API endpoints for Grav CMS with dual authentication,
 * rate limiting, image compression, webhook notifications, and API versioning.
 */
class BinapiPlugin extends Plugin
{
    /** @var array<string, array<int, int>> In-memory rate limit storage */
    private static array $rateLimitStore = [];

    /**
     * Register plugin events.
     *
     * @return array<string, array{0: string, 1: int}>
     */
    public static function getSubscribedEvents(): array
    {
        return [
            'onPluginsInitialized' => ['onPluginsInitialized', 0],
        ];
    }

    /**
     * Initialize plugin on frontend only.
     */
    public function onPluginsInitialized(): void
    {
        if ($this->isAdmin()) {
            return;
        }

        $this->enable([
            'onPageInitialized' => ['onPageInitialized', 0],
        ]);
    }

    /**
     * Handle API endpoints and authentication.
     */
    public function onPageInitialized(): void
    {
        $uri = $this->grav['uri'];
        $route = rtrim($uri->path(), '/');

        // Only handle /binapi/* routes
        if (strpos($route, '/binapi/') !== 0) {
            return;
        }

        // Enforce authentication if enabled in config
        if ($this->config->get('plugins.binapi.require_auth', true)) {
            $this->authenticateRequest();
        }

        // Rate limiting check
        $this->checkRateLimit();

        $method = $_SERVER['REQUEST_METHOD'];

        // API v1 endpoints (preferred)
        $v1Endpoints = [
            'POST' => [
                '/binapi/v1/create-article' => 'handleCreateArticle',
                '/binapi/v1/update-article' => 'handleUpdateArticle',
                '/binapi/v1/patch-article' => 'handlePatchArticle',
                '/binapi/v1/upload-image' => 'handleUploadImage',
            ],
            'PATCH' => [
                '/binapi/v1/patch-article' => 'handlePatchArticle',
            ],
            'DELETE' => [
                '/binapi/v1/delete-article' => 'handleDeleteArticle',
            ],
            'GET' => [
                '/binapi/v1/list-articles' => 'handleListArticles',
            ],
        ];

        // Legacy endpoints (deprecated, map to v1 handlers)
        $legacyEndpoints = [
            'POST' => [
                '/binapi/create-article' => 'handleCreateArticle',
                '/binapi/update-article' => 'handleUpdateArticle',
                '/binapi/upload-image' => 'handleUploadImage',
            ],
            'DELETE' => [
                '/binapi/delete-article' => 'handleDeleteArticle',
            ],
            'GET' => [
                '/binapi/list-articles' => 'handleListArticles',
            ],
        ];

        $endpoints = array_merge_recursive($v1Endpoints, $legacyEndpoints);

        if (!isset($endpoints[$method][$route])) {
            $this->sendJson(['error' => 'Not found'], 404);
        }

        // Permission checks for v1 routes
        if (strpos($route, '/binapi/v1/') === 0) {
            if ($route === '/binapi/v1/create-article' && !$this->config->get('plugins.binapi.allow_article_creation', true)) {
                $this->sendJson(['error' => 'Article creation is disabled'], 403);
            }
            if ($route === '/binapi/v1/upload-image' && !$this->config->get('plugins.binapi.allow_image_upload', true)) {
                $this->sendJson(['error' => 'Image upload is disabled'], 403);
            }
            if ($route === '/binapi/v1/delete-article' && !$this->config->get('plugins.binapi.allow_article_deletion', false)) {
                $this->sendJson(['error' => 'Article deletion is disabled'], 403);
            }
        }

        $handler = $endpoints[$method][$route];
        $this->$handler();
    }

    /**
     * Authenticate request using Bearer token or Grav session.
     */
    private function authenticateRequest(): void
    {
        $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
        $apiToken = $this->config->get('plugins.binapi.api_token');

        // Token-based authentication
        if ($apiToken && strpos($authHeader, 'Bearer ') === 0) {
            $token = substr($authHeader, 7);
            if (hash_equals((string) $apiToken, $token)) {
                return; // Valid token
            }
        }

        // Session-based authentication
        /** @var object $user */
        $user = $this->grav['user'];
        if ($user->authenticated) {
            return; // Valid session
        }

        $this->sendJson(['error' => 'Authentication required'], 401);
    }

    /**
     * Check rate limit for incoming request.
     */
    private function checkRateLimit(): void
    {
        $clientIp = $this->getClientIp();
        $maxRequests = (int) $this->config->get('plugins.binapi.rate_limit_requests', 60);
        $windowSeconds = (int) $this->config->get('plugins.binapi.rate_limit_window_seconds', 60);

        $now = time();
        $key = $clientIp;

        // Initialize or clean old entries
        if (!isset(self::$rateLimitStore[$key])) {
            self::$rateLimitStore[$key] = [];
        }

        // Remove expired entries
        self::$rateLimitStore[$key] = array_filter(
            self::$rateLimitStore[$key],
            static fn(int $timestamp): bool => ($now - $timestamp) < $windowSeconds
        );

        // Check limit
        if (count(self::$rateLimitStore[$key]) >= $maxRequests) {
            $this->sendJson([
                'error' => 'Rate limit exceeded',
                'retry_after' => $windowSeconds,
            ], 429);
        }

        // Record this request
        self::$rateLimitStore[$key][] = $now;
    }

    /**
     * Get client IP address.
     *
     * @return string
     */
    private function getClientIp(): string
    {
        $headers = ['HTTP_X_FORWARDED_FOR', 'HTTP_CLIENT_IP', 'REMOTE_ADDR'];
        foreach ($headers as $header) {
            if (!empty($_SERVER[$header])) {
                $ip = $_SERVER[$header];
                // X-Forwarded-For can contain multiple IPs
                if (strpos($ip, ',') !== false) {
                    $ip = trim(explode(',', $ip)[0]);
                }
                return filter_var($ip, FILTER_VALIDATE_IP) ? $ip : '0.0.0.0';
            }
        }
        return '0.0.0.0';
    }

    /**
     * Resolve and validate a folder path under user://pages to prevent path traversal.
     *
     * @param string $pagesDir Absolute path to user/pages
     * @param string $folder  Relative folder path
     * @param bool   $mustExist Whether the folder must already exist
     * @return string Validated absolute folder path
     */
    private function resolveFolder(string $pagesDir, string $folder, bool $mustExist = true): string
    {
        $folderPath = $pagesDir . '/' . $folder;

        if ($mustExist) {
            $resolved = realpath($folderPath);
            if ($resolved === false || strpos($resolved, $pagesDir) !== 0) {
                $this->sendJson(['error' => 'Invalid folder path'], 400);
            }
            return $resolved;
        }

        // For non-existing paths, validate that the canonical form stays within pagesDir
        // Normalize /../ etc. without requiring the path to exist
        $normalized = $pagesDir . '/' . preg_replace('#(^|/)\.\.(/|$)#', '/', $folder);
        if (realpath(dirname($folderPath)) !== false) {
            $parentResolved = realpath(dirname($folderPath));
            if ($parentResolved !== false && strpos($parentResolved, $pagesDir) !== 0 && $parentResolved !== $pagesDir) {
                $this->sendJson(['error' => 'Invalid folder path'], 400);
            }
        }

        // Extra safety: reject any path containing ..
        if (preg_match('#(^|[\\/])\.\.($|[\\/])#', $folder)) {
            $this->sendJson(['error' => 'Invalid folder path'], 400);
        }

        return $folderPath;
    }

    /**
     * Trigger webhook notification if enabled.
     *
     * @param string $event Event type (article_created, article_updated, article_deleted)
     * @param array<string, mixed> $data Event data
     */
    private function triggerWebhook(string $event, array $data): void
    {
        if (!$this->config->get('plugins.binapi.webhook_enabled', false)) {
            return;
        }

        $webhookUrl = $this->config->get('plugins.binapi.webhook_url', '');
        if (empty($webhookUrl)) {
            return;
        }

        $payload = json_encode([
            'event' => $event,
            'timestamp' => date('c'),
            'data' => $data,
        ]);

        // Fire and forget - don't block the response
        $ch = curl_init($webhookUrl);
        if ($ch !== false) {
            curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $payload,
                CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT_MS => 2000,
            ]);
            curl_exec($ch);
            curl_close($ch);
        }
    }

    /**
     * Handle article creation via API.
     */
    private function handleCreateArticle(): void
    {
        $data = $this->getJsonInput();
        $folder = $data['folder'] ?? $this->config->get('plugins.binapi.default_folder', 'blog');
        $filename = $data['filename'] ?? 'item.md';
        $content = $data['content'] ?? '';
        $title = $data['title'] ?? null;

        // Extract title from frontmatter if not provided
        if (!$title && preg_match('/^---\s*[\r\n]+title:\s*["\']?([^"\']+)["\']?/mi', $content, $matches)) {
            $title = trim($matches[1]);
        }

        if (!$folder || !$title) {
            $this->sendJson(['error' => 'Missing folder or title'], 400);
        }

        /** @var string $pagesDir */
        $pagesDir = $this->grav['locator']->findResource('user://pages', true);

        // Create folder if allowed and doesn't exist
        $folderPath = $pagesDir . '/' . $folder;
        if (!file_exists($folderPath)) {
            // Validate path before creating
            $this->resolveFolder($pagesDir, $folder, false);

            if ($this->config->get('plugins.binapi.allow_folder_creation', true)) {
                if (!mkdir($folderPath, 0755, true)) {
                    $this->sendJson(['error' => 'Failed to create article folder'], 500);
                }
            } else {
                $this->sendJson(['error' => 'Folder does not exist and creation is disabled'], 400);
            }
        } else {
            $this->resolveFolder($pagesDir, $folder);
            $folderPath = (string) realpath($folderPath);
        }

        $mdFile = $folderPath . '/' . $filename;
        if (file_exists($mdFile)) {
            $this->sendJson(['error' => 'Article file already exists'], 409);
        }

        // Add frontmatter if missing
        if (preg_match('/^---/', $content)) {
            $writeContent = $content;
        } else {
            $safeTitle = htmlspecialchars($title, ENT_QUOTES, 'UTF-8');
            $frontmatter = "---\ntitle: \"{$safeTitle}\"\npublished: true\n---\n";
            $writeContent = $frontmatter . $content;
        }

        if (!file_put_contents($mdFile, $writeContent)) {
            $this->sendJson(['error' => 'Failed to write article file'], 500);
        }

        // Trigger webhook
        $this->triggerWebhook('article_created', [
            'folder' => $folder,
            'filename' => $filename,
            'title' => $title,
        ]);

        $this->sendJson(['success' => true, 'message' => 'Article created']);
    }

    /**
     * Handle article update via API (full replace).
     */
    private function handleUpdateArticle(): void
    {
        $data = $this->getJsonInput();
        $folder = $data['folder'] ?? null;
        $filename = $data['filename'] ?? 'post.md';
        $newContent = $data['content'] ?? null;
        $newTitle = $data['title'] ?? null;

        if (!$folder) {
            $this->sendJson(['error' => 'Missing folder'], 400);
        }

        if ($newContent === null && $newTitle === null) {
            $this->sendJson(['error' => 'No content or title provided to update'], 400);
        }

        /** @var string $pagesDir */
        $pagesDir = $this->grav['locator']->findResource('user://pages', true);
        $folderPath = $this->resolveFolder($pagesDir, $folder);
        $mdFile = $folderPath . '/' . $filename;

        if (!file_exists($mdFile)) {
            $this->sendJson(['error' => 'Article file does not exist'], 404);
        }

        $existingContent = file_get_contents($mdFile) ?: '';

        // Determine if incoming content includes frontmatter
        $hasNewFrontmatter = ($newContent !== null && preg_match('/^---[\r\n]/', $newContent));

        if ($hasNewFrontmatter) {
            // Full replacement mode
            $writeContent = $newContent;
        } else {
            // Partial update mode
            $frontmatter = '';
            $body = $existingContent;

            if (preg_match('/^---\s*[\r\n]+(.+?)\s*---/s', $existingContent, $matches)) {
                $frontmatter = $matches[1];
                $body = substr($existingContent, strlen($matches[0]));
            }

            // Update title with proper escaping
            if ($newTitle !== null) {
                $safeTitle = htmlspecialchars($newTitle, ENT_QUOTES, 'UTF-8');
                if (preg_match('/^title:\s*["\']?[^"\']+["\']?/m', $frontmatter)) {
                    $frontmatter = preg_replace(
                        '/^title:\s*["\']?[^"\']+["\']?/m',
                        'title: "' . $safeTitle . '"',
                        $frontmatter
                    );
                } else {
                    $frontmatter .= "\ntitle: \"{$safeTitle}\"";
                }
            }

            // Update body
            if ($newContent !== null) {
                $body = $newContent;
            }

            // Reconstruct
            $writeContent = "---\n" . $frontmatter . "\n---\n" . ltrim($body);
        }

        if (!file_put_contents($mdFile, $writeContent)) {
            $this->sendJson(['error' => 'Failed to write article file'], 500);
        }

        // Trigger webhook
        $this->triggerWebhook('article_updated', [
            'folder' => $folder,
            'filename' => $filename,
            'title' => $newTitle,
        ]);

        $this->sendJson(['success' => true, 'message' => 'Article updated']);
    }

    /**
     * Handle article PATCH via API (partial field update).
     */
    private function handlePatchArticle(): void
    {
        $data = $this->getJsonInput();
        $folder = $data['folder'] ?? null;
        $filename = $data['filename'] ?? 'post.md';

        if (!$folder) {
            $this->sendJson(['error' => 'Missing folder'], 400);
        }

        /** @var string $pagesDir */
        $pagesDir = $this->grav['locator']->findResource('user://pages', true);
        $folderPath = $this->resolveFolder($pagesDir, $folder);
        $mdFile = $folderPath . '/' . $filename;

        if (!file_exists($mdFile)) {
            $this->sendJson(['error' => 'Article file does not exist'], 404);
        }

        $existingContent = file_get_contents($mdFile) ?: '';

        // Parse frontmatter and body
        $frontmatter = '';
        $body = $existingContent;

        if (preg_match('/^---\s*[\r\n]+(.+?)\s*---/s', $existingContent, $matches)) {
            $frontmatter = $matches[1];
            $body = substr($existingContent, strlen($matches[0]));
        }

        // Fields that can be patched: title, content, date, published
        $patchableFields = ['title', 'content', 'date', 'published'];
        $hasPatch = false;

        foreach ($patchableFields as $field) {
            if (array_key_exists($field, $data)) {
                $hasPatch = true;
                $value = $data[$field];

                switch ($field) {
                    case 'title':
                        $safeTitle = htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
                        if (preg_match('/^title:\s*["\']?[^"\']+["\']?/m', $frontmatter)) {
                            $frontmatter = preg_replace(
                                '/^title:\s*["\']?[^"\']+["\']?/m',
                                'title: "' . $safeTitle . '"',
                                $frontmatter
                            );
                        } else {
                            $frontmatter .= "\ntitle: \"{$safeTitle}\"";
                        }
                        break;

                    case 'content':
                        $body = (string) $value;
                        break;

                    case 'date':
                        $safeDate = htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
                        if (preg_match('/^date:\s*["\']?[^"\']+["\']?/m', $frontmatter)) {
                            $frontmatter = preg_replace(
                                '/^date:\s*["\']?[^"\']+["\']?/m',
                                'date: "' . $safeDate . '"',
                                $frontmatter
                            );
                        } else {
                            $frontmatter .= "\ndate: \"{$safeDate}\"";
                        }
                        break;

                    case 'published':
                        $boolValue = (bool) $value;
                        if (preg_match('/^published:\s*(true|false)\s*$/mi', $frontmatter)) {
                            $frontmatter = preg_replace(
                                '/^published:\s*(true|false)\s*$/mi',
                                'published: ' . ($boolValue ? 'true' : 'false'),
                                $frontmatter
                            );
                        } else {
                            $frontmatter .= "\npublished: " . ($boolValue ? 'true' : 'false');
                        }
                        break;
                }
            }
        }

        if (!$hasPatch) {
            $this->sendJson(['error' => 'No valid fields provided to patch'], 400);
        }

        // Reconstruct
        $writeContent = "---\n" . $frontmatter . "\n---\n" . ltrim($body);

        if (!file_put_contents($mdFile, $writeContent)) {
            $this->sendJson(['error' => 'Failed to write article file'], 500);
        }

        // Trigger webhook
        $this->triggerWebhook('article_patched', [
            'folder' => $folder,
            'filename' => $filename,
            'patched_fields' => array_keys(array_filter(
                $data,
                static fn(string $key): bool => in_array($key, $patchableFields, true),
                ARRAY_FILTER_USE_KEY
            )),
        ]);

        $this->sendJson(['success' => true, 'message' => 'Article patched']);
    }

    /**
     * Handle image upload via API with compression.
     */
    private function handleUploadImage(): void
    {
        $data = $this->getJsonInput();
        $folder = $data['folder'] ?? $this->config->get('plugins.binapi.default_folder', 'blog');
        $imageData = $data['image'] ?? null;
        $filename = $data['filename'] ?? null;

        if (!$folder || !$imageData || !$filename) {
            $this->sendJson(['error' => 'Missing required parameters'], 400);
        }

        // Sanitize filename
        $filename = (string) preg_replace('/[^a-z0-9\-_\.]/i', '', $filename);

        // Extract and decode base64 image data
        if (!preg_match('/^data:image\/(\w+);base64,/', $imageData, $matches)) {
            $this->sendJson(['error' => 'Invalid image format'], 400);
        }
        $mimeTypeFromData = $matches[1];
        $imageData = base64_decode(substr($imageData, strpos($imageData, ',') + 1));
        if ($imageData === false) {
            $this->sendJson(['error' => 'Base64 decoding failed'], 400);
        }

        // Validate image type and size
        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mimeType = $finfo->buffer($imageData);
        $allowedTypes = ['image/jpeg', 'image/png', 'image/gif'];
        if (!in_array($mimeType, $allowedTypes, true)) {
            $this->sendJson(['error' => 'Unsupported file type'], 415);
        }
        if (strlen($imageData) > 5 * 1024 * 1024) {
            $this->sendJson(['error' => 'File size exceeds 5MB limit'], 413);
        }

        // Compress image if needed
        $imageData = $this->compressImage($imageData, $mimeType);

        /** @var string $pagesDir */
        $pagesDir = $this->grav['locator']->findResource('user://pages', true);
        $imageDir = $pagesDir . '/' . $folder;
        if (!file_exists($imageDir)) {
            $this->resolveFolder($pagesDir, $folder, false);

            if ($this->config->get('plugins.binapi.allow_folder_creation', true)) {
                if (!mkdir($imageDir, 0755, true)) {
                    $this->sendJson(['error' => 'Failed to create article directory'], 500);
                }
            } else {
                $this->sendJson(['error' => 'Folder does not exist and creation is disabled'], 400);
            }
        } else {
            $imageDir = $this->resolveFolder($pagesDir, $folder);
        }
        $filePath = $imageDir . '/' . $filename;
        if (file_exists($filePath)) {
            $this->sendJson(['error' => 'Image file exists'], 409);
        }
        if (!file_put_contents($filePath, $imageData)) {
            $this->sendJson(['error' => 'Failed to save image'], 500);
        }

        $this->sendJson([
            'success' => true,
            'url' => "/user/pages/{$folder}/{$filename}",
        ]);
    }

    /**
     * Compress image if wider than max width.
     *
     * @param string $imageData Raw image data
     * @param string $mimeType  MIME type
     * @return string Compressed image data
     */
    private function compressImage(string $imageData, string $mimeType): string
    {
        $maxWidth = (int) $this->config->get('plugins.binapi.image_max_width', 1920);
        $quality = (int) $this->config->get('plugins.binapi.image_quality', 85);

        // Get image dimensions without creating full image object
        $sizeInfo = @getimagesize('data://image/' . $mimeType . ';base64,' . base64_encode($imageData));
        if ($sizeInfo === false || ($sizeInfo[0] ?? 0) <= $maxWidth) {
            return $imageData; // No compression needed
        }

        $originalWidth = $sizeInfo[0];
        $originalHeight = $sizeInfo[1];
        $ratio = $originalHeight / $originalWidth;
        $newWidth = $maxWidth;
        $newHeight = (int) round($maxWidth * $ratio);

        // Create resource from data
        $source = @imagecreatefromstring($imageData);
        if ($source === false) {
            return $imageData;
        }

        // Create resized image
        $resized = imagecreatetruecolor($newWidth, $newHeight);
        if ($resized === false) {
            imagedestroy($source);
            return $imageData;
        }

        // Handle transparency for PNG/GIF
        if ($mimeType === 'image/png' || $mimeType === 'image/gif') {
            imagealphablending($resized, false);
            imagesavealpha($resized, true);
        }

        imagecopyresampled($resized, $source, 0, 0, 0, 0, $newWidth, $newHeight, $originalWidth, $originalHeight);

        // Output to buffer
        $output = match ($mimeType) {
            'image/jpeg' => imagejpeg($resized, null, $quality),
            'image/png' => imagepng($resized, null, (int) round(9 * (100 - $quality) / 100)),
            'image/gif' => imagegif($resized, null),
            default => false,
        };

        if ($output === false) {
            imagedestroy($source);
            imagedestroy($resized);
            return $imageData;
        }

        $compressedData = ob_get_clean();

        imagedestroy($source);
        imagedestroy($resized);

        // Return compressed data or original if compression failed
        if ($compressedData === false || strlen($compressedData) >= strlen($imageData)) {
            return $imageData;
        }

        return $compressedData;
    }

    /**
     * Handle article deletion via API.
     */
    private function handleDeleteArticle(): void
    {
        $data = $this->getJsonInput();
        $folder = $data['folder'] ?? null;
        $filename = $data['filename'] ?? 'post.md';

        if (!$folder) {
            $this->sendJson(['error' => 'Missing folder'], 400);
        }

        /** @var string $pagesDir */
        $pagesDir = $this->grav['locator']->findResource('user://pages', true);
        $folderPath = $this->resolveFolder($pagesDir, $folder);
        $mdFile = $folderPath . '/' . $filename;

        if (!file_exists($mdFile)) {
            $this->sendJson(['error' => 'Article file does not exist'], 404);
        }

        if (!unlink($mdFile)) {
            $this->sendJson(['error' => 'Failed to delete article file'], 500);
        }

        // Trigger webhook
        $this->triggerWebhook('article_deleted', [
            'folder' => $folder,
            'filename' => $filename,
        ]);

        $this->sendJson(['success' => true, 'message' => 'Article deleted']);
    }

    /**
     * Handle article listing via API.
     */
    private function handleListArticles(): void
    {
        /** @var string $pagesDir */
        $pagesDir = $this->grav['locator']->findResource('user://pages', true);
        $defaultFolder = (string) $this->config->get('plugins.binapi.default_folder', '');

        $requestedFolder = $_GET['folder'] ?? $defaultFolder;
        $recursive = filter_var($_GET['recursive'] ?? false, FILTER_VALIDATE_BOOLEAN);

        // Resolve and validate the base directory
        $baseDir = $pagesDir;
        if ($requestedFolder !== '') {
            $baseDir = $this->resolveFolder($pagesDir, $requestedFolder);
        }

        if (!is_dir($baseDir)) {
            $this->sendJson(['error' => 'Folder does not exist'], 404);
        }

        $articles = $this->scanArticles($baseDir, $pagesDir, $recursive);

        $this->sendJson([
            'success' => true,
            'folder' => $requestedFolder,
            'count' => count($articles),
            'articles' => $articles,
        ]);
    }

    /**
     * Recursively scan a directory for .md files and return article metadata.
     *
     * @param string $dir       Absolute path to scan
     * @param string $pagesDir  Absolute path to /user/pages/
     * @param bool   $recursive Whether to descend into subdirectories
     * @return array<int, array{folder: string, filename: string, title: string|null, date: string|null, published: bool}>
     */
    private function scanArticles(string $dir, string $pagesDir, bool $recursive): array
    {
        $articles = [];

        foreach (new \DirectoryIterator($dir) as $item) {
            if ($item->isDot()) {
                continue;
            }

            if ($item->isDir() && $recursive) {
                $articles = array_merge(
                    $articles,
                    $this->scanArticles($item->getPathname(), $pagesDir, true)
                );
                continue;
            }

            if (!$item->isFile() || $item->getExtension() !== 'md') {
                continue;
            }

            $relativeFolder = ltrim(str_replace($pagesDir, '', $item->getPath()), '/');
            $filename = $item->getFilename();
            $raw = file_get_contents($item->getPathname()) ?: '';

            // Extract frontmatter block
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

            $articles[] = [
                'folder' => $relativeFolder,
                'filename' => $filename,
                'title' => $title,
                'date' => $date,
                'published' => $published,
            ];
        }

        // Sort by folder + filename for deterministic output
        usort($articles, static fn(array $a, array $b): int =>
            strcmp($a['folder'] . '/' . $a['filename'], $b['folder'] . '/' . $b['filename'])
        );

        return $articles;
    }

    /**
     * Parse JSON input safely.
     *
     * @return array<string, mixed>
     */
    private function getJsonInput(): array
    {
        $input = file_get_contents('php://input');
        if ($input === false || $input === '') {
            return [];
        }
        $data = json_decode($input, true);
        return is_array($data) ? $data : [];
    }

    /**
     * Send JSON response and exit.
     *
     * @param mixed $data
     */
    private function sendJson(mixed $data, int $status = 200): never
    {
        http_response_code($status);
        header('Content-Type: application/json');
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit();
    }
}

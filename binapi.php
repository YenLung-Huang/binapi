<?php

namespace Grav\Plugin;

use Grav\Common\Plugin;

/**
 * binapi Plugin
 * Provides secure API endpoints for Grav CMS with dual authentication support.
 */
class BinapiPlugin extends Plugin
{
    /**
     * Register plugin events.
     */
    public static function getSubscribedEvents()
    {
        return [
            'onPluginsInitialized' => ['onPluginsInitialized', 0]
        ];
    }

    /**
     * Initialize plugin on frontend only.
     */
    public function onPluginsInitialized()
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
    public function onPageInitialized()
    {
        // Enforce authentication if enabled in config
        if ($this->config->get('plugins.binapi.require_auth')) {
            $this->authenticateRequest();
        }

        $uri = $this->grav['uri'];
        $route = rtrim($uri->path(), '/');

        // Article creation endpoint
        if ($route === '/binapi/create-article' && $_SERVER['REQUEST_METHOD'] === 'POST') {
            $this->handleCreateArticle();
        }

        // Article update endpoint
        if ($route === '/binapi/update-article' && $_SERVER['REQUEST_METHOD'] === 'POST') {
            $this->handleUpdateArticle();
        }

        // Image upload endpoint
        if ($route === '/binapi/upload-image' && $_SERVER['REQUEST_METHOD'] === 'POST') {
            // Check if image upload is allowed
            if (!$this->config->get('plugins.binapi.allow_image_upload', true)) {
                $this->sendJson(['error' => 'Image upload is disabled'], 403);
            }
            $this->handleUploadImage();
        }

        // List articles endpoint
        if ($route === '/binapi/list-articles' && $_SERVER['REQUEST_METHOD'] === 'GET') {
            $this->handleListArticles();
        }
    }

    /**
     * Authenticate request using Bearer token or Grav session.
     */
    private function authenticateRequest()
    {
        $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
        $apiToken = $this->config->get('plugins.binapi.api_token');

        // Token-based authentication
        if ($apiToken && strpos($authHeader, 'Bearer ') === 0) {
            $token = substr($authHeader, 7);
            if (hash_equals($apiToken, $token)) {
                return; // Valid token
            }
        }

        // Session-based authentication
        $user = $this->grav['user'];
        if ($user->authenticated) {
            return; // Valid session
        }

        $this->sendJson(['error' => 'Authentication required'], 401);
    }

    /**
     * Handle article creation via API.
     */
    private function handleCreateArticle()
    {
        $data = json_decode(file_get_contents('php://input'), true);
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

        $pagesDir = $this->grav['locator']->findResource('user://pages', true);
        $folderPath = $pagesDir . '/' . $folder;

        // Create folder if allowed and doesn't exist
        if (!file_exists($folderPath)) {
            if ($this->config->get('plugins.binapi.allow_folder_creation', true)) {
                if (!mkdir($folderPath, 0755, true)) {
                    $this->sendJson(['error' => 'Failed to create article folder'], 500);
                }
            } else {
                $this->sendJson(['error' => 'Folder does not exist and creation is disabled'], 400);
            }
        }

        $mdFile = $folderPath . '/' . $filename;
        if (file_exists($mdFile)) {
            $this->sendJson(['error' => 'Article file already exists'], 409);
        }

        // Add frontmatter if missing
        if (preg_match('/^---/', $content)) {
            $writeContent = $content;
        } else {
            $frontmatter = "---\ntitle: \"$title\"\npublished: true\n---\n";
            $writeContent = $frontmatter . $content;
        }

        if (!file_put_contents($mdFile, $writeContent)) {
            $this->sendJson(['error' => 'Failed to write article file'], 500);
        }

        $this->sendJson(['success' => true, 'message' => 'Article created']);
    }

    /**
     * Handle article update via API.
     */
    private function handleUpdateArticle()
    {
        $data = json_decode(file_get_contents('php://input'), true);
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

        $pagesDir = $this->grav['locator']->findResource('user://pages', true);
        $folderPath = $pagesDir . '/' . $folder;
        $mdFile = $folderPath . '/' . $filename;

        if (!file_exists($mdFile)) {
            $this->sendJson(['error' => 'Article file does not exist'], 404);
        }

        $existingContent = file_get_contents($mdFile);

        // 判斷傳入的 content 是否已經包含 frontmatter
        $hasNewFrontmatter = ($newContent !== null && preg_match('/^---[\r\n]/', $newContent));

        if ($hasNewFrontmatter) {
            // 方案 A：完全替換模式（傳入完整 content + frontmatter）
            $writeContent = $newContent;
        } else {
            // 方案 B：部分更新模式（只傳 title 或不帶 frontmatter 的 content）

            // 解析現有的 frontmatter
            $frontmatter = '';
            $body = $existingContent;

            if (preg_match('/^---\s*[\r\n]+(.+?)\s*---/s', $existingContent, $matches)) {
                $frontmatter = $matches[1];
                $body = substr($existingContent, strlen($matches[0]));
            }

            // 更新 title
            if ($newTitle !== null) {
                if (preg_match('/^title:\s*["\']?([^"\']+)["\']?/m', $frontmatter, $matches)) {
                    $frontmatter = preg_replace('/^title:\s*["\']?[^"\']+["\']?/m', 'title: "' . addslashes($newTitle) . '"', $frontmatter);
                } else {
                    $frontmatter .= "\ntitle: \"$newTitle\"";
                }
            }

            // 更新 body
            if ($newContent !== null) {
                $body = $newContent;
            }

            // 重組
            $writeContent = "---\n" . $frontmatter . "\n---\n" . ltrim($body);
        }

        if (!file_put_contents($mdFile, $writeContent)) {
            $this->sendJson(['error' => 'Failed to write article file'], 500);
        }

        $this->sendJson(['success' => true, 'message' => 'Article updated']);
    }

    /**
     * Handle image upload via API.
     */
    private function handleUploadImage()
    {
        $data = json_decode(file_get_contents('php://input'), true);
        $folder = $data['folder'] ?? $this->config->get('plugins.binapi.default_folder', 'blog');
        $imageData = $data['image'] ?? null;
        $filename = $data['filename'] ?? null;

        if (!$folder || !$imageData || !$filename) {
            $this->sendJson(['error' => 'Missing required parameters'], 400);
        }

        // Sanitize filename
        $filename = preg_replace('/[^a-z0-9\-_\.]/i', '', $filename);

        // Extract and decode base64 image data
        if (!preg_match('/^data:image\/(\w+);base64,/', $imageData, $matches)) {
            $this->sendJson(['error' => 'Invalid image format'], 400);
        }
        $imageData = base64_decode(substr($imageData, strpos($imageData, ',') + 1));
        if ($imageData === false) {
            $this->sendJson(['error' => 'Base64 decoding failed'], 400);
        }

        // Validate image type and size
        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mimeType = $finfo->buffer($imageData);
        $allowedTypes = ['image/jpeg', 'image/png', 'image/gif'];
        if (!in_array($mimeType, $allowedTypes)) {
            $this->sendJson(['error' => 'Unsupported file type'], 415);
        }
        if (strlen($imageData) > 5 * 1024 * 1024) {
            $this->sendJson(['error' => 'File size exceeds 5MB limit'], 413);
        }

        // Save image in article folder
        $imageDir = $this->grav['locator']->findResource('user://pages', true) . '/' . $folder;
        if (!file_exists($imageDir)) {
            if ($this->config->get('plugins.binapi.allow_folder_creation', true)) {
                if (!mkdir($imageDir, 0755, true)) {
                    $this->sendJson(['error' => 'Failed to create article directory'], 500);
                }
            } else {
                $this->sendJson(['error' => 'Folder does not exist and creation is disabled'], 400);
            }
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
            'url' => "/user/pages/$folder/$filename"
        ]);
    }

    /**
     * Handle article listing via API.
     *
     * GET /binapi/list-articles?folder=01.blog
     *
     * Query parameters:
     *   folder    (optional) Subfolder path under /user/pages/. Defaults to default_folder.
     *             If not provided and no default_folder configured, lists all immediate
     *             subfolders of /user/pages/.
     *   recursive (optional, default: false) Set to "1" or "true" to recurse into subfolders.
     *
     * Returns a JSON array of article objects:
     *   {
     *     "folder":   "01.blog/2026-04-my-post",
     *     "filename": "post.zh-tw.md",
     *     "title":    "My Post Title",         // extracted from frontmatter, null if missing
     *     "date":     "2026-04-01",             // extracted from frontmatter, null if missing
     *     "published": true                    // extracted from frontmatter, defaults to true
     *   }
     */
    private function handleListArticles()
    {
        $pagesDir = $this->grav['locator']->findResource('user://pages', true);
        $defaultFolder = $this->config->get('plugins.binapi.default_folder', '');

        $requestedFolder = $_GET['folder'] ?? $defaultFolder;
        $recursive = filter_var($_GET['recursive'] ?? false, FILTER_VALIDATE_BOOLEAN);

        // Resolve and validate the base directory
        $baseDir = $pagesDir;
        if ($requestedFolder !== '') {
            // Prevent path traversal
            $baseDir = realpath($pagesDir . '/' . $requestedFolder);
            if ($baseDir === false || strpos($baseDir, $pagesDir) !== 0) {
                $this->sendJson(['error' => 'Invalid folder path'], 400);
            }
        }

        if (!is_dir($baseDir)) {
            $this->sendJson(['error' => 'Folder does not exist'], 404);
        }

        $articles = $this->scanArticles($baseDir, $pagesDir, $recursive);

        $this->sendJson([
            'success' => true,
            'folder'  => $requestedFolder,
            'count'   => count($articles),
            'articles' => $articles,
        ]);
    }

    /**
     * Recursively (or not) scan a directory for .md files and return article metadata.
     *
     * @param string $dir       Absolute path to scan
     * @param string $pagesDir  Absolute path to /user/pages/ (used to compute relative folder)
     * @param bool   $recursive Whether to descend into subdirectories
     * @return array
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
            $filename       = $item->getFilename();
            $raw            = file_get_contents($item->getPathname());

            // Extract frontmatter block
            $title     = null;
            $date      = null;
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
                'folder'    => $relativeFolder,
                'filename'  => $filename,
                'title'     => $title,
                'date'      => $date,
                'published' => $published,
            ];
        }

        // Sort by folder + filename for deterministic output
        usort($articles, fn($a, $b) =>
            strcmp($a['folder'] . '/' . $a['filename'], $b['folder'] . '/' . $b['filename'])
        );

        return $articles;
    }

    /**
     * Send JSON response and exit.
     */
    private function sendJson($data, $status = 200)
    {
        http_response_code($status);
        header('Content-Type: application/json');
        echo json_encode($data);
        exit();
    }
}

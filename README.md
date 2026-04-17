---
# binapi Grav Plugin v2

A secure, configurable REST API plugin for [Grav CMS](https://getgrav.org), enabling automated article and image creation via authenticated endpoints. Supports API versioning, rate limiting, image compression, and webhook notifications.

---

## Table of Contents

- [Features](#features)
- [Requirements](#requirements)
- [Installation](#installation)
- [Configuration](#configuration)
- [Authentication](#authentication)
- [API Reference](#api-reference)
  - [v1 Endpoints (Preferred)](#v1-endpoints-preferred)
  - [Legacy Endpoints (Deprecated)](#legacy-endpoints-deprecated)
  - [PATCH Partial Update](#patch-partial-update)
- [Webhook Notifications](#webhook-notifications)
- [Rate Limiting](#rate-limiting)
- [Image Compression](#image-compression)
- [Integration Example: n8n](#integration-example-n8n)
- [Security Notes](#security-notes)
- [Changelog](#changelog)
- [Contributing](#contributing)
- [License](#license)

---

## Features

- **API Versioning** — v1 endpoints (`/binapi/v1/*`) with legacy fallback
- **Create articles** via REST API
- **Update articles** via REST API (full replace or partial)
- **PATCH articles** — partial field updates (title, content, date, published)
- **Upload images** via REST API with automatic compression
- **Delete articles** via REST API
- **List articles** via REST API with recursive support
- **Dual authentication** — Bearer token or Grav session
- **Rate limiting** — configurable requests per minute per IP
- **Image compression** — auto-resize images > max width
- **Webhook notifications** — trigger on article events
- **Path traversal protection**
- Configurable permissions for folder, article, and image creation
- Designed for automation (n8n, Zapier, AI agents, custom scripts)

---

## Requirements

- [Grav CMS](https://getgrav.org) v1.7 or higher
- PHP 7.3+ (strict_types enabled)
- PHP GD extension (for image compression)
- (Recommended) [Login Plugin](https://github.com/getgrav/grav-plugin-login) for session authentication

---

## Installation

1. **Download or Clone:**

```bash
git clone https://github.com/YenLung-Huang/binapi.git
```

2. **Move to Plugins Folder:**
   Place the `binapi` directory into your site's `user/plugins/` directory.

3. **Enable the Plugin:**
   - Via Admin Panel: Go to Plugins > binapi > Enable.
   - Or in `user/config/plugins/binapi.yaml`:

```yaml
enabled: true
```

---

## Configuration

All plugin settings available via Admin Panel or `user/config/plugins/binapi.yaml`:

```yaml
enabled: true
require_auth: true
api_token: "your_secure_token_here"
default_folder: "02.blog"
allow_folder_creation: true
allow_article_creation: true
allow_image_upload: true
allow_article_deletion: false

# Rate limiting (requests per window per IP)
rate_limit_requests: 60
rate_limit_window_seconds: 60

# Image compression
image_max_width: 1920
image_quality: 85

# Webhook notifications
webhook_enabled: false
webhook_url: ""
```

---

## Authentication

binapi supports two authentication methods:

### 1. Bearer Token (Recommended for API)

Set `require_auth: true` and provide a strong `api_token`:

```
Authorization: Bearer your_secure_token_here
```

### 2. Grav Session

If the user is logged into Grav admin, sessions are automatically authenticated.

---

## API Reference

### v1 Endpoints (Preferred)

| Method | Endpoint | Description |
|--------|----------|-------------|
| POST | `/binapi/v1/create-article` | Create new article |
| POST | `/binapi/v1/update-article` | Update article (full replace) |
| PATCH | `/binapi/v1/patch-article` | Partial update (single fields) |
| POST | `/binapi/v1/upload-image` | Upload image with compression |
| DELETE | `/binapi/v1/delete-article` | Delete article |
| GET | `/binapi/v1/list-articles` | List articles in folder |

### Legacy Endpoints (Deprecated)

| Method | Endpoint | Maps To |
|--------|----------|---------|
| POST | `/binapi/create-article` | `/binapi/v1/create-article` |
| POST | `/binapi/update-article` | `/binapi/v1/update-article` |
| POST | `/binapi/upload-image` | `/binapi/v1/upload-image` |
| DELETE | `/binapi/delete-article` | `/binapi/v1/delete-article` |
| GET | `/binapi/list-articles` | `/binapi/v1/list-articles` |

---

### Create Article

**POST** `/binapi/v1/create-article`

```json
{
  "folder": "02.blog",
  "filename": "my-post.md",
  "title": "My Article Title",
  "content": "Markdown content here..."
}
```

**Response:**

```json
{
  "success": true,
  "message": "Article created"
}
```

---

### Update Article (Full Replace)

**POST** `/binapi/v1/update-article`

```json
{
  "folder": "02.blog",
  "filename": "my-post.md",
  "title": "Updated Title",
  "content": "Complete new content with frontmatter..."
}
```

Or partial update (just title or just content):

```json
{
  "folder": "02.blog",
  "filename": "my-post.md",
  "title": "New Title Only"
}
```

---

### PATCH Partial Update

**PATCH** `/binapi/v1/patch-article`

Update individual fields without replacing entire content:

```json
{
  "folder": "02.blog",
  "filename": "my-post.md",
  "title": "New Title",
  "published": false
}
```

**Available PATCH fields:**

| Field | Type | Description |
|-------|------|-------------|
| `title` | string | Article title |
| `content` | string | Article body (markdown) |
| `date` | string | Publication date (YYYY-MM-DD) |
| `published` | boolean | Published status |

---

### Upload Image

**POST** `/binapi/v1/upload-image`

```json
{
  "folder": "02.blog/my-post",
  "filename": "hero.jpg",
  "image": "data:image/jpeg;base64,/9j/4AAQSkZJRg..."
}
```

**Response:**

```json
{
  "success": true,
  "url": "/user/pages/02.blog/my-post/hero.jpg"
}
```

Images larger than `image_max_width` are automatically compressed.

---

### Delete Article

**DELETE** `/binapi/v1/delete-article`

```json
{
  "folder": "02.blog",
  "filename": "my-post.md"
}
```

**Response:**

```json
{
  "success": true,
  "message": "Article deleted"
}
```

---

### List Articles

**GET** `/binapi/v1/list-articles?folder=02.blog&recursive=true`

| Query Param | Default | Description |
|-------------|---------|-------------|
| `folder` | default_folder config | Subfolder path |
| `recursive` | false | Include subfolders |

**Response:**

```json
{
  "success": true,
  "folder": "02.blog",
  "count": 2,
  "articles": [
    {
      "folder": "02.blog/my-post",
      "filename": "post.zh-tw.md",
      "title": "My Post Title",
      "date": "2026-04-01",
      "published": true
    }
  ]
}
```

---

## Webhook Notifications

When `webhook_enabled: true` and `webhook_url` is configured, the plugin sends POST requests after article operations.

**Webhook Payload:**

```json
{
  "event": "article_created",
  "timestamp": "2026-04-17T22:00:00+08:00",
  "data": {
    "folder": "02.blog",
    "filename": "my-post.md",
    "title": "My Article"
  }
}
```

**Events:**

- `article_created`
- `article_updated`
- `article_patched`
- `article_deleted`

---

## Rate Limiting

Protects against abuse with configurable limits:

```yaml
rate_limit_requests: 60      # Max requests per window
rate_limit_window_seconds: 60 # Window size in seconds
```

When exceeded, returns HTTP 429:

```json
{
  "error": "Rate limit exceeded",
  "retry_after": 60
}
```

---

## Image Compression

Images are automatically compressed when they exceed `image_max_width`:

```yaml
image_max_width: 1920   # Max width in pixels
image_quality: 85       # JPEG quality (0-100)
```

- Supports JPEG, PNG, GIF
- Preserves transparency for PNG/GIF
- Only resizes if wider than max width
- Falls back to original if compression would increase size

---

## Integration Example: n8n

### 1. Create Article Workflow

```
HTTP Request (POST) → binapi/create-article → ...
```

**n8n HTTP Request Node:**

- Method: POST
- URL: `https://yourblog.com/binapi/v1/create-article`
- Headers:
  - `Authorization: Bearer your_token_here`
  - `Content-Type: application/json`
- Body:

```json
{
  "folder": "02.blog",
  "filename": "{{ $json.filename }}.md",
  "title": "{{ $json.title }}",
  "content": "{{ $json.content }}"
}
```

---

## Security Notes

- **Always use HTTPS** in production
- **Strong API tokens** — use random 32+ character strings
- **Path traversal protection** — all folder paths validated
- **Filename sanitization** — special characters removed
- **MIME type validation** — file types checked before save
- **Rate limiting** — prevents brute force attacks
- **Proper escaping** — XSS prevention with `htmlspecialchars()`

---

## Changelog

### v2.0.0

- Added `declare(strict_types=1)` for type safety
- API versioning with `/binapi/v1/*` endpoints
- PATCH endpoint for partial article updates
- Rate limiting (configurable requests/minute per IP)
- Image compression (auto-resize with GD)
- Webhook notifications on article events
- Replaced `addslashes()` with `htmlspecialchars()`
- Comprehensive PHPDoc and type hints

---

## Contributing

Pull requests welcome. Please test changes thoroughly.

---

## License

MIT License

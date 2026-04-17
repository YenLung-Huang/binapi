# Tests

This directory contains PHPUnit tests for the binapi plugin.

## Running Tests

```bash
# Install dependencies
composer install

# Run all tests
composer test

# Or run phpunit directly
vendor/bin/phpunit
```

## Test Structure

| File | Purpose |
|------|---------|
| `BinapiTestCase.php` | Base class with helper methods |
| `RateLimitingTest.php` | Rate limiting logic tests |
| `PathTraversalTest.php` | Path traversal protection tests |
| `FrontmatterTest.php` | Frontmatter parsing tests |
| `SecurityTest.php` | XSS protection and sanitization tests |

## Test Coverage

- **Rate Limiting** — Request counting, window cleanup, IP isolation
- **Path Traversal** — Double-dot, URL-encoded, backslash attacks
- **Frontmatter** — Parsing, metadata extraction, defaults
- **Security** — Filename sanitization, XSS prevention with htmlspecialchars

## Notes

These are unit tests that simulate plugin logic without requiring a full Grav installation. They test the pure functions and helper methods.

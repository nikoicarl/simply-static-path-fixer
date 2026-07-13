# simply-static-path-fixer

A small PHP script that scans a WordPress **Simply Static** export for absolute (root-relative) paths that break when the export is opened directly from disk or hosted somewhere other than a domain root — and optionally fixes them in place.

## The problem

Simply Static's "offline use" / relative URL setting doesn't always apply cleanly to every file in an export. When it doesn't, you end up with HTML and CSS referencing assets like:

```html
<link rel="stylesheet" href="/wp-content/themes/your-theme/style.css">
<img src="/files/2024/01/photo.jpg" srcset="/files/2024/01/photo-300x200.jpg 300w">
```

```css
background: url(/wp-content/themes/your-theme/images/bg.png);
```

These paths only resolve correctly if the site is served from an actual domain root. If you:

- open `index.html` directly from disk (`file://`)
- host the export in a subdirectory (`yoursite.com/archive/`)
- open it on a different domain than the one it was exported for

...then the browser looks for `/wp-content/...` starting from the wrong place, and your theme, images, and internal links silently break — even though every file is actually sitting right there in the export folder.

## What this script does

It recursively scans every `.html` and `.css` file in an export folder and finds:

- `href`, `src`, and `action` attributes starting with `/`
- `srcset` attributes (including multiple comma-separated responsive image URLs)
- bare `href="/"` / `action="/"` (home links, search forms)
- CSS `url(/...)` references (fonts, background images)

With `--fix`, it rewrites each one to the correct **relative** path, automatically calculating the right number of `../` based on how deeply nested each file is — a page at the root gets no prefix, a page one folder deep gets `../`, two folders deep gets `../../`, and so on.

It intentionally leaves `/wp-json/` and `/xmlrpc.php` references alone, since those are live API endpoints with nothing to point to in a static export anyway (search and similar dynamic features won't work in the free version of Simply Static regardless).

## Requirements

- PHP 7.4+ (no dependencies, no Composer, single file)

## Usage

Report issues only, no files changed:

```bash
php check-static-paths.php /path/to/extracted-export-folder
```

Fix everything found, in place:

```bash
php check-static-paths.php /path/to/extracted-export-folder --fix
```

Exit codes: `0` = clean (or everything was fixed), `1` = issues found and not fixed. Useful if you want to drop this into a script or CI step.

### Example output

```
Scanned 1185 HTML/CSS files under: /path/to/export
Mode: REPORT ONLY (add --fix to rewrite files in place)
============================================================

⚠️  Found absolute paths in HTML files:

  hydrology-lab/index.html
    - href="/wp-content/themes/twentyeleven/style.css?ver=20260520"
    - href="/hydrology-lab/"
    - href="/feed/"
    - href="/comments/feed/"
    - href="/hydrology-lab/feed/"
    ... and 28 more

============================================================
Total files with issues: 14

Re-run with --fix to rewrite these files in place:
  php check-static-paths.php '/path/to/export' --fix
```

After running with `--fix`:

```
✅ No absolute path issues found. Export looks clean.
```

## Notes

- Always keep a backup of your export before running with `--fix` — it rewrites files in place and doesn't create backups itself.
- This is a targeted fix for the absolute-path problem specifically. It won't fix unrelated export issues (missing pages from a crawl failure, archived multisite subsites blocking the crawler, incomplete pagination, etc.) — those need to be addressed in Simply Static / WordPress directly before re-exporting.
- Tested against exports from Simply Static's free tier on WordPress Multisite, but the underlying logic is generic enough to work with any static HTML export that has this kind of absolute-path issue.

## License

MIT

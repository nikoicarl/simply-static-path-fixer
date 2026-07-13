<?php
/**
 * check-static-paths.php
 *
 * Scans (and optionally fixes) a Simply Static export folder for absolute
 * root-relative paths (href="/...", src="/...", srcset="/...", action="/...",
 * bare href="/", and CSS url(/...)) that break when the export is opened
 * directly from disk or hosted in a subdirectory instead of a domain root.
 *
 * Usage:
 *   php check-static-paths.php /path/to/exported-folder            (report only)
 *   php check-static-paths.php /path/to/exported-folder --fix       (report + fix in place)
 *
 * Exit code 0 = clean (or all fixed), 1 = issues found and not fixed.
 */

if ($argc < 2) {
    echo "Usage: php check-static-paths.php /path/to/exported-folder [--fix]\n";
    exit(1);
}

$root = rtrim($argv[1], '/');
$fixMode = in_array('--fix', array_slice($argv, 2));

if (!is_dir($root)) {
    echo "Error: '$root' is not a valid directory.\n";
    exit(1);
}

function shouldSkip($path) {
    return (strpos($path, '/wp-json') === 0 || strpos($path, '/xmlrpc') === 0);
}

// Depth-aware relative prefix for a file, based on its location under $root.
function getPrefix($root, $filePath) {
    $rel = substr($filePath, strlen($root) + 1);
    $dir = dirname($rel);
    if ($dir === '.') {
        return '';
    }
    $depth = count(explode('/', $dir));
    return str_repeat('../', $depth);
}

$htmlIssues = [];
$cssIssues  = [];
$filesScanned = 0;
$filesFixed = 0;

$attrPattern   = '/(href|src|action)="(\/(?!\/)[^"]*)"/i';
$srcsetPattern = '/srcset="([^"]+)"/i';
$cssUrlPattern = '/url\((["\']?)\/(?!\/)([^"\')]+)\1\)/i';
$bareRootPattern = '/(href|action)="\/"/i';

$rii = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)
);

foreach ($rii as $file) {
    $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
    $filePath = $file->getPathname();
    $relativePath = substr($filePath, strlen($root) + 1);

    if ($ext === 'html' || $ext === 'htm') {
        $filesScanned++;
        $content = file_get_contents($filePath);
        $original = $content;
        $lineIssues = [];
        $prefix = getPrefix($root, $filePath);

        // href/src/action="/..."
        if (preg_match_all($attrPattern, $content, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $m) {
                if (!shouldSkip($m[2])) {
                    $lineIssues[] = "{$m[1]}=\"{$m[2]}\"";
                }
            }
        }
        if ($fixMode) {
            $content = preg_replace_callback($attrPattern, function ($m) use ($prefix) {
                if (shouldSkip($m[2])) {
                    return $m[0];
                }
                $newPath = $prefix . ltrim($m[2], '/');
                return "{$m[1]}=\"{$newPath}\"";
            }, $content);
        }

        // srcset="..., ..."
        if (preg_match_all($srcsetPattern, $content, $matches)) {
            foreach ($matches[1] as $srcsetValue) {
                foreach (explode(',', $srcsetValue) as $part) {
                    $part = trim($part);
                    if (preg_match('#^/(?!/)#', $part)) {
                        $lineIssues[] = "srcset entry: \"$part\"";
                    }
                }
            }
        }
        if ($fixMode) {
            $content = preg_replace_callback($srcsetPattern, function ($m) use ($prefix) {
                $parts = explode(',', $m[1]);
                $fixed = [];
                foreach ($parts as $part) {
                    $part = trim($part);
                    if (preg_match('#^/(?!/)([^\s]+)(\s+.*)?$#', $part, $pm)) {
                        $descriptor = isset($pm[2]) ? $pm[2] : '';
                        $fixed[] = $prefix . $pm[1] . $descriptor;
                    } else {
                        $fixed[] = $part;
                    }
                }
                return 'srcset="' . implode(', ', $fixed) . '"';
            }, $content);
        }

        // bare href="/" or action="/"
        if (preg_match_all($bareRootPattern, $content, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $m) {
                $lineIssues[] = "{$m[1]}=\"/\" (bare root link)";
            }
        }
        if ($fixMode) {
            $homePrefix = $prefix === '' ? './' : $prefix;
            $content = preg_replace($bareRootPattern, '$1="' . $homePrefix . '"', $content);
        }

        if (!empty($lineIssues)) {
            $htmlIssues[$relativePath] = $lineIssues;
        }

        if ($fixMode && $content !== $original) {
            file_put_contents($filePath, $content);
            $filesFixed++;
        }
    }

    if ($ext === 'css') {
        $filesScanned++;
        $content = file_get_contents($filePath);
        $original = $content;
        $lineIssues = [];
        $prefix = getPrefix($root, $filePath);

        if (preg_match_all($cssUrlPattern, $content, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $m) {
                $lineIssues[] = "url(/{$m[2]})";
            }
        }
        if ($fixMode) {
            $content = preg_replace_callback($cssUrlPattern, function ($m) use ($prefix) {
                $quote = $m[1];
                $newPath = $prefix . $m[2];
                return "url({$quote}{$newPath}{$quote})";
            }, $content);
        }

        if (!empty($lineIssues)) {
            $cssIssues[$relativePath] = $lineIssues;
        }

        if ($fixMode && $content !== $original) {
            file_put_contents($filePath, $content);
            $filesFixed++;
        }
    }
}

echo "Scanned $filesScanned HTML/CSS files under: $root\n";
if ($fixMode) {
    echo "Mode: FIX (files rewritten in place)\n";
} else {
    echo "Mode: REPORT ONLY (add --fix to rewrite files in place)\n";
}
echo str_repeat('=', 60) . "\n\n";

if (empty($htmlIssues) && empty($cssIssues)) {
    echo "✅ No absolute path issues found. Export looks clean.\n";
    exit(0);
}

$label = $fixMode ? "Found and fixed" : "Found";

if (!empty($htmlIssues)) {
    echo "⚠️  $label absolute paths in HTML files:\n\n";
    foreach ($htmlIssues as $path => $issues) {
        echo "  $path\n";
        $unique = array_slice(array_unique($issues), 0, 5);
        foreach ($unique as $issue) {
            echo "    - $issue\n";
        }
        if (count($issues) > 5) {
            echo "    ... and " . (count($issues) - 5) . " more\n";
        }
        echo "\n";
    }
}

if (!empty($cssIssues)) {
    echo "⚠️  $label absolute paths in CSS files (url()):\n\n";
    foreach ($cssIssues as $path => $issues) {
        echo "  $path\n";
        $unique = array_slice(array_unique($issues), 0, 5);
        foreach ($unique as $issue) {
            echo "    - $issue\n";
        }
        if (count($issues) > 5) {
            echo "    ... and " . (count($issues) - 5) . " more\n";
        }
        echo "\n";
    }
}

echo str_repeat('=', 60) . "\n";
echo "Total files with issues: " . (count($htmlIssues) + count($cssIssues)) . "\n";

if ($fixMode) {
    echo "Total files rewritten: $filesFixed\n";
    echo "\nRun the script again without --fix to confirm everything is now clean.\n";
    exit(0);
} else {
    echo "\nRe-run with --fix to rewrite these files in place:\n";
    echo "  php " . basename(__FILE__) . " " . escapeshellarg($root) . " --fix\n";
    exit(1);
}

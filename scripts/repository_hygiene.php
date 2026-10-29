<?php

declare(strict_types=1);

$root = dirname(__DIR__);
chdir($root);
$output = shell_exec('git ls-files -z');
if (!is_string($output) || $output === '') {
    fwrite(STDERR, "No indexed files available for the publication check.\n");
    exit(1);
}

$files = array_filter(explode("\0", $output));
$failures = [];
foreach ($files as $file) {
    $base = basename($file);
    $forbidden = preg_match('~(^|/)(vendor|storage|backups|production-sync|\.ssh|\.idea|\.vscode)(/|$)~i', $file)
        || ($base !== '.env.example' && preg_match('/^\.env(?:\.|$)/', $base))
        || in_array($base, ['auth.json', '.DS_Store', 'known_hosts', 'id_rsa', 'id_ed25519'], true)
        || str_starts_with($base, '._')
        || preg_match('/\.(pem|key|p12|pfx|log|dump|sqlite3?|zip|tar|gz|bak)$/i', $base)
        || (str_ends_with($file, '.sql') && !str_starts_with($file, 'database/'));
    if ($forbidden || is_link($file) || !is_file($file)) {
        $failures[] = "$file: forbidden artifact or symlink";
        continue;
    }
    $content = file_get_contents($file);
    if ($content === false || str_contains($content, "\0")) {
        $failures[] = "$file: unexpected binary or unreadable content";
        continue;
    }
    if (preg_match('~/(?:Users|home)/[a-zA-Z0-9_.-]+/~', $content)) {
        $failures[] = "$file: local user path";
    }
    // Composer retains public upstream author metadata for reproducible dependencies.
    if ($file !== 'composer.lock' && preg_match_all('/[a-zA-Z0-9._%+-]+@([a-zA-Z0-9.-]+\.[a-zA-Z]{2,})/', $content, $matches)) {
        foreach ($matches[1] as $domain) {
            if (!preg_match('/(^|\.)example\.(invalid|com|org|net)$/i', $domain)) {
                $failures[] = "$file: non-example email address";
                break;
            }
        }
    }
}

if ($failures !== []) {
    fwrite(STDERR, implode("\n", array_unique($failures)) . "\n");
    exit(1);
}
echo 'Repository hygiene passed for ' . count($files) . " indexed files.\n";

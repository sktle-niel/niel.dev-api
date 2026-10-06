<?php
// Tiny .env loader (no Composer needed).
// Reads KEY=VALUE lines from a file and exposes them via getenv()/$_ENV.
// Real server environment variables always win — we never override them.

function load_env(string $file): void
{
    if (!is_file($file) || !is_readable($file)) {
        return;
    }

    foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#') {
            continue;
        }
        if (strpos($line, '=') === false) {
            continue;
        }

        [$key, $val] = explode('=', $line, 2);
        $key = trim($key);
        $val = trim($val);

        // Strip a single pair of surrounding quotes, if present.
        $len = strlen($val);
        if ($len >= 2 && ($val[0] === '"' || $val[0] === "'") && $val[$len - 1] === $val[0]) {
            $val = substr($val, 1, -1);
        }

        if ($key === '' || getenv($key) !== false) {
            continue; // don't override anything the server already set
        }

        putenv("$key=$val");
        $_ENV[$key] = $val;
    }
}

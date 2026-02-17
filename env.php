<?php
/**
 * Минималистичный загрузчик .env без зависимостей.
 * Поддерживает строки вида KEY=VALUE, пробелы вокруг =, комментарии # и пустые строки.
 * Значения в кавычках "..." и '...' поддерживаются (кавычки снимаются).
 */
function loadEnvFile(string $path): void {
    if (!is_file($path) || !is_readable($path)) return;

    $lines = file($path, FILE_IGNORE_NEW_LINES);
    if ($lines === false) return;

    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) continue;

        $pos = strpos($line, '=');
        if ($pos === false) continue;

        $key = trim(substr($line, 0, $pos));
        $val = trim(substr($line, $pos + 1));
        if ($key === '') continue;

        // remove inline comments for unquoted values: KEY=value # comment
        if ($val !== '' && $val[0] !== '"' && $val[0] !== "'") {
            $hashPos = strpos($val, ' #');
            if ($hashPos !== false) $val = rtrim(substr($val, 0, $hashPos));
        }

        // strip quotes
        if ((str_starts_with($val, '"') && str_ends_with($val, '"')) || (str_starts_with($val, "'") && str_ends_with($val, "'"))) {
            $val = substr($val, 1, -1);
        }

        // do not override existing env
        if (getenv($key) !== false) continue;

        putenv($key . '=' . $val);
        $_ENV[$key] = $val;
    }
}

function env(string $key, $default = null) {
    $v = getenv($key);
    if ($v === false || $v === '') return $default;
    return $v;
}


<?php

namespace App\Support;

/**
 * Safe in-place editing of the project's .env file.
 *
 * The installer uses this during the Database and Site Config steps to
 * persist credentials and settings without losing other lines (comments,
 * unrelated config, blank lines for readability).
 *
 * Behavior:
 *   - If a key exists, its line is replaced (regex-anchored to start of line).
 *   - If a key doesn't exist, it's appended at the end.
 *   - Values containing whitespace, '#', or quotes are auto-quoted.
 *   - Empty values are written as `KEY=` (no value).
 *   - The file is written atomically via tmp + rename to avoid partial writes.
 */
class EnvFileWriter
{
    /**
     * @param array<string, string|int|bool|null> $values
     */
    public static function set(array $values, ?string $path = null): void
    {
        $path = $path ?: base_path('.env');

        // Read existing content (or initialize from .env.example if .env is missing)
        if (file_exists($path)) {
            $content = file_get_contents($path);
        } elseif (file_exists($path . '.example')) {
            $content = file_get_contents($path . '.example');
        } else {
            $content = '';
        }

        foreach ($values as $key => $value) {
            $escaped = self::escapeValue(self::stringify($value));
            $line = "{$key}={$escaped}";

            // Regex matches lines like KEY=value or KEY="value" or KEY=
            $pattern = '/^' . preg_quote($key, '/') . '=.*$/m';

            if (preg_match($pattern, $content)) {
                $content = preg_replace($pattern, $line, $content);
            } else {
                // Append, ensuring exactly one newline before
                if ($content !== '' && !str_ends_with($content, "\n")) {
                    $content .= "\n";
                }
                $content .= $line . "\n";
            }
        }

        // Atomic write
        $tmp = $path . '.tmp.' . bin2hex(random_bytes(4));
        file_put_contents($tmp, $content);
        @chmod($tmp, 0644);
        rename($tmp, $path);
    }

    public static function get(string $key, ?string $path = null): ?string
    {
        $path = $path ?: base_path('.env');
        if (!file_exists($path)) {
            return null;
        }
        $content = file_get_contents($path);
        if (preg_match('/^' . preg_quote($key, '/') . '=(.*)$/m', $content, $m)) {
            return self::unescapeValue($m[1]);
        }
        return null;
    }

    protected static function stringify(mixed $value): string
    {
        if ($value === null) return '';
        if (is_bool($value)) return $value ? 'true' : 'false';
        return (string) $value;
    }

    protected static function escapeValue(string $value): string
    {
        if ($value === '') {
            return '';
        }
        // Quote if contains whitespace, hash, or quotes
        if (preg_match('/[\s#"\']/', $value)) {
            return '"' . str_replace('"', '\\"', $value) . '"';
        }
        return $value;
    }

    protected static function unescapeValue(string $raw): string
    {
        $raw = trim($raw);
        if (strlen($raw) >= 2 && $raw[0] === '"' && substr($raw, -1) === '"') {
            return str_replace('\\"', '"', substr($raw, 1, -1));
        }
        return $raw;
    }
}

<?php

namespace Tests\Feature\Support;

final class StaticReferenceScanner
{
    private static ?array $files = null;

    private static array $codeCache = [];

    public static function files(): array
    {
        if (self::$files !== null) {
            return self::$files;
        }

        $root = base_path();
        $files = [];

        foreach (['app', 'extensions', 'resources/views'] as $dir) {
            $path = $root.'/'.$dir;
            if (!is_dir($path)) {
                continue;
            }

            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS)
            );

            foreach ($iterator as $file) {
                if ($file->isFile() && str_ends_with($file->getFilename(), '.php')) {
                    $files[] = $file->getPathname();
                }
            }
        }

        sort($files);

        return self::$files = $files;
    }

    public static function stripCommentsForScanning(string $content): string
    {
        return self::stripComments($content);
    }

    private static function codeContent(string $path): ?string
    {
        if (isset(self::$codeCache[$path])) {
            return self::$codeCache[$path];
        }

        $raw = file_get_contents($path);
        if ($raw === false) {
            return null;
        }

        return self::$codeCache[$path] = self::stripComments($raw);
    }

    private static function stripComments(string $content): string
    {
        $len = strlen($content);
        $out = '';
        $i = 0;

        while ($i < $len) {
            $c = $content[$i];
            $two = substr($content, $i, 2);

            if ($c === "'" || $c === '"') {
                $quote = $c;
                $out .= $c;
                $i++;
                while ($i < $len) {
                    $ch = $content[$i];
                    if ($ch === '\\' && $i + 1 < $len) {
                        $out .= $ch.$content[$i + 1];
                        $i += 2;
                        continue;
                    }
                    $out .= $ch;
                    $i++;
                    if ($ch === $quote) {
                        break;
                    }
                }
                continue;
            }

            if (substr($content, $i, 4) === '{{--') {
                $end = strpos($content, '--}}', $i + 4);
                $blankTo = $end === false ? $len : $end + 4;
                $out .= preg_replace('/[^\n]/', ' ', substr($content, $i, $blankTo - $i));
                $i = $blankTo;
                continue;
            }

            if ($two === '/*') {
                $end = strpos($content, '*/', $i + 2);
                $blankTo = $end === false ? $len : $end + 2;
                $out .= preg_replace('/[^\n]/', ' ', substr($content, $i, $blankTo - $i));
                $i = $blankTo;
                continue;
            }

            if ($two === '//') {
                $end = strpos($content, "\n", $i + 2);
                $blankTo = $end === false ? $len : $end;
                $out .= preg_replace('/[^\n]/', ' ', substr($content, $i, $blankTo - $i));
                $i = $blankTo;
                continue;
            }

            $out .= $c;
            $i++;
        }

        return $out;
    }

    public static function scan(string $kind, string $startPattern): array
    {
        $entries = [];

        foreach (self::files() as $path) {
            $content = self::codeContent($path);
            if ($content === null) {
                continue;
            }

            if (!preg_match_all($startPattern, $content, $matches, PREG_OFFSET_CAPTURE)) {
                continue;
            }

            foreach ($matches[0] as [$matchedText, $offset]) {
                $argStart = $offset + strlen($matchedText);
                [$value, $dynamic] = self::readLiteralArgument($content, $argStart);

                $entries[] = [
                    'kind' => $kind,
                    'name' => $value,
                    'dynamic' => $dynamic,
                    'file' => self::relative($path),
                    'line' => self::lineOf($content, $offset),
                    'snippet' => trim(substr($content, $offset, 80)),
                ];
            }
        }

        return $entries;
    }

    public static function scanMultiArg(string $kind, string $startPattern): array
    {
        $entries = [];

        foreach (self::files() as $path) {
            $content = self::codeContent($path);
            if ($content === null) {
                continue;
            }

            if (!preg_match_all($startPattern, $content, $matches, PREG_OFFSET_CAPTURE)) {
                continue;
            }

            foreach ($matches[0] as [$matchedText, $offset]) {
                $pos = $offset + strlen($matchedText);
                $line = self::lineOf($content, $offset);
                $snippet = trim(substr($content, $offset, 80));
                $foundAny = false;

                while (true) {
                    [$value, $dynamic, $pos] = self::readLiteralArgumentAdvancing($content, $pos);

                    if ($value === null && $dynamic) {
                        if (!$foundAny) {
                            $entries[] = [
                                'kind' => $kind, 'name' => null, 'dynamic' => true,
                                'file' => self::relative($path), 'line' => $line, 'snippet' => $snippet,
                            ];
                        }
                        break;
                    }

                    $foundAny = true;
                    $entries[] = [
                        'kind' => $kind, 'name' => $value, 'dynamic' => $dynamic,
                        'file' => self::relative($path), 'line' => $line, 'snippet' => $snippet,
                    ];

                    while ($pos < strlen($content) && ctype_space($content[$pos])) {
                        $pos++;
                    }
                    if (($content[$pos] ?? '') === ',') {
                        $pos++;
                        continue;
                    }
                    break;
                }
            }
        }

        return $entries;
    }

    public static function readLiteralArgument(string $content, int $pos): array
    {
        [$value, $dynamic, ] = self::readLiteralArgumentAdvancing($content, $pos);

        return [$value, $dynamic];
    }

    private static function readLiteralArgumentAdvancing(string $content, int $pos): array
    {
        $len = strlen($content);

        while ($pos < $len && ctype_space($content[$pos])) {
            $pos++;
        }

        if ($pos >= $len) {
            return [null, true, $pos];
        }

        $quote = $content[$pos];
        if ($quote !== "'" && $quote !== '"') {
            return [null, true, $pos];
        }

        $i = $pos + 1;
        while ($i < $len) {
            if ($content[$i] === '\\') {
                $i += 2;
                continue;
            }
            if ($content[$i] === $quote) {
                break;
            }
            $i++;
        }

        if ($i >= $len) {
            return [null, true, $len];
        }

        $value = substr($content, $pos + 1, $i - $pos - 1);
        $afterQuote = $i + 1;

        $j = $afterQuote;
        while ($j < $len && ctype_space($content[$j])) {
            $j++;
        }

        $dynamic = ($content[$j] ?? '') === '.';

        return [$value, $dynamic, $afterQuote];
    }

    private static function lineOf(string $content, int $offset): int
    {
        return substr_count($content, "\n", 0, $offset) + 1;
    }

    private static function relative(string $absolutePath): string
    {
        return ltrim(str_replace(base_path(), '', $absolutePath), '/');
    }
}

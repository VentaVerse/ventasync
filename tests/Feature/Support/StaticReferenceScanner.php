<?php

namespace Tests\Feature\Support;

/**
 * Grep-by-regex-with-arg-parsing over app/, extensions/ and resources/views/,
 * shared by ViewResolutionSweepTest and RouteNameSweepTest.
 *
 * The premise: a view/route NAME is safe to check statically only when it is
 * a single, unbroken string literal. `view('foo')` is checkable. `view($x)`
 * and `view('foo.' . $x)` are not - the real value only exists at runtime.
 * This class draws that line mechanically (readLiteralArgument()) so both
 * sweep tests apply the exact same rule and can't quietly diverge on what
 * counts as "dynamic".
 *
 * Deliberately NOT a TestCase and has no "Test" suffix, so PHPUnit's
 * directory-based discovery (suffix Test.php) never picks it up as a test of
 * its own - same convention as the other classes in this folder.
 */
final class StaticReferenceScanner
{
    /** @var string[]|null */
    private static ?array $files = null;

    /** @var array<string, string> path => comment-stripped content, cached */
    private static array $codeCache = [];

    /**
     * Every .php file (which also matches .blade.php) under the three trees
     * the plan names: app/, extensions/, resources/views/. Cached for the
     * lifetime of the process since both sweep tests re-scan several times.
     *
     * @return string[] absolute paths
     */
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

    /**
     * Public wrapper around the comment-stripping pass, for callers that
     * have their own raw string to scan rather than a path under files()
     * (the self-test fixtures being the one example: they scan a fixture
     * file directly, and their own explanatory header comments would
     * otherwise be fair game for the very same construct they document,
     * e.g. a comment that says "a route() call" containing the literal
     * text a naive scan would treat as one).
     */
    public static function stripCommentsForScanning(string $content): string
    {
        return self::stripComments($content);
    }

    /**
     * A file's contents with every comment blanked out (replaced with
     * spaces, newlines kept so line numbers stay correct) - // and /* … *\/
     * PHP comments plus Blade's {{-- … --}} comments. Without this, a
     * docblock that merely TALKS about `route(` or `view(` (there are
     * several in this codebase, describing why a call is safe) gets
     * scanned as if it were the call itself. String literals are left
     * completely untouched: only comment DELIMITERS outside a string turn
     * comment bodies into code.
     *
     * Cached per path for the life of the process; both sweep tests scan
     * the same trees for several different constructs each.
     */
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

    /**
     * Single-pass state machine: normal code, single/double-quoted PHP
     * strings (backslash-escape aware), `//` line comments, `/ * ... * /`
     * block comments (docblocks included), and Blade `{{-- ... --}}`
     * comments. Everything inside a comment is replaced with a space
     * except newlines, which are kept so byte offsets still map to the
     * right line number for reporting.
     *
     * No heredoc/nowdoc handling: this codebase does not use them (checked
     * across app/, extensions/, resources/views/ before relying on this).
     * A `#` is never treated as a comment starter - Blade/HTML templates
     * use it constantly for anchors and colors outside of any PHP context
     * this scanner understands, so treating it as PHP's alternate line
     * comment would blank out real markup between potential call sites.
     */
    private static function stripComments(string $content): string
    {
        $len = strlen($content);
        $out = '';
        $i = 0;

        while ($i < $len) {
            $c = $content[$i];
            $two = substr($content, $i, 2);

            if ($c === "'" || $c === '"') {
                // Copy the string verbatim, honouring backslash escapes, so
                // its contents can never be mistaken for a comment opener.
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

    /**
     * Scan every swept file for call sites of one construct (a function
     * call, method call, or Blade directive) and return each occurrence,
     * literal or not.
     *
     * $startPattern must be a PCRE matching everything up to and including
     * the opening paren and any whitespace right after it - the pattern
     * used for the `view(` helper, for example, matches "view(" not
     * preceded by "->" or a word character (so it skips View::make and
     * $x->view calls), followed by optional whitespace. The regex must NOT
     * capture a group; the argument itself is read separately so every
     * construct shares one literal/dynamic parser.
     *
     * @return array<int, array{kind:string, name:?string, dynamic:bool, file:string, line:int, snippet:string}>
     */
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

    /**
     * Same as scan(), but for routeIs('a', 'b', 'wildcard.*'), which accepts
     * any number of comma-separated string patterns. Each literal pattern in
     * the call becomes its own entry (kind stays the same, 'name' holds one
     * pattern each) so a caller can fnmatch() each independently.
     *
     * @return array<int, array{kind:string, name:?string, dynamic:bool, file:string, line:int, snippet:string}>
     */
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

                    // Advance past optional whitespace; a following comma
                    // means another argument, anything else ends the list
                    // (closing paren, or a second argument that is not a
                    // plain string, e.g. routeIs($patterns) with an array).
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

    /**
     * Read the string literal starting at $pos (skipping leading
     * whitespace). Returns [value, dynamic]. $value is null when the
     * argument is not a string literal at all (a variable, a method call,
     * etc). $dynamic is true when the argument cannot be trusted as a
     * complete, standalone name - either because it isn't a literal, or
     * because the literal is immediately concatenated with something else
     * (`'foo.' . $x`), which makes the literal fragment meaningless on its
     * own.
     *
     * @return array{0: ?string, 1: bool}
     */
    public static function readLiteralArgument(string $content, int $pos): array
    {
        [$value, $dynamic, ] = self::readLiteralArgumentAdvancing($content, $pos);

        return [$value, $dynamic];
    }

    /**
     * Same contract as readLiteralArgument(), but also returns the offset
     * just past the literal (and its closing quote) so a caller reading a
     * comma-separated argument list can continue from there. When the
     * argument is not a literal, the returned offset equals $pos (nothing
     * was consumed).
     *
     * @return array{0: ?string, 1: bool, 2: int}
     */
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
            // Not a string literal at all: a variable, a method call, an
            // array, string interpolation start, etc. Genuinely dynamic.
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
            // Unterminated string (should not happen in valid PHP source);
            // refuse to guess.
            return [null, true, $len];
        }

        $value = substr($content, $pos + 1, $i - $pos - 1);
        $afterQuote = $i + 1;

        $j = $afterQuote;
        while ($j < $len && ctype_space($content[$j])) {
            $j++;
        }

        // Concatenation immediately after the closing quote means the real
        // value is assembled at runtime; the literal fragment on its own
        // would be a false name.
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

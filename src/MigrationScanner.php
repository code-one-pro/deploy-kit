<?php

declare(strict_types=1);

namespace DeployKit;

/**
 * Flags statements in a migration's up() that can break code still running
 * against the old schema. Pure: takes source text, does no I/O.
 */
final class MigrationScanner
{
    /** Ordered: the first matching label wins for a line. */
    private const PATTERNS = [
        'dropColumn' => '/->\s*(?:dropColumns?|dropSoftDeletes(?:Tz)?|dropTimestamps(?:Tz)?|dropMorphs|dropRememberToken)\s*\(|Schema::\s*dropColumns\s*\(/',
        'renameColumn' => '/->\s*renameColumn\s*\(/',
        'rename' => '/Schema::\s*(?:connection\s*\(.*\)\s*->\s*)?rename\s*\(/',
        'drop' => '/Schema::\s*(?:connection\s*\(.*\)\s*->\s*)?drop(?:IfExists)?\s*\(/',
        'change' => '/->\s*change\s*\(/',
        'dropForeign' => '/->\s*dropForeign\s*\(/',
        'raw-sql' => '/DROP\s+COLUMN|DROP\s+TABLE|RENAME\s+COLUMN|RENAME\s+TO|ALTER\s+COLUMN/i',
    ];

    /** @return list<array{line:int, text:string, pattern:string}> */
    public function scan(string $source): array
    {
        $body = $this->upBody($source);
        if ($body === null) {
            return [];
        }

        [$code, $firstLine] = $body;
        $original = explode("\n", $this->bodyOriginal($source, $firstLine, substr_count($code, "\n")));
        $findings = [];

        foreach (explode("\n", $code) as $i => $codeLine) {
            foreach (self::PATTERNS as $label => $regex) {
                if (preg_match($regex, $codeLine) === 1) {
                    $findings[] = [
                        'line' => $firstLine + $i,
                        'text' => trim($original[$i] ?? $codeLine),
                        'pattern' => $label,
                    ];
                    break;
                }
            }
        }

        return $findings;
    }

    /**
     * The body of up() with comments blanked (newlines kept), and the 1-based
     * line where the body starts.
     *
     * @return array{string, int}|null
     */
    private function upBody(string $source): ?array
    {
        $tokens = token_get_all($source);
        $count = count($tokens);
        $line = 1;
        $lines = [];
        foreach ($tokens as $i => $token) {
            $lines[$i] = $line;
            $line += substr_count(is_array($token) ? $token[1] : $token, "\n");
        }

        for ($i = 0; $i < $count; $i++) {
            if (!is_array($tokens[$i]) || $tokens[$i][0] !== T_FUNCTION) {
                continue;
            }
            $j = $this->nextSignificant($tokens, $i + 1);
            if ($j === null || !is_array($tokens[$j]) || $tokens[$j][0] !== T_STRING || strtolower($tokens[$j][1]) !== 'up') {
                continue;
            }
            for ($k = $j + 1; $k < $count && $tokens[$k] !== '{'; $k++) {
                if ($tokens[$k] === ';') {
                    continue 2; // abstract / interface declaration, no body
                }
            }
            if ($k >= $count) {
                return null;
            }

            $depth = 1;
            $code = '';
            for ($m = $k + 1; $m < $count; $m++) {
                $token = $tokens[$m];
                $text = is_array($token) ? $token[1] : $token;
                if (is_array($token) && in_array($token[0], [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true)) {
                    $depth++;
                } elseif ($token === '{') {
                    $depth++;
                } elseif ($token === '}' && --$depth === 0) {
                    return [$code, $lines[$k]];
                }
                if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                    $text = str_repeat("\n", substr_count($text, "\n"));
                }
                $code .= $text;
            }

            return [$code, $lines[$k]];
        }

        return null;
    }

    private function bodyOriginal(string $source, int $firstLine, int $extraLines): string
    {
        $all = explode("\n", $source);

        return implode("\n", array_slice($all, $firstLine - 1, $extraLines + 1));
    }

    /** @param array<int, array{int, string, int}|string> $tokens */
    private function nextSignificant(array $tokens, int $from): ?int
    {
        for ($i = $from, $n = count($tokens); $i < $n; $i++) {
            if (is_array($tokens[$i]) && in_array($tokens[$i][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            return $i;
        }

        return null;
    }
}

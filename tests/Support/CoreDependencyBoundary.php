<?php

namespace Tests\Support;

/** Test-only direct PHP name/import check; no runtime policy or universal dependency framework. */
final class CoreDependencyBoundary
{
    public static function violations(string $source, array $allowed = []): array
    {
        $allowed = array_map('strtolower', $allowed);
        $tokens = token_get_all($source);
        [$imports, $aliases] = self::imports($tokens);
        $names = $imports;
        foreach ($tokens as $token) {
            if (! is_array($token) || ! in_array($token[0], [T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true)) {
                continue;
            }
            $name = ltrim($token[1], '\\');
            $parts = explode('\\', $name, 2);
            $alias = $aliases[strtolower($parts[0])] ?? null;
            $names[] = $token[0] !== T_NAME_FULLY_QUALIFIED && $alias !== null
                ? $alias.(isset($parts[1]) ? '\\'.$parts[1] : '') : $name;
        }
        $violations = [];
        foreach ($names as $name) {
            $normalized = strtolower($name);
            if (($normalized === 'app\\providers\\ads' || str_starts_with($normalized, 'app\\providers\\ads\\'))
                && ! in_array($normalized, $allowed, true)) {
                $violations[] = $name;
            }
        }

        return array_values(array_unique($violations));
    }

    private static function imports(array $tokens): array
    {
        $names = $aliases = [];
        foreach ($tokens as $index => $token) {
            if (! is_array($token) || $token[0] !== T_USE) {
                continue;
            }
            $statement = '';
            for ($i = $index + 1; $i < count($tokens) && $tokens[$i] !== ';'; $i++) {
                // Closure captures are not imports. Comments cannot change namespace resolution.
                if ($tokens[$i] === '(') {
                    continue 2;
                }
                if (is_array($tokens[$i]) && in_array($tokens[$i][0], [T_COMMENT, T_DOC_COMMENT], true)) {
                    continue;
                }
                $statement .= is_array($tokens[$i]) ? $tokens[$i][1] : $tokens[$i];
            }
            $statement = preg_replace('/^(?:function|const)\s+/i', '', trim($statement));
            $prefix = '';
            if (str_contains($statement, '{')) {
                [$prefix, $statement] = explode('{', $statement, 2);
                $prefix = rtrim(trim($prefix), '\\').'\\';
                $statement = rtrim(trim($statement), '}');
            }
            foreach (explode(',', $statement) as $member) {
                $parts = preg_split('/\s+as\s+/i', preg_replace('/^(?:function|const)\s+/i', '', trim($member)));
                $name = ltrim(preg_replace('/\s+/', '', $prefix.$parts[0]), '\\');
                $names[] = $name;
                $leaf = strrchr($name, '\\');
                $aliases[strtolower($parts[1] ?? ($leaf === false ? $name : substr($leaf, 1)))] = $name;
            }
        }

        return [$names, $aliases];
    }
}

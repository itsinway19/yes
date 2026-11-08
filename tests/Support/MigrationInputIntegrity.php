<?php

namespace Tests\Support;

use RuntimeException;
use Symfony\Component\Process\Process;

/** Build-time SQL migration convention and Git history check; never executes migration PHP. */
final class MigrationInputIntegrity
{
    private static array $snapshots = [];

    public function __construct(private string $repository, private ?string $database = null)
    {
        $this->database ??= $repository.'/database';
    }

    public static function digest(string $bytes): string
    {
        return hash('sha256', str_replace("\r\n", "\n", $bytes));
    }

    public function trustedSha(): string
    {
        $sha = getenv('BUILDERAD_MIGRATION_TRUSTED_SHA');
        if ($sha === false || $sha === '') {
            if (getenv('GITHUB_ACTIONS') === 'true') {
                throw new RuntimeException('CI requires the server-provided target/before SHA, not manifest baseline.');
            }
            // Local remote-tracking state is useful evidence, not proof of branch protection.
            $sha = trim($this->git(['rev-parse', '--verify', 'refs/remotes/origin/main^{commit}']));
        }
        if (! preg_match('/^[a-f0-9]{40}$/D', $sha) || $sha === str_repeat('0', 40)) {
            throw new RuntimeException('A pinned, existing trusted Git commit SHA is required.');
        }
        $this->git(['cat-file', '-e', $sha.'^{commit}']);

        return $sha;
    }

    public function verify(array $lock, string $sha): array
    {
        $trusted = $this->snapshot($sha);
        $errors = [];
        if (($lock['baseline'] ?? null) !== $trusted['baseline']) {
            $errors[] = 'Manifest baseline differs from trusted Git history metadata';
        }
        $paths = glob($this->database.'/migrations/*.php');
        $names = array_map('basename', $paths);
        $expectedReaders = $trusted['readers'];
        $expectedInputs = $trusted['inputs'];
        $last = max(array_keys($trusted['historical']));
        foreach ($trusted['historical'] as $name => $digest) {
            if (! is_file($this->database.'/migrations/'.$name)
                || self::digest(file_get_contents($this->database.'/migrations/'.$name)) !== $digest
                || ($lock['historical'][$name] ?? null) !== $digest) {
                $errors[] = 'Trusted historical migration changed: '.$name;
            }
        }
        foreach ($paths as $path) {
            $name = basename($path);
            if (isset($trusted['historical'][$name])) {
                continue;
            }
            if (strcmp($name, $last) <= 0) {
                $errors[] = 'Backdated migration: '.$name;
            }
            $source = file_get_contents($path);
            if (($lock['historical'][$name] ?? null) !== self::digest($source)) {
                $errors[] = 'Unregistered or edited forward migration: '.$name;
            }
            $inspection = self::inspectForward($name, $source);
            foreach ($inspection['violations'] as $violation) {
                $errors[] = $name.': '.$violation;
            }
            $expectedReaders[$name] = $inspection['inputs'];
            foreach ($inspection['inputs'] as $input) {
                $expectedInputs[$input] = $lock['inputs'][$input] ?? null;
            }
        }
        foreach ($expectedReaders as $name => $inputs) {
            if (self::set($lock['readers'][$name] ?? []) !== self::set($inputs)) {
                $errors[] = 'Reader relationship differs from actual Git/PHP source: '.$name;
            }
        }
        foreach ($expectedInputs as $input => $digest) {
            if ($digest === null || ($lock['inputs'][$input] ?? null) !== $digest
                || ! is_file($this->database.'/'.$input)
                || self::digest(file_get_contents($this->database.'/'.$input)) !== $digest) {
                $errors[] = 'Pinned input differs from trusted Git/versioned input: '.$input;
            }
        }
        if (self::set(array_keys($lock['historical'] ?? [])) !== self::set($names)
            || self::set(array_keys($lock['readers'] ?? [])) !== self::set($names)
            || self::set(array_keys($lock['inputs'] ?? [])) !== self::set(array_keys($expectedInputs))) {
            $errors[] = 'Manifest migration/reader/input membership differs from actual sources';
        }

        return $errors;
    }

    /** Only literal DB::statement/unprepared SQL and literal own-version file reads are admitted. */
    public static function inspectForward(string $name, string $source): array
    {
        try {
            $tokens = self::tokens($source, TOKEN_PARSE);
        } catch (\ParseError) {
            return ['inputs' => [], 'violations' => ['Invalid PHP']];
        }
        $inputs = [];
        $violations = [];
        $reduced = [];
        for ($i = 0; $i < count($tokens); $i++) {
            $token = $tokens[$i];
            if (is_array($token) && $token[0] === T_STRING && $token[1] === 'file_get_contents') {
                $slice = array_slice($tokens, $i, 7);
                $text = array_map(self::text(...), $slice);
                $literal = $slice[4] ?? null;
                if (count($slice) === 7 && $text[1] === '(' && $text[2] === 'database_path' && $text[3] === '('
                    && $text[5] === ')' && $text[6] === ')' && is_array($literal) && $literal[0] === T_CONSTANT_ENCAPSED_STRING
                    && preg_match('~\A([\x27\x22])(migration-sql/'.preg_quote(pathinfo($name, PATHINFO_FILENAME), '~').'/[a-zA-Z0-9_-]+\.sql)\1\z~', $literal[1], $match)
                    && ! in_array(self::text($tokens[$i - 1] ?? ''), ['::', '->', '?->', 'new'], true)) {
                    $inputs[] = $match[2];
                    $reduced[] = [T_CONSTANT_ENCAPSED_STRING, "'pinned SQL'"];
                    $i += 6;

                    continue;
                }
            }
            $reduced[] = $token;
        }
        $allowedNames = ['Migration', 'DB', 'up', 'down', 'void', 'statement', 'unprepared'];
        $classes = ['Illuminate\\Database\\Migrations\\Migration', 'Illuminate\\Support\\Facades\\DB'];
        foreach ($reduced as $i => $token) {
            $text = self::text($token);
            $previous = self::text($reduced[$i - 1] ?? '');
            $next = self::text($reduced[$i + 1] ?? '');
            if (! is_array($token)) {
                if (! in_array($text, ['{', '}', '(', ')', ';', ':'], true)
                    || ($text === '(' && ($previous === ')' || (is_array($reduced[$i - 1] ?? null) && $reduced[$i - 1][0] === T_CONSTANT_ENCAPSED_STRING)))) {
                    $violations[] = 'Unqualified syntax/call: '.$text;
                }

                continue;
            }
            if (! in_array($token[0], [T_OPEN_TAG, T_USE, T_RETURN, T_NEW, T_CLASS, T_EXTENDS, T_PUBLIC, T_FUNCTION,
                T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED, T_DOUBLE_COLON, T_CONSTANT_ENCAPSED_STRING,
                T_START_HEREDOC, T_ENCAPSED_AND_WHITESPACE, T_END_HEREDOC], true)
                || ($token[0] === T_STRING && ! in_array($text, $allowedNames, true))
                || (in_array($token[0], [T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true) && ! in_array(ltrim($text, '\\'), $classes, true))
                || ($token[0] === T_NEW && $next !== 'class')
                || ($token[0] === T_CLASS && $previous !== 'new')
                || ($token[0] === T_FUNCTION && ! in_array($next, ['up', 'down'], true))
                || ($token[0] === T_DOUBLE_COLON && (! in_array(ltrim($previous, '\\'), ['DB', $classes[1]], true) || ! in_array($next, ['statement', 'unprepared'], true)))
                || ($next === '(' && in_array($token[0], [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true)
                    && ! ($previous === 'function' && in_array($text, ['up', 'down'], true))
                    && ! ($previous === '::' && in_array($text, ['statement', 'unprepared'], true)))) {
                $violations[] = 'Unqualified token/call: '.$text;
            }
        }

        return ['inputs' => self::set($inputs), 'violations' => $violations];
    }

    /** Finite accepted legacy forms: literal paths and literal-list foreach interpolation/concatenation. */
    private static function historicalInputs(string $source): array
    {
        $code = '';
        $readPositions = [];
        $loopPositions = [];
        foreach (token_get_all($source) as $token) {
            if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            if (is_array($token) && $token[0] === T_STRING && $token[1] === 'file_get_contents') {
                $readPositions[] = strlen($code);
            }
            if (is_array($token) && $token[0] === T_FOREACH) {
                $loopPositions[] = strlen($code);
            }
            $code .= self::text($token);
        }
        $reads = [];
        foreach ($readPositions as $position) {
            // Anchor at a real PHP call token, never at lookalike text inside SQL literals.
            if (! preg_match('~\Gfile_get_contents\s*\(\s*database_path\s*\((.*?)\)\s*\)~s', $code, $read, PREG_OFFSET_CAPTURE, $position)) {
                throw new RuntimeException('Unresolved historical file reader');
            }
            $reads[] = $read;
        }
        $loops = [];
        foreach ($loopPositions as $position) {
            if (preg_match('~\Gforeach\s*\(\s*\[([^\]]*)\]\s+as\s+\$(\w+)\s*\)\s*\{(.*?)^        \}~ms', $code, $loop, PREG_OFFSET_CAPTURE, $position)) {
                $loops[] = $loop;
            }
        }
        $inputs = [];
        foreach ($reads as $read) {
            $expression = trim($read[1][0]);
            if (preg_match('~\A([\x27\x22])([a-zA-Z0-9_./-]+)\1\z~', $expression, $literal)) {
                $inputs[] = $literal[2];

                continue;
            }
            if (! preg_match('~\A"([a-zA-Z0-9_./-]*)\{\$(\w+)\}([a-zA-Z0-9_./-]*)"\z~', $expression, $parts)
                && ! preg_match('~\A\x27([a-zA-Z0-9_./-]*)\x27\s*\.\s*\$(\w+)\s*\.\s*\x27([a-zA-Z0-9_./-]*)\x27\z~', $expression, $parts)) {
                throw new RuntimeException('Unresolved historical path expression: '.$expression);
            }
            $resolved = false;
            foreach ($loops as $loop) {
                if ($loop[2][0] !== $parts[2] || $read[0][1] < $loop[3][1] || $read[0][1] >= $loop[3][1] + strlen($loop[3][0])) {
                    continue;
                }
                preg_match_all("~'([a-zA-Z0-9_./-]+)'~", $loop[1][0], $values);
                if (preg_replace("~'([a-zA-Z0-9_./-]+)'|[\\s,]~", '', $loop[1][0]) !== '') {
                    throw new RuntimeException('Unresolved historical foreach list');
                }
                foreach ($values[1] as $value) {
                    $inputs[] = $parts[1].$value.$parts[3];
                }
                $resolved = true;
            }
            if (! $resolved) {
                throw new RuntimeException('Historical reader has no finite enclosing foreach');
            }
        }

        return self::set($inputs);
    }

    private function snapshot(string $sha): array
    {
        if (! preg_match('/^[a-f0-9]{40}$/D', $sha)) {
            throw new RuntimeException('Trusted comparison requires a pinned Git SHA');
        }
        $key = $this->repository.':'.$sha;
        if (isset(self::$snapshots[$key])) {
            return self::$snapshots[$key];
        }
        $manifest = 'tests/Fixtures/migration-inputs.lock.json';
        $paths = array_filter(explode("\n", trim($this->git(['ls-tree', '-r', '--name-only', $sha, '--', 'database/migrations', $manifest]))));
        $contents = $this->blobs($sha, $paths);
        $snapshot = ['baseline' => isset($contents[$manifest]) ? json_decode($contents[$manifest], true, flags: JSON_THROW_ON_ERROR)['baseline'] : $sha,
            'historical' => [], 'readers' => [], 'inputs' => []];
        foreach ($contents as $path => $source) {
            if (! str_starts_with($path, 'database/migrations/') || ! str_ends_with($path, '.php')) {
                continue;
            }
            $name = basename($path);
            $snapshot['historical'][$name] = self::digest($source);
            $snapshot['readers'][$name] = self::historicalInputs($source);
        }
        if ($snapshot['historical'] === []) {
            throw new RuntimeException('Trusted Git reference has no accepted migrations');
        }
        $inputs = self::set(array_merge(...array_values($snapshot['readers'])));
        foreach ($this->blobs($sha, array_map(fn ($input) => 'database/'.$input, $inputs)) as $path => $source) {
            $snapshot['inputs'][substr($path, strlen('database/'))] = self::digest($source);
        }

        return self::$snapshots[$key] = $snapshot;
    }

    private function blobs(string $sha, array $paths): array
    {
        if ($paths === []) {
            return [];
        }
        $output = $this->git(['cat-file', '--batch'], implode("\n", array_map(fn ($path) => $sha.':'.$path, $paths))."\n");
        $offset = 0;
        $blobs = [];
        foreach ($paths as $path) {
            $end = strpos($output, "\n", $offset);
            if ($end === false || ! preg_match('/^[a-f0-9]{40} blob (\d+)$/D', substr($output, $offset, $end - $offset), $match)) {
                throw new RuntimeException('Trusted Git blob unavailable: '.$path);
            }
            $size = (int) $match[1];
            $blobs[$path] = substr($output, $end + 1, $size);
            $offset = $end + 1 + $size + 1;
        }

        return $blobs;
    }

    private function git(array $arguments, ?string $input = null): string
    {
        $process = new Process(['git', ...$arguments], $this->repository, input: $input);
        $process->mustRun();

        return $process->getOutput();
    }

    private static function tokens(string $source, int $flags = 0): array
    {
        return array_values(array_filter(token_get_all($source, $flags), static fn ($token) => ! is_array($token)
            || ! in_array($token[0], [T_COMMENT, T_DOC_COMMENT, T_WHITESPACE], true)));
    }

    private static function text(array|string $token): string
    {
        return is_array($token) ? $token[1] : $token;
    }

    private static function set(array $values): array
    {
        $values = array_values(array_unique($values));
        sort($values, SORT_STRING);

        return $values;
    }
}

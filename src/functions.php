<?php

declare(strict_types=1);

namespace DeployKit;

/**
 * Shell command running artisan from a release, as the app account when one is set.
 *
 * $env sets real environment variables for that one command. They go through `env` after sudo,
 * because sudo resets the environment it was given.
 *
 * @param array<string, string> $env
 */
function artisanCommand(string $releasePath, string $command, ?string $appUser, string $php = 'php', array $env = []): string
{
    $line = $php . ' ' . $releasePath . '/artisan ' . $command;
    if ($env !== []) {
        $assignments = [];
        foreach ($env as $name => $value) {
            if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', (string) $name) !== 1) {
                throw new \InvalidArgumentException('Not an environment variable name: ' . $name);
            }
            $assignments[] = $name . '=' . escapeshellarg($value);
        }
        $line = 'env ' . implode(' ', $assignments) . ' ' . $line;
    }

    return $appUser === null ? $line : 'sudo -u ' . $appUser . ' ' . $line;
}

/**
 * artisanCommand() fed from the Deployer context (release_path, dk_app_user, bin/php).
 *
 * @param array<string, string> $env
 */
function artisan(string $command, array $env = []): string
{
    $user = \Deployer\get('dk_app_user');

    return artisanCommand(
        (string) \Deployer\get('release_path'),
        $command,
        $user === null || $user === '' ? null : (string) $user,
        (string) \Deployer\get('bin/php'),
        $env,
    );
}

/** Where a release keeps its own compiled Blade views. */
function releaseViewPath(string $releasePath): string
{
    return rtrim($releasePath, '/') . '/bootstrap/cache/views';
}

/**
 * Shell command printing `view.compiled` from a release's cached config, and nothing else (the cached
 * config holds secrets). It reads the frozen value PHP-FPM will use. `artisan config:show` cannot be
 * used for this check: it prints paths inside the app relative to the base path, so a correct value
 * would show as `bootstrap/cache/views` without the release path.
 */
function cachedViewPathCommand(string $releasePath, ?string $appUser, string $php = 'php'): string
{
    $file = rtrim($releasePath, '/') . '/bootstrap/cache/config.php';
    $code = '$c = require ' . var_export($file, true) . '; echo is_string($c["view"]["compiled"] ?? null) ? $c["view"]["compiled"] : "";';
    $line = $php . ' -r ' . escapeshellarg($code);

    return $appUser === null ? $line : 'sudo -u ' . $appUser . ' ' . $line;
}

/**
 * artisan() for the `current` release: what runs after a rollback, when release_path no longer exists.
 */
function currentArtisan(string $command): string
{
    $user = \Deployer\get('dk_app_user');

    return artisanCommand(
        (string) \Deployer\get('current_path'),
        $command,
        $user === null || $user === '' ? null : (string) $user,
        (string) \Deployer\get('bin/php'),
    );
}

/**
 * The curl probe for the health URL. The body is discarded, never printed. For an https URL with
 * $resolveLocal the host is pinned to 127.0.0.1 on the URL's port (443 unless the URL names one),
 * skipping DNS and the proxy; -k as the certificate may not match. Other schemes are fetched as they are.
 */
function healthCommand(string $url, bool $resolveLocal): string
{
    $line = 'curl -fsS --max-time 20 -o /dev/null';
    $host = parse_url($url, PHP_URL_HOST);
    if ($resolveLocal && str_starts_with(strtolower($url), 'https://') && is_string($host) && $host !== '') {
        $port = parse_url($url, PHP_URL_PORT) ?? 443;
        $line .= ' --resolve ' . escapeshellarg($host . ':' . $port . ':127.0.0.1') . ' -k';
    }

    return $line . ' ' . escapeshellarg($url);
}

/** The health URL as it may be printed: scheme, host and path only (no credentials, port, query or fragment). */
function healthUrlForDisplay(string $url): string
{
    $parts = parse_url($url);
    if ($parts === false) {
        return '(unparsable URL)';
    }

    return ($parts['scheme'] ?? '') . '://' . ($parts['host'] ?? '') . ($parts['path'] ?? '');
}

/**
 * Shell commands pointing `current` at releases/<release>. With $atomic (mv supports -T) a temporary link
 * is renamed over `current`, the way deploy:symlink switches; otherwise Deployer's {{bin/symlink}} form.
 *
 * @return list<string>
 */
function rollbackSwitchCommands(string $deployPath, string $currentPath, string $release, bool $atomic): array
{
    $target = escapeshellarg('releases/' . $release);
    $cd = 'cd ' . escapeshellarg($deployPath) . ' && ';
    if (! $atomic) {
        return [$cd . '{{bin/symlink}} ' . $target . ' ' . escapeshellarg($currentPath)];
    }
    $tmp = escapeshellarg($currentPath . '.dk_tmp');

    return [$cd . 'ln -sfn ' . $target . ' ' . $tmp . ' && mv -T ' . $tmp . ' ' . escapeshellarg($currentPath)];
}

/** The artisan arguments running one seeder; the class is quoted so `Database\Seeders\X` survives the shell. */
function seedCommand(string $class): string
{
    return 'db:seed --class=' . escapeshellarg($class) . ' --force';
}

/** Shell command clearing the PHP-FPM pool's opcache through cachetool. */
function cachetoolCommand(string $cachetool, string $socket): string
{
    return 'sudo ' . $cachetool . ' opcache:reset --fcgi=' . $socket;
}

/**
 * Which release directories a cleanup removes.
 *
 * Kept: the release `current` points to, plus the newest live releases (those that passed a health
 * check and were not rolled back), up to $keep in total. Everything else goes, failed and unfinished
 * releases included, so failed attempts never push the last live release out.
 *
 * Before any release is marked live (releases made before the marker existed), only clear surplus
 * goes: releases older than current that are not among the $keep newest. Nothing goes when there is
 * no current, when it is not among $names, or when only one release exists.
 *
 * @param list<string> $names release directory names, newest first
 * @param list<string> $live names of the live releases
 * @return list<string> names to remove, newest first
 */
function releasesToRemove(array $names, array $live, ?string $current, int $keep): array
{
    if ($keep <= 0 || count($names) <= 1 || $current === null || ! in_array($current, $names, true)) {
        return [];
    }

    $kept = [$current => true];
    $liveSet = array_flip(array_intersect($live, $names));
    if ($liveSet !== []) {
        foreach ($names as $name) {
            if (count($kept) >= $keep) {
                break;
            }
            if (isset($liveSet[$name])) {
                $kept[$name] = true;
            }
        }
    } else {
        foreach ($names as $i => $name) {
            if ($name === $current) {
                break;
            }
            $kept[$name] = true; // newer than current, status unknown: keep
        }
        foreach (array_slice($names, 0, $keep) as $name) {
            $kept[$name] = true;
        }
    }

    return array_values(array_filter($names, static fn (string $name): bool => ! isset($kept[$name])));
}

/** Release directory names the kit will ever delete: Deployer's numbers, or a plain overridden name. */
const RELEASE_NAME_PATTERN = '/^[A-Za-z0-9][A-Za-z0-9._-]*$/';

/**
 * The path of one release, for a delete. Throws unless the deploy path is absolute and plain, the name
 * is a plain release name, and the result sits directly under <deploy_path>/releases/.
 */
function releasePathFor(string $deployPath, string $name): string
{
    $base = rtrim($deployPath, '/');
    if ($base === '' || $base[0] !== '/' || in_array('..', explode('/', $base), true) || in_array('.', explode('/', $base), true)) {
        throw new \RuntimeException('Refusing to delete a release: the deploy path "' . $deployPath . '" is not a plain absolute path.');
    }
    if (preg_match(RELEASE_NAME_PATTERN, $name) !== 1 || str_contains($name, '..')) {
        throw new \RuntimeException('Refusing to delete a release: "' . $name . '" is not a release name.');
    }
    $path = $base . '/releases/' . $name;
    if (! str_starts_with($path, $base . '/releases/') || dirname($path) !== $base . '/releases') {
        throw new \RuntimeException('Refusing to delete "' . $path . '": it is not inside ' . $base . '/releases/.');
    }

    return $path;
}

/**
 * Migration names in `migrate:status --pending` output.
 *
 * Accepts bare names one per line and Laravel's framed table, where a pending row looks like
 * `  2026_01_01_000000_create_x_table .......... Pending`. Anything else yields no names.
 *
 * @return list<string>
 */
function pendingMigrationNames(string $output): array
{
    preg_match_all('/(?<![\w])(\d{4}_\d{2}_\d{2}_\d{6}_\w+)/', $output, $matches);

    return array_values(array_unique($matches[1]));
}

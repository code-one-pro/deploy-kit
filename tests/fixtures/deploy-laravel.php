<?php

declare(strict_types=1);

namespace Deployer;

require __DIR__ . '/../../recipe/common.php';

/** @return list<string>|null null when the variable is unset */
function dkEnvList(string $name): ?array
{
    $value = getenv($name);

    return $value === false ? null : array_values(array_filter(explode(',', $value), static fn ($v) => $v !== ''));
}

set('deploy_path', getenv('DK_BASE'));
set('dk_source_path', getenv('DK_SOURCE'));

if (($paths = dkEnvList('DK_UPLOAD_PATHS')) !== null) {
    set('dk_upload_paths', $paths);
}
if (($excludes = dkEnvList('DK_UPLOAD_EXCLUDES')) !== null) {
    set('dk_upload_excludes', $excludes);
}

localhost();

task('deploy', [
    'deploy:info',
    'deploy:setup',
    'deploy:lock',
    'deploy:release',
    'dk:upload',
    'deploy:shared',
    'deploy:symlink',
    'deploy:unlock',
    'deploy:cleanup',
]);

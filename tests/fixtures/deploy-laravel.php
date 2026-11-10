<?php

declare(strict_types=1);

namespace Deployer;

require 'recipe/common.php';

set('deploy_path', getenv('DK_BASE'));
set('keep_releases', 5);

localhost();

task('dk:fixture:upload', function () {
    upload(rtrim((string) getenv('DK_SOURCE'), '/') . '/', '{{release_path}}/');
});

task('deploy', [
    'deploy:info',
    'deploy:setup',
    'deploy:lock',
    'deploy:release',
    'dk:fixture:upload',
    'deploy:shared',
    'deploy:symlink',
    'deploy:unlock',
    'deploy:cleanup',
]);

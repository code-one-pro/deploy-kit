<?php

declare(strict_types=1);

namespace Deployer;

require __DIR__ . '/../../recipe/static-site.php';

set('deploy_path', getenv('DK_BASE'));
set('dk_source_path', getenv('DK_SOURCE'));
set('dk_health_url', 'file://{{deploy_path}}/current/index.html');
set('dk_health_delay', 0);

localhost();

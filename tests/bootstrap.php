<?php

declare(strict_types=1);

/*
 * Standalone, the library's own vendor directory has the autoloader. Installed as a path package of a project —
 * the Neos distribution it is developed in — the project's has it instead, minus this package's autoload-dev.
 */
$candidates = [
    __DIR__ . '/../vendor/autoload.php',
    __DIR__ . '/../../../Packages/Libraries/autoload.php',
];
foreach ($candidates as $candidate) {
    if (is_file($candidate)) {
        $loader = require $candidate;
        $loader->addPsr4('Neos\\OpenApi\\Mcp\\Tests\\', __DIR__ . '/');
        return;
    }
}
throw new RuntimeException('No Composer autoloader found; run "composer install" first.');

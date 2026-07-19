<?php

/*
 * Credentials live in .deployment-credentials.php (gitignored):
 *
 * return [
 *     'remote' => <fill in your FTP url - e.g. ftps://example.com/path/to/your/dir>,
 *     'user' => <fill in your FTP username>,
 *     'password' => <fill in your FTP password>
 * ];
 */
$credentials = require __DIR__ . '/.deployment-credentials.php';

return [
    'Production' => [
        'remote' => $credentials['remote'],
        'user' => $credentials['user'],
        'password' => $credentials['password'],
        'local' => __DIR__ . '/web',
        'test' => false,
        'ignore' => '
            /composer.json
            /composer.lock
            /latte-lint
            /phpstan.neon
            /config/local.neon
            /data/
            /log/
            /temp/
            /tests/
            /www/upload/
        ',
        'allowDelete' => true,
        'purge' => ['/temp/cache'],
    ],
    'tempDir' => __DIR__ . '/web/temp',
    'colors' => true,
    'log' => __DIR__ . '/web/log/deployment.log'
];

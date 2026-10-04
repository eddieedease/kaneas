<?php

// Written by the web installer (install/) as config.php. Do not commit config.php.
return [
    'db' => [
        'host' => 'localhost',
        'port' => 3306,
        'name' => 'kaneas',
        'user' => 'kaneas',
        'pass' => 'secret',
        'prefix' => 'kb_',
    ],
    'jwt' => [
        'secret' => 'random 64 byte base64 string',
        'access_ttl' => 900,          // 15 minutes
        'refresh_ttl' => 2592000,     // 30 days
    ],
    'app' => [
        'url' => 'https://example.com/kanban/',
        'key' => 'random 32 byte base64 string (encrypts stored secrets)',
        'debug' => false,
    ],
];

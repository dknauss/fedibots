<?php

declare(strict_types=1);

require_once __DIR__ . '/src/Config.php';
require_once __DIR__ . '/src/Http/Router.php';
require_once __DIR__ . '/src/ActivityPub/WebFinger.php';
require_once __DIR__ . '/src/ActivityPub/Actor.php';
require_once __DIR__ . '/src/ActivityPub/NodeInfo.php';
require_once __DIR__ . '/src/ActivityPub/Collections.php';

$config = new Fedibots\Config(__DIR__ . '/.env');
$router = new Fedibots\Http\Router($config);
$router->dispatch();

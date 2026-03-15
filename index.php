<?php

declare(strict_types=1);

require_once __DIR__ . '/src/Config.php';
require_once __DIR__ . '/src/Http/Router.php';
require_once __DIR__ . '/src/Storage/StorageInterface.php';
require_once __DIR__ . '/src/Storage/FlatFile.php';
require_once __DIR__ . '/src/ActivityPub/WebFinger.php';
require_once __DIR__ . '/src/ActivityPub/Actor.php';
require_once __DIR__ . '/src/ActivityPub/NodeInfo.php';
require_once __DIR__ . '/src/ActivityPub/Collections.php';
require_once __DIR__ . '/src/ActivityPub/Signature.php';
require_once __DIR__ . '/src/ActivityPub/Inbox.php';
require_once __DIR__ . '/src/ActivityPub/Delivery.php';
require_once __DIR__ . '/src/ActivityPub/Outbox.php';
require_once __DIR__ . '/src/Content/ContentProviderInterface.php';
require_once __DIR__ . '/src/Content/Post.php';

$config  = new Fedibots\Config(__DIR__ . '/.env');
$storage = new Fedibots\Storage\FlatFile(
    __DIR__ . '/data',
    (int) ($config->get('MAX_LOGS') ?? 2048)
);

$router = new Fedibots\Http\Router($config, $storage);
$router->dispatch();

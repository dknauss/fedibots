<?php

declare(strict_types=1);

// Autoload via Composer if available, otherwise manual requires
$autoload = __DIR__ . '/../vendor/autoload.php';
if (file_exists($autoload)) {
    require_once $autoload;
} else {
    // Manual autoloader for running without Composer
    spl_autoload_register(function (string $class): void {
        $prefix = 'Fedibots\\';
        if (!str_starts_with($class, $prefix)) {
            return;
        }
        $relative = substr($class, strlen($prefix));
        $file = __DIR__ . '/../src/' . str_replace('\\', '/', $relative) . '.php';
        if (file_exists($file)) {
            require_once $file;
        }
    });
}

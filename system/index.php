<?php

use Illuminate\Http\Request;

define('LARAVEL_START', microtime(true));

// mod_rewrite is disabled in this MAMP installation. When Apache resolves this
// file as DirectoryIndex it hides index.php from Laravel's URL generator, so
// normalize the directory URL to the explicit front controller first.
$requestPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
if (is_string($requestPath) && str_ends_with($requestPath, '/')) {
    header('Location: ./index.php', true, 302);
    exit;
}

// MAMP serves this project as a subdirectory. Until the guided setup has
// completed, the project root must lead to the installer instead of booting
// Laravel with its temporary development configuration.
if (!is_file(__DIR__.'/storage/app/installed.lock')) {
    header('Location: ./install.php', true, 302);
    exit;
}

if (file_exists($maintenance = __DIR__.'/storage/framework/maintenance.php')) {
    require $maintenance;
}

require __DIR__.'/vendor/autoload.php';

(require_once __DIR__.'/bootstrap/app.php')
    ->handleRequest(Request::capture());

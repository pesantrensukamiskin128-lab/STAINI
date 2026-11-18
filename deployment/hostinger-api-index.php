<?php

use Illuminate\Foundation\Application;
use Illuminate\Http\Request;

define('LARAVEL_START', microtime(true));

// The API document root is inside public_html; Laravel itself stays outside it.
$backendRoot = '/home/u273554645/asc-backend';

if (file_exists($maintenance = $backendRoot.'/storage/framework/maintenance.php')) {
    require $maintenance;
}

require $backendRoot.'/vendor/autoload.php';

/** @var Application $app */
$app = require_once $backendRoot.'/bootstrap/app.php';

$app->handleRequest(Request::capture());

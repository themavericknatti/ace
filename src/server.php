<?php

/**
 * Eventlyy / Ace - Internal Development Server Router
 *
 * Emulates Apache mod_rewrite for PHP's built-in web server.
 * This file is internal to the framework (located in src/).
 */

$publicDir = dirname(__DIR__) . '/public';

$uri = urldecode(
    parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?? ''
);

// If request matches a real static file in public directory, return false to let PHP serve it
if ($uri !== '/' && file_exists($publicDir . $uri)) {
    return false;
}

require_once $publicDir . '/index.php';

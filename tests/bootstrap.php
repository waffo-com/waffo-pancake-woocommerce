<?php
require_once __DIR__ . '/../vendor/autoload.php';

if (! defined('DAY_IN_SECONDS')) {
    define('DAY_IN_SECONDS', 86400);
}

WP_Mock::bootstrap();

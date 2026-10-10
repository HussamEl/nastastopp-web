<?php
declare(strict_types=1);

require __DIR__ . '/Config.php';
require __DIR__ . '/Store.php';
require __DIR__ . '/Api.php';

// Errors are never shown or logged with what a request carried.
ini_set('display_errors', '0');

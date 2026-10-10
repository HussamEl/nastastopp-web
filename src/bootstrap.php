<?php
declare(strict_types=1);

require __DIR__ . '/Config.php';
require __DIR__ . '/Store.php';
require __DIR__ . '/Api.php';

// Errors are never shown or logged with what a request carried: no display, and a stack trace
// never holds a function's arguments.
ini_set('display_errors', '0');
ini_set('zend.exception_ignore_args', '1');
// Numbers in a body passed on are written back as they came (the shortest form that reads the same).
ini_set('serialize_precision', '-1');

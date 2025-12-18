<?php
declare(strict_types=1);

// Autoloader
require_once __DIR__ . '/../vendor/autoload.php';

// Start session for tests that need it
if (session_status() === PHP_STATUS_NONE) {
    session_start();
}

// Set error reporting
error_reporting(E_ALL);
ini_set('display_errors', '1');
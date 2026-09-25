<?php
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_set_cookie_params(['httponly'=>true,'secure'=>!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off','samesite'=>'Lax']);
    session_start();
}
$config=require __DIR__.'/config/config.php'; date_default_timezone_set($config['timezone'] ?? 'UTC');
require_once __DIR__.'/config/database.php'; require_once __DIR__.'/includes/functions.php';

<?php
session_start();
$config = require __DIR__ . '/config/config.php';
date_default_timezone_set($config['timezone']);
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/functions.php';

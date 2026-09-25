<?php require_once __DIR__.'/../bootstrap.php';require_admin();session_destroy();header('Location: '.url('admin/login.php'));exit;

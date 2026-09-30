<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/auth.php';

logout_user();
header('Location: ' . BASE_URL . '/auth/login.php');
exit;

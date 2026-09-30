<?php
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/includes/auth.php';

if (is_logged_in()) {
    if ($_SESSION['role'] === 'admin') {
        $dest = '/admin/dashboard.php';
    } elseif ($_SESSION['role'] === 'inventory_staff') {
        $dest = '/inventory/dashboard.php';
    } elseif ($_SESSION['role'] === 'field_supervisor') {
        $dest = '/requisition/dashboard.php';
    } elseif ($_SESSION['role'] === 'driver_helper') {
        $dest = '/requisition/home.php';
    } else {
        $dest = '/catalog/browse.php';
    }
    header('Location: ' . BASE_URL . $dest);
} else {
    header('Location: ' . BASE_URL . '/auth/login.php');
}
exit;

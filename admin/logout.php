<?php
/**
 * Admin Logout Handler
 * Place this file as: admin/logout.php
 */

session_start();

// Include auth functions for logging
require_once 'includes/auth.php';

// Log the logout activity if user is logged in
if (isset($_SESSION['user_id'])) {
    logAdminActivity('admin_logout');
}

// Clear all session data
$_SESSION = array();

// Delete the session cookie if it exists
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000,
        $params["path"], $params["domain"],
        $params["secure"], $params["httponly"]
    );
}

// Destroy the session
session_destroy();

// Clear remember me cookie if it exists
if (isset($_COOKIE['remember_token'])) {
    setcookie('remember_token', '', time() - 3600, '/', '', true, true);
}

// Redirect to login page
header('Location: ../login.php?message=' . urlencode('You have been logged out successfully'));
exit();
?>
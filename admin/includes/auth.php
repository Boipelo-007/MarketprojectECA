<?php

session_start();

// Include database configuration
require_once '../config/database.php';

/**
 * Check if user is logged in and is an admin
 */
function requireAdminAuth() {
    if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'admin') {
        header('Location: login.php');
        exit();
    }
}

/**
 * Check admin session validity
 */
function checkAdminSession() {
    if (!isset($_SESSION['user_id'])) {
        return false;
    }
    
    try {
        $pdo = getDatabase();
        $stmt = $pdo->prepare("SELECT user_id, user_type, is_active FROM users WHERE user_id = ? AND user_type = 'admin'");
        $stmt->execute([$_SESSION['user_id']]);
        $admin = $stmt->fetch();
        
        if (!$admin || !$admin['is_active']) {
            session_destroy();
            return false;
        }
        
        return true;
    } catch (Exception $e) {
        error_log("Admin session check error: " . $e->getMessage());
        return false;
    }
}

/**
 * Get current admin user info
 */
function getCurrentAdmin() {
    if (!isset($_SESSION['user_id'])) {
        return null;
    }
    
    try {
        $pdo = getDatabase();
        $stmt = $pdo->prepare("
            SELECT user_id, first_name, last_name, email, profile_image_url, last_login 
            FROM users 
            WHERE user_id = ? AND user_type = 'admin'
        ");
        $stmt->execute([$_SESSION['user_id']]);
        return $stmt->fetch();
    } catch (Exception $e) {
        error_log("Get admin user error: " . $e->getMessage());
        return null;
    }
}

/**
 * Log admin activity
 */
function logAdminActivity($action_type, $table_name = null, $record_id = null, $old_values = null, $new_values = null) {
    try {
        $pdo = getDatabase();
        $stmt = $pdo->prepare("
            INSERT INTO activity_logs (user_id, action_type, table_name, record_id, old_values, new_values, ip_address, user_agent) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ");
        
        $ip_address = $_SERVER['REMOTE_ADDR'] ?? null;
        $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? null;
        
        $stmt->execute([
            $_SESSION['user_id'],
            $action_type,
            $table_name,
            $record_id,
            $old_values ? json_encode($old_values) : null,
            $new_values ? json_encode($new_values) : null,
            $ip_address,
            $user_agent
        ]);
    } catch (Exception $e) {
        error_log("Admin activity log error: " . $e->getMessage());
    }
}

/**
 * Format numbers for display
 */
function formatNumber($number) {
    if ($number >= 1000000) {
        return number_format($number / 1000000, 1) . 'M';
    } elseif ($number >= 1000) {
        return number_format($number / 1000, 1) . 'K';
    }
    return number_format($number);
}

/**
 * Format currency for display
 */
function formatCurrency($amount) {
    return 'R' . number_format($amount, 2);
}

/**
 * Time ago helper
 */
function timeAgo($datetime) {
    $time = time() - strtotime($datetime);
    
    if ($time < 60) return 'just now';
    if ($time < 3600) return floor($time/60) . ' min ago';
    if ($time < 86400) return floor($time/3600) . ' hours ago';
    if ($time < 2592000) return floor($time/86400) . ' days ago';
    if ($time < 31536000) return floor($time/2592000) . ' weeks ago';
    
    return date('M j, Y', strtotime($datetime));
}

/**
 * Generate CSRF token
 */
function generateCSRFToken() {
    if (!isset($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/**
 * Verify CSRF token
 */
function verifyCSRFToken($token) {
    return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
}

/**
 * Get dashboard statistics
 */
function getDashboardStats() {
    try {
        $pdo = getDatabase();
        
        // Total users
        $stmt = $pdo->query("SELECT COUNT(*) as total_users FROM users WHERE user_type != 'admin'");
        $total_users = $stmt->fetch()['total_users'];
        
        // Active listings
        $stmt = $pdo->query("SELECT COUNT(*) as active_listings FROM listings WHERE status = 'active'");
        $active_listings = $stmt->fetch()['active_listings'];
        
        // Pending listings
        $stmt = $pdo->query("SELECT COUNT(*) as pending_listings FROM listings WHERE admin_approved = FALSE");
        $pending_listings = $stmt->fetch()['pending_listings'];
        
        // Total transactions
        $stmt = $pdo->query("SELECT COUNT(*) as total_transactions FROM transactions");
        $total_transactions = $stmt->fetch()['total_transactions'];
        
        // Revenue this month
        $stmt = $pdo->query("
            SELECT COALESCE(SUM(total_amount), 0) as monthly_revenue 
            FROM transactions 
            WHERE transaction_status = 'completed' 
            AND DATE_FORMAT(date_created, '%Y-%m') = DATE_FORMAT(NOW(), '%Y-%m')
        ");
        $monthly_revenue = $stmt->fetch()['monthly_revenue'];
        
        return [
            'total_users' => $total_users,
            'active_listings' => $active_listings,
            'pending_listings' => $pending_listings,
            'total_transactions' => $total_transactions,
            'monthly_revenue' => $monthly_revenue
        ];
    } catch (Exception $e) {
        error_log("Dashboard stats error: " . $e->getMessage());
        return [
            'total_users' => 0,
            'active_listings' => 0,
            'pending_listings' => 0,
            'total_transactions' => 0,
            'monthly_revenue' => 0
        ];
    }
}
?>
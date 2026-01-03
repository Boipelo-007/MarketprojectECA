<?php

require_once 'includes/auth.php';
requireAdminAuth();

$admin = getCurrentAdmin();

// Handle POST requests (user actions)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        die('Invalid CSRF token');
    }
    
    $action = $_POST['action'] ?? '';
    $user_id = intval($_POST['user_id'] ?? 0);
    
    try {
        $pdo = getDatabase();
        
        switch ($action) {
            case 'activate':
                $stmt = $pdo->prepare("UPDATE users SET is_active = TRUE WHERE user_id = ?");
                $stmt->execute([$user_id]);
                logAdminActivity('user_activated', 'users', $user_id);
                $message = 'User activated successfully';
                break;
                
            case 'deactivate':
                $stmt = $pdo->prepare("UPDATE users SET is_active = FALSE WHERE user_id = ?");
                $stmt->execute([$user_id]);
                logAdminActivity('user_deactivated', 'users', $user_id);
                $message = 'User deactivated successfully';
                break;
                
            case 'verify':
                $stmt = $pdo->prepare("UPDATE users SET is_verified = TRUE, verification_date = NOW() WHERE user_id = ?");
                $stmt->execute([$user_id]);
                logAdminActivity('user_verified', 'users', $user_id);
                $message = 'User verified successfully';
                break;
                
            case 'unverify':
                $stmt = $pdo->prepare("UPDATE users SET is_verified = FALSE, verification_date = NULL WHERE user_id = ?");
                $stmt->execute([$user_id]);
                logAdminActivity('user_unverified', 'users', $user_id);
                $message = 'User verification removed';
                break;
        }
        
        header('Location: users.php?message=' . urlencode($message));
        exit();
        
    } catch (Exception $e) {
        error_log("User action error: " . $e->getMessage());
        $error = 'An error occurred while processing the request';
    }
}

// Get filters
$search = $_GET['search'] ?? '';
$user_type = $_GET['user_type'] ?? '';
$status = $_GET['status'] ?? '';
$verified = $_GET['verified'] ?? '';
$page = max(1, intval($_GET['page'] ?? 1));
$limit = 20;
$offset = ($page - 1) * $limit;

try {
    $pdo = getDatabase();
    
    // Build WHERE clause
    $where_conditions = ["user_type != 'admin'"];
    $params = [];
    
    if ($search) {
        $where_conditions[] = "(first_name LIKE ? OR last_name LIKE ? OR email LIKE ? OR phone_number LIKE ?)";
        $search_param = '%' . $search . '%';
        $params = array_merge($params, [$search_param, $search_param, $search_param, $search_param]);
    }
    
    if ($user_type) {
        $where_conditions[] = "user_type = ?";
        $params[] = $user_type;
    }
    
    if ($status !== '') {
        $where_conditions[] = "is_active = ?";
        $params[] = $status === 'active' ? 1 : 0;
    }
    
    if ($verified !== '') {
        $where_conditions[] = "is_verified = ?";
        $params[] = $verified === 'verified' ? 1 : 0;
    }
    
    $where_clause = implode(' AND ', $where_conditions);
    
    // Get total count
    $count_query = "SELECT COUNT(*) as total FROM users WHERE " . $where_clause;
    $count_stmt = $pdo->prepare($count_query);
    $count_stmt->execute($params);
    $total_users = $count_stmt->fetch()['total'];
    $total_pages = ceil($total_users / $limit);
    
    // Get users
    $users_query = "
        SELECT user_id, first_name, last_name, email, phone_number, user_type, 
               is_active, is_verified, date_created, last_login,
               (SELECT COUNT(*) FROM listings WHERE seller_id = users.user_id) as listing_count,
               (SELECT COUNT(*) FROM transactions WHERE buyer_id = users.user_id OR seller_id = users.user_id) as transaction_count
        FROM users 
        WHERE " . $where_clause . "
        ORDER BY date_created DESC 
        LIMIT ? OFFSET ?
    ";
    
    $users_stmt = $pdo->prepare($users_query);
    $users_stmt->execute(array_merge($params, [$limit, $offset]));
    $users = $users_stmt->fetchAll();
    
} catch (Exception $e) {
    error_log("Users page error: " . $e->getMessage());
    $users = [];
    $total_users = 0;
    $total_pages = 0;
}

$csrf_token = generateCSRFToken();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Users Management - Edu C2C Marketplace</title>
    <!-- Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.6/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Font Awesome -->
    <script src="https://kit.fontawesome.com/4d21d6d70f.js" crossorigin="anonymous"></script>
    <style>
        .sidebar {
            background: linear-gradient(135deg, #2d3748, #4a5568);
            min-height: 100vh;
        }
        .admin-header {
            background: #fff;
            border-bottom: 1px solid #e2e8f0;
        }
        .nav-link {
            color: rgba(255,255,255,0.8);
            transition: all 0.3s;
        }
        .nav-link:hover, .nav-link.active {
            background: rgba(255,255,255,0.1);
            color: white;
        }
        .user-avatar {
            width: 40px;
            height: 40px;
            background: linear-gradient(135deg, #4a6bff, #6b46ff);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: bold;
        }
    </style>
</head>
<body>
    <div class="d-flex">
        <!-- Sidebar -->
        <div class="sidebar flex-shrink-0 p-3" style="width: 280px;">
            <a href="#" class="d-flex align-items-center mb-3 mb-md-0 me-md-auto text-white text-decoration-none">
                <i class="fas fa-handshake me-2"></i>
                <span class="fs-4">Admin Portal</span>
            </a>
            <hr>
            <ul class="nav nav-pills flex-column mb-auto">
                <li class="nav-item">
                    <a href="dashboard.php" class="nav-link">
                        <i class="fas fa-tachometer-alt me-2"></i>
                        Dashboard
                    </a>
                </li>
                <li>
                    <a href="listings.php" class="nav-link">
                        <i class="fas fa-list me-2"></i>
                        Listings
                    </a>
                </li>
                <li>
                    <a href="users.php" class="nav-link active">
                        <i class="fas fa-users me-2"></i>
                        Users
                    </a>
                </li>
                <li>
                    <a href="transactions.php" class="nav-link">
                        <i class="fas fa-exchange-alt me-2"></i>
                        Transactions
                    </a>
                </li>
                <li>
                    <a href="reports.php" class="nav-link">
                        <i class="fas fa-chart-bar me-2"></i>
                        Reports
                    </a>
                </li>
                <li>
                    <a href="settings.php" class="nav-link">
                        <i class="fas fa-cog me-2"></i>
                        Settings
                    </a>
                </li>
            </ul>
            <hr>
            <div class="dropdown">
                <a href="#" class="d-flex align-items-center text-white text-decoration-none dropdown-toggle" 
                   id="dropdownUser1" data-bs-toggle="dropdown" aria-expanded="false">
                    <img src="<?php echo $admin['profile_image_url'] ?? 'https://randomuser.me/api/portraits/men/32.jpg'; ?>" 
                         alt="Admin" width="32" height="32" class="rounded-circle me-2">
                    <strong><?php echo htmlspecialchars($admin['first_name'] . ' ' . $admin['last_name']); ?></strong>
                </a>
                <ul class="dropdown-menu dropdown-menu-dark text-small shadow" aria-labelledby="dropdownUser1">
                    <li><a class="dropdown-item" href="#">Profile</a></li>
                    <li><a class="dropdown-item" href="settings.php">Settings</a></li>
                    <li><hr class="dropdown-divider"></li>
                    <li><a class="dropdown-item" href="logout.php">Sign out</a></li>
                </ul>
            </div>
        </div>

        <!-- Main Content -->
        <div class="flex-grow-1">
            <!-- Admin Header -->
            <header class="admin-header py-3 px-4 d-flex justify-content-between align-items-center">
                <div>
                    <h4 class="mb-0">User Management</h4>
                    <small class="text-muted">Manage platform users and their verification status</small>
                </div>
                <div>
                    <button class="btn btn-sm btn-outline-secondary me-2">
                        <i class="fas fa-download"></i> Export
                    </button>
                    <button class="btn btn-sm btn-primary">
                        <i class="fas fa-plus"></i> Add User
                    </button>
                </div>
            </header>

            <!-- Admin Content -->
            <main class="p-4">
                <?php if (isset($_GET['message'])): ?>
                    <div class="alert alert-success alert-dismissible fade show" role="alert">
                        <?php echo htmlspecialchars($_GET['message']); ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>

                <?php if (isset($error)): ?>
                    <div class="alert alert-danger alert-dismissible fade show" role="alert">
                        <?php echo htmlspecialchars($error); ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>

                <!-- Filters -->
                <div class="card border-0 shadow-sm mb-4">
                    <div class="card-body">
                        <form method="GET" class="row g-3">
                            <div class="col-md-3">
                                <label for="search" class="form-label">Search Users</label>
                                <input type="text" class="form-control" id="search" name="search" 
                                       placeholder="Name, email, phone..." value="<?php echo htmlspecialchars($search); ?>">
                            </div>
                            <div class="col-md-2">
                                <label for="user_type" class="form-label">User Type</label>
                                <select class="form-select" id="user_type" name="user_type">
                                    <option value="">All Types</option>
                                    <option value="buyer" <?php echo $user_type === 'buyer' ? 'selected' : ''; ?>>Buyer</option>
                                    <option value="seller" <?php echo $user_type === 'seller' ? 'selected' : ''; ?>>Seller</option>
                                </select>
                            </div>
                            <div class="col-md-2">
                                <label for="status" class="form-label">Status</label>
                                <select class="form-select" id="status" name="status">
                                    <option value="">All Status</option>
                                    <option value="active" <?php echo $status === 'active' ? 'selected' : ''; ?>>Active</option>
                                    <option value="inactive" <?php echo $status === 'inactive' ? 'selected' : ''; ?>>Inactive</option>
                                </select>
                            </div>
                            <div class="col-md-2">
                                <label for="verified" class="form-label">Verification</label>
                                <select class="form-select" id="verified" name="verified">
                                    <option value="">All Users</option>
                                    <option value="verified" <?php echo $verified === 'verified' ? 'selected' : ''; ?>>Verified</option>
                                    <option value="unverified" <?php echo $verified === 'unverified' ? 'selected' : ''; ?>>Unverified</option>
                                </select>
                            </div>
                            <div class="col-md-3 d-flex align-items-end">
                                <button type="submit" class="btn btn-primary me-2">
                                    <i class="fas fa-search"></i> Filter
                                </button>
                                <a href="users.php" class="btn btn-outline-secondary">
                                    <i class="fas fa-times"></i> Clear
                                </a>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- Users Table -->
                <div class="card border-0 shadow-sm">
                    <div class="card-header bg-white">
                        <div class="d-flex justify-content-between align-items-center">
                            <h5 class="mb-0">Users (<?php echo number_format($total_users); ?>)</h5>
                            <div>
                                <span class="text-muted">
                                    Showing <?php echo $offset + 1; ?>-<?php echo min($offset + $limit, $total_users); ?> 
                                    of <?php echo number_format($total_users); ?> users
                                </span>
                            </div>
                        </div>
                    </div>
                    <div class="card-body p-0">
                        <div class="table-responsive">
                            <table class="table table-hover mb-0">
                                <thead class="table-light">
                                    <tr>
                                        <th>User</th>
                                        <th>Contact</th>
                                        <th>Type</th>
                                        <th>Activity</th>
                                        <th>Status</th>
                                        <th>Joined</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($users)): ?>
                                        <tr>
                                            <td colspan="7" class="text-center py-4 text-muted">
                                                No users found matching your criteria.
                                            </td>
                                        </tr>
                                    <?php else: ?>
                                        <?php foreach ($users as $user): ?>
                                            <tr>
                                                <td>
                                                    <div class="d-flex align-items-center">
                                                        <div class="user-avatar me-3">
                                                            <?php echo strtoupper(substr($user['first_name'], 0, 1) . substr($user['last_name'], 0, 1)); ?>
                                                        </div>
                                                        <div>
                                                            <h6 class="mb-0">
                                                                <?php echo htmlspecialchars($user['first_name'] . ' ' . $user['last_name']); ?>
                                                                <?php if ($user['is_verified']): ?>
                                                                    <i class="fas fa-check-circle text-success ms-1" title="Verified"></i>
                                                                <?php endif; ?>
                                                            </h6>
                                                            <small class="text-muted">ID: <?php echo $user['user_id']; ?></small>
                                                        </div>
                                                    </div>
                                                </td>
                                                <td>
                                                    <div>
                                                        <div class="fw-medium"><?php echo htmlspecialchars($user['email']); ?></div>
                                                        <small class="text-muted"><?php echo htmlspecialchars($user['phone_number']); ?></small>
                                                    </div>
                                                </td>
                                                <td>
                                                    <span class="badge bg-<?php echo $user['user_type'] === 'seller' ? 'primary' : 'info'; ?>">
                                                        <?php echo ucfirst($user['user_type']); ?>
                                                    </span>
                                                </td>
                                                <td>
                                                    <div>
                                                        <small class="text-muted d-block">
                                                            <?php echo $user['listing_count']; ?> listings
                                                        </small>
                                                        <small class="text-muted">
                                                            <?php echo $user['transaction_count']; ?> transactions
                                                        </small>
                                                    </div>
                                                </td>
                                                <td>
                                                    <div>
                                                        <span class="badge bg-<?php echo $user['is_active'] ? 'success' : 'danger'; ?>">
                                                            <?php echo $user['is_active'] ? 'Active' : 'Inactive'; ?>
                                                        </span>
                                                        <div>
                                                            <small class="text-muted">
                                                                Last login: <?php echo $user['last_login'] ? timeAgo($user['last_login']) : 'Never'; ?>
                                                            </small>
                                                        </div>
                                                    </div>
                                                </td>
                                                <td>
                                                    <small class="text-muted"><?php echo timeAgo($user['date_created']); ?></small>
                                                </td>
                                                <td>
                                                    <div class="dropdown">
                                                        <button class="btn btn-sm btn-outline-secondary dropdown-toggle" 
                                                                type="button" data-bs-toggle="dropdown">
                                                            Actions
                                                        </button>
                                                        <ul class="dropdown-menu">
                                                            <li>
                                                                <a class="dropdown-item" href="user-details.php?id=<?php echo $user['user_id']; ?>">
                                                                    <i class="fas fa-eye me-2"></i> View Details
                                                                </a>
                                                            </li>
                                                            <li><hr class="dropdown-divider"></li>
                                                            <?php if ($user['is_active']): ?>
                                                                <li>
                                                                    <form method="POST" class="d-inline">
                                                                        <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                                                                        <input type="hidden" name="action" value="deactivate">
                                                                        <input type="hidden" name="user_id" value="<?php echo $user['user_id']; ?>">
                                                                        <button type="submit" class="dropdown-item text-warning" 
                                                                                onclick="return confirm('Are you sure you want to deactivate this user?')">
                                                                            <i class="fas fa-user-times me-2"></i> Deactivate
                                                                        </button>
                                                                    </form>
                                                                </li>
                                                            <?php else: ?>
                                                                <li>
                                                                    <form method="POST" class="d-inline">
                                                                        <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                                                                        <input type="hidden" name="action" value="activate">
                                                                        <input type="hidden" name="user_id" value="<?php echo $user['user_id']; ?>">
                                                                        <button type="submit" class="dropdown-item text-success">
                                                                            <i class="fas fa-user-check me-2"></i> Activate
                                                                        </button>
                                                                    </form>
                                                                </li>
                                                            <?php endif; ?>
                                                            
                                                            <?php if ($user['is_verified']): ?>
                                                                <li>
                                                                    <form method="POST" class="d-inline">
                                                                        <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                                                                        <input type="hidden" name="action" value="unverify">
                                                                        <input type="hidden" name="user_id" value="<?php echo $user['user_id']; ?>">
                                                                        <button type="submit" class="dropdown-item text-warning"
                                                                                onclick="return confirm('Are you sure you want to remove verification?')">
                                                                            <i class="fas fa-user-minus me-2"></i> Remove Verification
                                                                        </button>
                                                                    </form>
                                                                </li>
                                                            <?php else: ?>
                                                                <li>
                                                                    <form method="POST" class="d-inline">
                                                                        <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                                                                        <input type="hidden" name="action" value="verify">
                                                                        <input type="hidden" name="user_id" value="<?php echo $user['user_id']; ?>">
                                                                        <button type="submit" class="dropdown-item text-primary">
                                                                            <i class="fas fa-user-check me-2"></i> Verify User
                                                                        </button>
                                                                    </form>
                                                                </li>
                                                            <?php endif; ?>
                                                        </ul>
                                                    </div>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                    
                    <!-- Pagination -->
                    <?php if ($total_pages > 1): ?>
                        <div class="card-footer bg-white">
                            <nav aria-label="Users pagination">
                                <ul class="pagination pagination-sm mb-0 justify-content-center">
                                    <li class="page-item <?php echo $page <= 1 ? 'disabled' : ''; ?>">
                                        <a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page - 1])); ?>">
                                            Previous
                                        </a>
                                    </li>
                                    
                                    <?php for ($i = max(1, $page - 2); $i <= min($total_pages, $page + 2); $i++): ?>
                                        <li class="page-item <?php echo $i === $page ? 'active' : ''; ?>">
                                            <a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['page' => $i])); ?>">
                                                <?php echo $i; ?>
                                            </a>
                                        </li>
                                    <?php endfor; ?>
                                    
                                    <li class="page-item <?php echo $page >= $total_pages ? 'disabled' : ''; ?>">
                                        <a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page + 1])); ?>">
                                            Next
                                        </a>
                                    </li>
                                </ul>
                            </nav>
                        </div>
                    <?php endif; ?>
                </div>
            </main>
        </div>
    </div>

    <!-- Bootstrap JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.6/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
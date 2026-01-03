<?php

require_once 'includes/auth.php';
requireAdminAuth();

$admin = getCurrentAdmin();
$stats = getDashboardStats();

// Get recent activities
try {
    $pdo = getDatabase();
    
    // Recent listings
    $recent_listings_query = "
        SELECT l.listing_id, l.title, l.price, l.status, l.date_created,
               u.first_name, u.last_name, c.category_name
        FROM listings l
        JOIN users u ON l.seller_id = u.user_id
        JOIN categories c ON l.category_id = c.category_id
        ORDER BY l.date_created DESC
        LIMIT 5
    ";
    $stmt = $pdo->prepare($recent_listings_query);
    $stmt->execute();
    $recent_listings = $stmt->fetchAll();
    
    // Recent users
    $recent_users_query = "
        SELECT user_id, first_name, last_name, user_type, date_created, is_verified
        FROM users 
        WHERE user_type != 'admin'
        ORDER BY date_created DESC
        LIMIT 5
    ";
    $stmt = $pdo->prepare($recent_users_query);
    $stmt->execute();
    $recent_users = $stmt->fetchAll();
    
    // Recent transactions
    $recent_transactions_query = "
        SELECT t.transaction_id, t.total_amount, t.transaction_status, t.date_created,
               l.title as listing_title,
               buyer.first_name as buyer_name,
               seller.first_name as seller_name
        FROM transactions t
        JOIN listings l ON t.listing_id = l.listing_id
        JOIN users buyer ON t.buyer_id = buyer.user_id
        JOIN users seller ON t.seller_id = seller.user_id
        ORDER BY t.date_created DESC
        LIMIT 5
    ";
    $stmt = $pdo->prepare($recent_transactions_query);
    $stmt->execute();
    $recent_transactions = $stmt->fetchAll();
    
} catch (Exception $e) {
    error_log("Dashboard data error: " . $e->getMessage());
    $recent_listings = [];
    $recent_users = [];
    $recent_transactions = [];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Dashboard - Edu C2C Marketplace</title>
    <!-- Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.6/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Font Awesome -->
    <script src="https://kit.fontawesome.com/4d21d6d70f.js" crossorigin="anonymous"></script>
    <!-- Chart.js -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        .sidebar {
            background: linear-gradient(135deg, #2d3748, #4a5568);
            min-height: 100vh;
        }
        .admin-header {
            background: #fff;
            border-bottom: 1px solid #e2e8f0;
        }
        .stat-card {
            background: linear-gradient(135deg, #4a6bff, #6b46ff);
            color: white;
            border-radius: 15px;
            padding: 1.5rem;
        }
        .nav-link {
            color: rgba(255,255,255,0.8);
            transition: all 0.3s;
        }
        .nav-link:hover, .nav-link.active {
            background: rgba(255,255,255,0.1);
            color: white;
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
                    <a href="dashboard.php" class="nav-link active">
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
                    <a href="users.php" class="nav-link">
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
                    <h4 class="mb-0">Dashboard Overview</h4>
                    <small class="text-muted">Welcome back, <?php echo htmlspecialchars($admin['first_name']); ?>!</small>
                </div>
                <div>
                    <button class="btn btn-sm btn-outline-secondary me-2">
                        <i class="fas fa-bell"></i>
                        <span class="badge bg-danger badge-sm"><?php echo $stats['pending_listings']; ?></span>
                    </button>
                    <button class="btn btn-sm btn-outline-secondary">
                        <i class="fas fa-question-circle"></i>
                    </button>
                </div>
            </header>

            <!-- Dashboard Content -->
            <main class="p-4">
                <!-- Stats Cards -->
                <div class="row mb-4">
                    <div class="col-md-3 mb-3">
                        <div class="stat-card">
                            <div class="d-flex justify-content-between align-items-center">
                                <div>
                                    <h6 class="mb-0 text-white-50">Total Users</h6>
                                    <h3 class="mb-0"><?php echo formatNumber($stats['total_users']); ?></h3>
                                </div>
                                <i class="fas fa-users fa-2x opacity-75"></i>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3 mb-3">
                        <div class="stat-card">
                            <div class="d-flex justify-content-between align-items-center">
                                <div>
                                    <h6 class="mb-0 text-white-50">Active Listings</h6>
                                    <h3 class="mb-0"><?php echo formatNumber($stats['active_listings']); ?></h3>
                                </div>
                                <i class="fas fa-list fa-2x opacity-75"></i>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3 mb-3">
                        <div class="stat-card">
                            <div class="d-flex justify-content-between align-items-center">
                                <div>
                                    <h6 class="mb-0 text-white-50">Transactions</h6>
                                    <h3 class="mb-0"><?php echo formatNumber($stats['total_transactions']); ?></h3>
                                </div>
                                <i class="fas fa-exchange-alt fa-2x opacity-75"></i>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3 mb-3">
                        <div class="stat-card">
                            <div class="d-flex justify-content-between align-items-center">
                                <div>
                                    <h6 class="mb-0 text-white-50">Monthly Revenue</h6>
                                    <h3 class="mb-0"><?php echo formatCurrency($stats['monthly_revenue']); ?></h3>
                                </div>
                                <i class="fas fa-chart-line fa-2x opacity-75"></i>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="row">
                    <!-- Recent Listings -->
                    <div class="col-lg-6 mb-4">
                        <div class="card border-0 shadow-sm">
                            <div class="card-header bg-white">
                                <h5 class="card-title mb-0">Recent Listings</h5>
                            </div>
                            <div class="card-body">
                                <?php if (empty($recent_listings)): ?>
                                    <p class="text-muted">No recent listings found.</p>
                                <?php else: ?>
                                    <?php foreach ($recent_listings as $listing): ?>
                                        <div class="d-flex justify-content-between align-items-center py-2 border-bottom">
                                            <div>
                                                <h6 class="mb-0"><?php echo htmlspecialchars($listing['title']); ?></h6>
                                                <small class="text-muted">
                                                    by <?php echo htmlspecialchars($listing['first_name'] . ' ' . $listing['last_name']); ?> • 
                                                    <?php echo timeAgo($listing['date_created']); ?>
                                                </small>
                                            </div>
                                            <div class="text-end">
                                                <div class="fw-bold">R<?php echo number_format($listing['price']); ?></div>
                                                <span class="badge bg-<?php echo $listing['status'] === 'active' ? 'success' : 'warning'; ?>">
                                                    <?php echo ucfirst($listing['status']); ?>
                                                </span>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                                <div class="text-center mt-3">
                                    <a href="listings.php" class="btn btn-sm btn-outline-primary">View All Listings</a>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Recent Users -->
                    <div class="col-lg-6 mb-4">
                        <div class="card border-0 shadow-sm">
                            <div class="card-header bg-white">
                                <h5 class="card-title mb-0">Recent Users</h5>
                            </div>
                            <div class="card-body">
                                <?php if (empty($recent_users)): ?>
                                    <p class="text-muted">No recent users found.</p>
                                <?php else: ?>
                                    <?php foreach ($recent_users as $user): ?>
                                        <div class="d-flex justify-content-between align-items-center py-2 border-bottom">
                                            <div>
                                                <h6 class="mb-0">
                                                    <?php echo htmlspecialchars($user['first_name'] . ' ' . $user['last_name']); ?>
                                                    <?php if ($user['is_verified']): ?>
                                                        <i class="fas fa-check-circle text-success ms-1"></i>
                                                    <?php endif; ?>
                                                </h6>
                                                <small class="text-muted">
                                                    <?php echo ucfirst($user['user_type']); ?> • 
                                                    <?php echo timeAgo($user['date_created']); ?>
                                                </small>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                                <div class="text-center mt-3">
                                    <a href="users.php" class="btn btn-sm btn-outline-primary">View All Users</a>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Recent Transactions -->
                <div class="row">
                    <div class="col-12">
                        <div class="card border-0 shadow-sm">
                            <div class="card-header bg-white">
                                <h5 class="card-title mb-0">Recent Transactions</h5>
                            </div>
                            <div class="card-body">
                                <?php if (empty($recent_transactions)): ?>
                                    <p class="text-muted">No recent transactions found.</p>
                                <?php else: ?>
                                    <div class="table-responsive">
                                        <table class="table table-hover">
                                            <thead>
                                                <tr>
                                                    <th>Transaction ID</th>
                                                    <th>Listing</th>
                                                    <th>Buyer</th>
                                                    <th>Seller</th>
                                                    <th>Amount</th>
                                                    <th>Status</th>
                                                    <th>Date</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach ($recent_transactions as $transaction): ?>
                                                    <tr>
                                                        <td>#<?php echo $transaction['transaction_id']; ?></td>
                                                        <td><?php echo htmlspecialchars($transaction['listing_title']); ?></td>
                                                        <td><?php echo htmlspecialchars($transaction['buyer_name']); ?></td>
                                                        <td><?php echo htmlspecialchars($transaction['seller_name']); ?></td>
                                                        <td><?php echo formatCurrency($transaction['total_amount']); ?></td>
                                                        <td>
                                                            <span class="badge bg-<?php echo $transaction['transaction_status'] === 'completed' ? 'success' : 'warning'; ?>">
                                                                <?php echo ucfirst($transaction['transaction_status']); ?>
                                                            </span>
                                                        </td>
                                                        <td><?php echo timeAgo($transaction['date_created']); ?></td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                <?php endif; ?>
                                <div class="text-center mt-3">
                                    <a href="transactions.php" class="btn btn-sm btn-outline-primary">View All Transactions</a>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </main>
        </div>
    </div>

    <!-- Bootstrap JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.6/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
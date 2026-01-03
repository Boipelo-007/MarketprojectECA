<?php


require_once 'includes/auth.php';
requireAdminAuth();

$admin = getCurrentAdmin();

// Handle POST requests (transaction actions)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        die('Invalid CSRF token');
    }
    
    $action = $_POST['action'] ?? '';
    $transaction_id = intval($_POST['transaction_id'] ?? 0);
    
    try {
        $pdo = getDatabase();
        
        switch ($action) {
            case 'approve_refund':
                $stmt = $pdo->prepare("UPDATE transactions SET transaction_status = 'refunded', refund_processed_date = NOW() WHERE transaction_id = ?");
                $stmt->execute([$transaction_id]);
                logAdminActivity('transaction_refund_approved', 'transactions', $transaction_id);
                $message = 'Refund approved successfully';
                break;
                
            case 'mark_completed':
                $stmt = $pdo->prepare("UPDATE transactions SET transaction_status = 'completed', completion_date = NOW() WHERE transaction_id = ?");
                $stmt->execute([$transaction_id]);
                logAdminActivity('transaction_completed', 'transactions', $transaction_id);
                $message = 'Transaction marked as completed';
                break;
                
            case 'cancel_transaction':
                $stmt = $pdo->prepare("UPDATE transactions SET transaction_status = 'cancelled', cancellation_date = NOW() WHERE transaction_id = ?");
                $stmt->execute([$transaction_id]);
                logAdminActivity('transaction_cancelled', 'transactions', $transaction_id);
                $message = 'Transaction cancelled';
                break;
        }
        
        header('Location: transactions.php?message=' . urlencode($message));
        exit();
        
    } catch (Exception $e) {
        error_log("Transaction action error: " . $e->getMessage());
        $error = 'An error occurred while processing the request';
    }
}

// Get filters
$search = $_GET['search'] ?? '';
$status = $_GET['status'] ?? '';
$date_from = $_GET['date_from'] ?? '';
$date_to = $_GET['date_to'] ?? '';
$min_amount = $_GET['min_amount'] ?? '';
$max_amount = $_GET['max_amount'] ?? '';
$page = max(1, intval($_GET['page'] ?? 1));
$limit = 20;
$offset = ($page - 1) * $limit;

try {
    $pdo = getDatabase();
    
    // Build WHERE clause
    $where_conditions = ["1=1"];
    $params = [];
    
    if ($search) {
        $where_conditions[] = "(l.title LIKE ? OR buyer.first_name LIKE ? OR seller.first_name LIKE ? OR CAST(t.transaction_id AS CHAR) LIKE ?)";
        $search_param = '%' . $search . '%';
        $params = array_merge($params, [$search_param, $search_param, $search_param, $search_param]);
    }
    
    if ($status) {
        $where_conditions[] = "t.transaction_status = ?";
        $params[] = $status;
    }
    
    if ($date_from) {
        $where_conditions[] = "DATE(t.date_created) >= ?";
        $params[] = $date_from;
    }
    
    if ($date_to) {
        $where_conditions[] = "DATE(t.date_created) <= ?";
        $params[] = $date_to;
    }
    
    if ($min_amount) {
        $where_conditions[] = "t.total_amount >= ?";
        $params[] = floatval($min_amount);
    }
    
    if ($max_amount) {
        $where_conditions[] = "t.total_amount <= ?";
        $params[] = floatval($max_amount);
    }
    
    $where_clause = implode(' AND ', $where_conditions);
    
    // Get total count
    $count_query = "
        SELECT COUNT(*) as total 
        FROM transactions t
        JOIN listings l ON t.listing_id = l.listing_id
        JOIN users buyer ON t.buyer_id = buyer.user_id
        JOIN users seller ON t.seller_id = seller.user_id
        WHERE " . $where_clause;
    $count_stmt = $pdo->prepare($count_query);
    $count_stmt->execute($params);
    $total_transactions = $count_stmt->fetch()['total'];
    $total_pages = ceil($total_transactions / $limit);
    
    // Get transactions
    $transactions_query = "
        SELECT t.transaction_id, t.total_amount, t.transaction_status, t.payment_method,
               t.date_created, t.completion_date, t.cancellation_date,
               l.listing_id, l.title as listing_title, l.price as listing_price,
               buyer.user_id as buyer_id, buyer.first_name as buyer_first_name, 
               buyer.last_name as buyer_last_name, buyer.email as buyer_email,
               seller.user_id as seller_id, seller.first_name as seller_first_name, 
               seller.last_name as seller_last_name, seller.email as seller_email
        FROM transactions t
        JOIN listings l ON t.listing_id = l.listing_id
        JOIN users buyer ON t.buyer_id = buyer.user_id
        JOIN users seller ON t.seller_id = seller.user_id
        WHERE " . $where_clause . "
        ORDER BY t.date_created DESC 
        LIMIT ? OFFSET ?
    ";
    
    $transactions_stmt = $pdo->prepare($transactions_query);
    $transactions_stmt->execute(array_merge($params, [$limit, $offset]));
    $transactions = $transactions_stmt->fetchAll();
    
    // Get summary statistics
    $summary_query = "
        SELECT 
            COUNT(*) as total_count,
            SUM(CASE WHEN transaction_status = 'completed' THEN total_amount ELSE 0 END) as completed_revenue,
            SUM(CASE WHEN transaction_status = 'pending' THEN 1 ELSE 0 END) as pending_count,
            SUM(CASE WHEN transaction_status = 'cancelled' THEN 1 ELSE 0 END) as cancelled_count,
            SUM(CASE WHEN transaction_status = 'refunded' THEN total_amount ELSE 0 END) as refunded_amount
        FROM transactions t
        JOIN listings l ON t.listing_id = l.listing_id
        JOIN users buyer ON t.buyer_id = buyer.user_id
        JOIN users seller ON t.seller_id = seller.user_id
        WHERE " . $where_clause;
    $summary_stmt = $pdo->prepare($summary_query);
    $summary_stmt->execute($params);
    $summary = $summary_stmt->fetch();
    
} catch (Exception $e) {
    error_log("Transactions page error: " . $e->getMessage());
    $transactions = [];
    $total_transactions = 0;
    $total_pages = 0;
    $summary = [
        'total_count' => 0,
        'completed_revenue' => 0,
        'pending_count' => 0,
        'cancelled_count' => 0,
        'refunded_amount' => 0
    ];
}

$csrf_token = generateCSRFToken();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Transaction Management - Edu C2C Marketplace</title>
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
        .stat-card {
            background: linear-gradient(135deg, #4a6bff, #6b46ff);
            color: white;
            border-radius: 15px;
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
                    <a href="users.php" class="nav-link">
                        <i class="fas fa-users me-2"></i>
                        Users
                    </a>
                </li>
                <li>
                    <a href="transactions.php" class="nav-link active">
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
                    <h4 class="mb-0">Transaction Management</h4>
                    <small class="text-muted">Monitor and manage platform transactions</small>
                </div>
                <div>
                    <button class="btn btn-sm btn-outline-secondary me-2">
                        <i class="fas fa-download"></i> Export
                    </button>
                    <button class="btn btn-sm btn-outline-secondary">
                        <i class="fas fa-chart-line"></i> Analytics
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

                <!-- Summary Stats -->
                <div class="row mb-4">
                    <div class="col-md-3 mb-3">
                        <div class="card stat-card">
                            <div class="card-body">
                                <div class="d-flex justify-content-between align-items-center">
                                    <div>
                                        <h6 class="mb-0 text-white-50">Total Transactions</h6>
                                        <h3 class="mb-0"><?php echo formatNumber($summary['total_count']); ?></h3>
                                    </div>
                                    <i class="fas fa-exchange-alt fa-2x opacity-75"></i>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3 mb-3">
                        <div class="card stat-card">
                            <div class="card-body">
                                <div class="d-flex justify-content-between align-items-center">
                                    <div>
                                        <h6 class="mb-0 text-white-50">Completed Revenue</h6>
                                        <h3 class="mb-0"><?php echo formatCurrency($summary['completed_revenue']); ?></h3>
                                    </div>
                                    <i class="fas fa-chart-line fa-2x opacity-75"></i>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3 mb-3">
                        <div class="card stat-card">
                            <div class="card-body">
                                <div class="d-flex justify-content-between align-items-center">
                                    <div>
                                        <h6 class="mb-0 text-white-50">Pending</h6>
                                        <h3 class="mb-0"><?php echo formatNumber($summary['pending_count']); ?></h3>
                                    </div>
                                    <i class="fas fa-clock fa-2x opacity-75"></i>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3 mb-3">
                        <div class="card stat-card">
                            <div class="card-body">
                                <div class="d-flex justify-content-between align-items-center">
                                    <div>
                                        <h6 class="mb-0 text-white-50">Refunded</h6>
                                        <h3 class="mb-0"><?php echo formatCurrency($summary['refunded_amount']); ?></h3>
                                    </div>
                                    <i class="fas fa-undo fa-2x opacity-75"></i>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Filters -->
                <div class="card border-0 shadow-sm mb-4">
                    <div class="card-body">
                        <form method="GET" class="row g-3">
                            <div class="col-md-3">
                                <label for="search" class="form-label">Search</label>
                                <input type="text" class="form-control" id="search" name="search" 
                                       placeholder="Transaction ID, listing, user..." value="<?php echo htmlspecialchars($search); ?>">
                            </div>
                            <div class="col-md-2">
                                <label for="status" class="form-label">Status</label>
                                <select class="form-select" id="status" name="status">
                                    <option value="">All Status</option>
                                    <option value="pending" <?php echo $status === 'pending' ? 'selected' : ''; ?>>Pending</option>
                                    <option value="completed" <?php echo $status === 'completed' ? 'selected' : ''; ?>>Completed</option>
                                    <option value="cancelled" <?php echo $status === 'cancelled' ? 'selected' : ''; ?>>Cancelled</option>
                                    <option value="refunded" <?php echo $status === 'refunded' ? 'selected' : ''; ?>>Refunded</option>
                                </select>
                            </div>
                            <div class="col-md-2">
                                <label for="date_from" class="form-label">Date From</label>
                                <input type="date" class="form-control" id="date_from" name="date_from" value="<?php echo htmlspecialchars($date_from); ?>">
                            </div>
                            <div class="col-md-2">
                                <label for="date_to" class="form-label">Date To</label>
                                <input type="date" class="form-control" id="date_to" name="date_to" value="<?php echo htmlspecialchars($date_to); ?>">
                            </div>
                            <div class="col-md-3 d-flex align-items-end">
                                <button type="submit" class="btn btn-primary me-2">
                                    <i class="fas fa-search"></i> Filter
                                </button>
                                <a href="transactions.php" class="btn btn-outline-secondary">
                                    <i class="fas fa-times"></i> Clear
                                </a>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- Transactions Table -->
                <div class="card border-0 shadow-sm">
                    <div class="card-header bg-white">
                        <div class="d-flex justify-content-between align-items-center">
                            <h5 class="mb-0">Transactions (<?php echo number_format($total_transactions); ?>)</h5>
                            <div>
                                <span class="text-muted">
                                    Showing <?php echo $offset + 1; ?>-<?php echo min($offset + $limit, $total_transactions); ?> 
                                    of <?php echo number_format($total_transactions); ?> transactions
                                </span>
                            </div>
                        </div>
                    </div>
                    <div class="card-body p-0">
                        <div class="table-responsive">
                            <table class="table table-hover mb-0">
                                <thead class="table-light">
                                    <tr>
                                        <th>Transaction</th>
                                        <th>Listing</th>
                                        <th>Buyer</th>
                                        <th>Seller</th>
                                        <th>Amount</th>
                                        <th>Status</th>
                                        <th>Date</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($transactions)): ?>
                                        <tr>
                                            <td colspan="8" class="text-center py-4 text-muted">
                                                No transactions found matching your criteria.
                                            </td>
                                        </tr>
                                    <?php else: ?>
                                        <?php foreach ($transactions as $transaction): ?>
                                            <tr>
                                                <td>
                                                    <div>
                                                        <h6 class="mb-0">#<?php echo $transaction['transaction_id']; ?></h6>
                                                        <small class="text-muted">
                                                            <?php echo ucfirst($transaction['payment_method']); ?>
                                                        </small>
                                                    </div>
                                                </td>
                                                <td>
                                                    <div>
                                                        <h6 class="mb-0">
                                                            <a href="../listing.php?id=<?php echo $transaction['listing_id']; ?>" 
                                                               class="text-decoration-none" target="_blank">
                                                                <?php echo htmlspecialchars($transaction['listing_title']); ?>
                                                            </a>
                                                        </h6>
                                                        <small class="text-muted">
                                                            Listed at <?php echo formatCurrency($transaction['listing_price']); ?>
                                                        </small>
                                                    </div>
                                                </td>
                                                <td>
                                                    <div>
                                                        <h6 class="mb-0">
                                                            <?php echo htmlspecialchars($transaction['buyer_first_name'] . ' ' . $transaction['buyer_last_name']); ?>
                                                        </h6>
                                                        <small class="text-muted"><?php echo htmlspecialchars($transaction['buyer_email']); ?></small>
                                                    </div>
                                                </td>
                                                <td>
                                                    <div>
                                                        <h6 class="mb-0">
                                                            <?php echo htmlspecialchars($transaction['seller_first_name'] . ' ' . $transaction['seller_last_name']); ?>
                                                        </h6>
                                                        <small class="text-muted"><?php echo htmlspecialchars($transaction['seller_email']); ?></small>
                                                    </div>
                                                </td>
                                                <td>
                                                    <div class="fw-bold"><?php echo formatCurrency($transaction['total_amount']); ?></div>
                                                </td>
                                                <td>
                                                    <?php
                                                    $status_colors = [
                                                        'pending' => 'warning',
                                                        'completed' => 'success',
                                                        'cancelled' => 'danger',
                                                        'refunded' => 'info'
                                                    ];
                                                    $status_color = $status_colors[$transaction['transaction_status']] ?? 'secondary';
                                                    ?>
                                                    <span class="badge bg-<?php echo $status_color; ?>">
                                                        <?php echo ucfirst($transaction['transaction_status']); ?>
                                                    </span>
                                                </td>
                                                <td>
                                                    <div>
                                                        <small class="text-muted d-block">
                                                            Created: <?php echo timeAgo($transaction['date_created']); ?>
                                                        </small>
                                                        <?php if ($transaction['completion_date']): ?>
                                                            <small class="text-success">
                                                                Completed: <?php echo timeAgo($transaction['completion_date']); ?>
                                                            </small>
                                                        <?php endif; ?>
                                                    </div>
                                                </td>
                                                <td>
                                                    <div class="dropdown">
                                                        <button class="btn btn-sm btn-outline-secondary dropdown-toggle" 
                                                                type="button" data-bs-toggle="dropdown">
                                                            Actions
                                                        </button>
                                                        <ul class="dropdown-menu">
                                                            <li>
                                                                <a class="dropdown-item" href="transaction-details.php?id=<?php echo $transaction['transaction_id']; ?>">
                                                                    <i class="fas fa-eye me-2"></i> View Details
                                                                </a>
                                                            </li>
                                                            <li><hr class="dropdown-divider"></li>
                                                            
                                                            <?php if ($transaction['transaction_status'] === 'pending'): ?>
                                                                <li>
                                                                    <form method="POST" class="d-inline">
                                                                        <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                                                                        <input type="hidden" name="action" value="mark_completed">
                                                                        <input type="hidden" name="transaction_id" value="<?php echo $transaction['transaction_id']; ?>">
                                                                        <button type="submit" class="dropdown-item text-success"
                                                                                onclick="return confirm('Mark this transaction as completed?')">
                                                                            <i class="fas fa-check me-2"></i> Mark Completed
                                                                        </button>
                                                                    </form>
                                                                </li>
                                                                <li>
                                                                    <form method="POST" class="d-inline">
                                                                        <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                                                                        <input type="hidden" name="action" value="cancel_transaction">
                                                                        <input type="hidden" name="transaction_id" value="<?php echo $transaction['transaction_id']; ?>">
                                                                        <button type="submit" class="dropdown-item text-danger"
                                                                                onclick="return confirm('Cancel this transaction?')">
                                                                            <i class="fas fa-times me-2"></i> Cancel Transaction
                                                                        </button>
                                                                    </form>
                                                                </li>
                                                            <?php endif; ?>
                                                            
                                                            <?php if ($transaction['transaction_status'] === 'completed'): ?>
                                                                <li>
                                                                    <form method="POST" class="d-inline">
                                                                        <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                                                                        <input type="hidden" name="action" value="approve_refund">
                                                                        <input type="hidden" name="transaction_id" value="<?php echo $transaction['transaction_id']; ?>">
                                                                        <button type="submit" class="dropdown-item text-warning"
                                                                                onclick="return confirm('Process refund for this transaction?')">
                                                                            <i class="fas fa-undo me-2"></i> Process Refund
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
                            <nav aria-label="Transactions pagination">
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
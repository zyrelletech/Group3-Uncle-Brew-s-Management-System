<?php
    session_start();
    require_once __DIR__ . '/../config/db.php';

    if (!isset($_SESSION['user_id'])) {
        header('Location: login.php');
        exit;
    }

    $username  = $_SESSION['username'];
    $full_name = $_SESSION['full_name'];
    $role      = $_SESSION['role'];

    // Cashiers have no access to the dashboard — send them straight to POS
    if (strtolower($role) === 'cashier') {
        header('Location: pos.php');
        exit;
    }
    $pdo = db();

    // ── Live Stats ────────────────────────────────────────────
    $today_revenue = (float)$pdo->query(
        "SELECT COALESCE(SUM(total),0) FROM orders
        WHERE status='Completed' AND DATE(created_at)=CURDATE()"
    )->fetchColumn();

    $weekly_revenue = (float)$pdo->query(
        "SELECT COALESCE(SUM(total),0) FROM orders
        WHERE status='Completed'
        AND created_at >= DATE_SUB(CURDATE(), INTERVAL 6 DAY)"
    )->fetchColumn();

    $low_stock = (int)$pdo->query(
        "SELECT COUNT(*) FROM products
        WHERE is_active=1 AND stock <= low_stock_threshold"
    )->fetchColumn();

    $active_orders = (int)$pdo->query(
        "SELECT COUNT(*) FROM orders WHERE status='Pending'"
    )->fetchColumn();

    // ── Revenue Last 7 Days ───────────────────────────────────
    $revenue_rows = $pdo->query(
        "SELECT DATE(created_at) AS day, COALESCE(SUM(total),0) AS total
        FROM orders
        WHERE status='Completed'
        AND created_at >= DATE_SUB(CURDATE(), INTERVAL 6 DAY)
        GROUP BY DATE(created_at)"
    )->fetchAll();

    $revenue_map = [];
    foreach ($revenue_rows as $r) {
        $revenue_map[$r['day']] = (float)$r['total'];
    }

    $revenue_last7 = [];
    for ($i = 6; $i >= 0; $i--) {
        $date  = date('Y-m-d', strtotime("-$i days"));
        $label = date('D', strtotime($date));
        $revenue_last7[$label] = $revenue_map[$date] ?? 0;
    }
    $max_revenue = max($revenue_last7) ?: 1;

    // ── Top Products ──────────────────────────────────────────
    $top_products_raw = $pdo->query(
        "SELECT p.name, COALESCE(SUM(oi.subtotal),0) AS revenue
        FROM order_items oi
        JOIN products p ON p.id = oi.product_id
        JOIN orders o ON o.id = oi.order_id
        WHERE o.status='Completed'
        AND o.created_at >= DATE_SUB(CURDATE(), INTERVAL 6 DAY)
        GROUP BY p.id, p.name
        ORDER BY revenue DESC
        LIMIT 5"
    )->fetchAll();

    $top_total = array_sum(array_column($top_products_raw, 'revenue')) ?: 1;
    $top_products = array_map(fn($r) => [
        'name'    => $r['name'],
        'revenue' => (float)$r['revenue'],
        'pct'     => round(($r['revenue'] / $top_total) * 100),
    ], $top_products_raw);

    // ── Recent Orders ─────────────────────────────────────────
    $recent_orders = $pdo->query(
        "SELECT o.order_number, u.full_name AS cashier, o.total, o.status,
                DATE_FORMAT(o.created_at,'%h:%i %p') AS time
        FROM orders o
        JOIN users u ON u.id = o.cashier_id
        ORDER BY o.created_at DESC
        LIMIT 10"
    )->fetchAll();

    // ── Helpers ───────────────────────────────────────────────
    $hour = (int)date('H');
    $greeting = $hour < 12 ? 'Good morning' : ($hour < 18 ? 'Good afternoon' : 'Good evening');
    $today_label = date('l, F j, Y');

    // Admin-only navigation
    $nav = [
        'MAIN' => [
            ['icon'=>'grid',      'label'=>'Dashboard',    'href'=>'dashboard.php', 'active'=>true],
            ['icon'=>'receipt',   'label'=>'POS / Orders', 'href'=>'pos.php'],
        ],
        'MANAGEMENT' => [
            ['icon'=>'box',       'label'=>'Inventory',    'href'=>'inventory.php'],
             ['icon'=>'box',       'label'=>'Categories',    'href'=>'categories.php'],
              ['icon'=>'box',       'label'=>'Products',    'href'=>'products.php'],
            ['icon'=>'bar-chart', 'label'=>'Reports',      'href'=>'reports.php'],
            ['icon'=>'users',     'label'=>'Users',        'href'=>'users.php'],
        ],
        'SHIFT' => [
            ['icon'=>'clock',     'label'=>'Shift Control','href'=>'shift.php'],
        ],
    ];

    function nav_icon(string $name): string {
        $icons = [
            'grid'      => '<rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/>',
            'receipt'   => '<rect x="4" y="2" width="16" height="20" rx="2"/><line x1="8" y1="7" x2="16" y2="7"/><line x1="8" y1="12" x2="16" y2="12"/><line x1="8" y1="17" x2="12" y2="17"/>',
            'box'       => '<path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/><polyline points="3.27 6.96 12 12.01 20.73 6.96"/><line x1="12" y1="22.08" x2="12" y2="12"/>',
            'bar-chart' => '<line x1="18" y1="20" x2="18" y2="10"/><line x1="12" y1="20" x2="12" y2="4"/><line x1="6" y1="20" x2="6" y2="14"/>',
            'users'     => '<path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/>',
            'clock'     => '<circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/>',
            'logout'    => '<path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/>',
        ];
        return $icons[$name] ?? '';
    }
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8"/>
        <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
        <title>Dashboard – Uncle Brew's</title>
        <link rel="preconnect" href="https://fonts.googleapis.com"/>
        <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin/>
        <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@700&family=DM+Sans:ital,wght@0,400;0,500;0,600;1,400&display=swap" rel="stylesheet"/>
        <link rel="stylesheet" href="../css/dashboard.css"/>
    </head>
    <body>

    <aside class="sidebar">

        <div class="brand">
            <img src="../images/brew.jpg" alt="Uncle Brew's Logo"
                style="width:36px;height:36px;object-fit:contain;border-radius:50%;background:#000;flex-shrink:0;" />
            <div>
                <div class="brand-name">Uncle Brew's</div>
                <div class="brand-sub">Management System</div>
            </div>
        </div>

        <nav class="nav">
            <?php foreach ($nav as $section => $items): ?>
                <div class="nav-section-label"><?= $section ?></div>
                <?php foreach ($items as $item): ?>
                    <a href="<?= $item['href'] ?>" class="nav-item <?= !empty($item['active']) ? 'active' : '' ?>">
                        <svg class="nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                            <?= nav_icon($item['icon']) ?>
                        </svg>
                        <?= $item['label'] ?>
                    </a>
                <?php endforeach; ?>
            <?php endforeach; ?>
        </nav>

        <div class="sidebar-footer">
            <div class="user-block">
                <div class="user-avatar"><?= strtoupper($username[0]) ?></div>
                <div>
                    <div class="user-name"><?= htmlspecialchars($full_name) ?></div>
                    <div class="user-role"><?= htmlspecialchars($role) ?></div>
                </div>
            </div>
            <a href="logout.php" class="btn-logout">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                    <?= nav_icon('logout') ?>
                </svg>
                Logout
            </a>
        </div>

    </aside>

    <main class="main">
        <header class="topbar">
            <div class="topbar-title">
                <span class="page-title">Dashboard</span>
                <span class="topbar-sep">—</span>
                <span class="topbar-date"><?= $today_label ?></span>
            </div>
        </header>

        <div class="content">

            <div class="greeting" style="animation-delay:.05s">
                <h1 class="greeting-text"><?= $greeting ?>, <?= htmlspecialchars($full_name) ?> </h1>
                <p class="greeting-sub">Here's what's happening at Uncle Brew's today.</p>
            </div>

            <div class="stat-grid">
                <div class="stat-card accent-green" style="animation-delay:.1s">
                    <div class="stat-top"><span class="stat-label">TODAY'S REVENUE</span><span class="stat-icon">💰</span></div>
                    <div class="stat-value">₱<?= number_format($today_revenue, 2) ?></div>
                    <div class="stat-sub">Daily sales total</div>
                </div>
                <div class="stat-card accent-teal" style="animation-delay:.15s">
                    <div class="stat-top"><span class="stat-label">WEEKLY REVENUE</span><span class="stat-icon">📅</span></div>
                    <div class="stat-value">₱<?= number_format($weekly_revenue, 2) ?></div>
                    <div class="stat-sub">This week</div>
                </div>
                <div class="stat-card accent-amber" style="animation-delay:.2s">
                    <div class="stat-top"><span class="stat-label">LOW STOCK ALERTS</span><span class="stat-icon">⚠️</span></div>
                    <div class="stat-value"><?= $low_stock ?></div>
                    <div class="stat-sub"><?= $low_stock === 0 ? 'All stock OK' : "$low_stock items low" ?></div>
                </div>
                <div class="stat-card accent-blue" style="animation-delay:.25s">
                    <div class="stat-top"><span class="stat-label">ACTIVE ORDERS</span><span class="stat-icon">🗒️</span></div>
                    <div class="stat-value"><?= $active_orders ?></div>
                    <div class="stat-sub">Processing now</div>
                </div>
            </div>

            <div class="charts-row">
                <div class="card chart-card" style="animation-delay:.3s">
                    <div class="card-header">
                        <span class="card-title">Revenue — Last 7 Days</span>
                        <a href="reports.php" class="card-link">Full Report →</a>
                    </div>
                    <div class="bar-chart">
                        <?php foreach ($revenue_last7 as $day => $val): ?>
                            <?php $pct = ($val / $max_revenue) * 100; ?>
                            <div class="bar-col">
                                <div class="bar-wrap">
                                    <div class="bar <?= $val > 0 ? 'has-value' : '' ?>"
                                        style="height:<?= max($pct, $val > 0 ? 8 : 2) ?>%"
                                        title="₱<?= number_format($val, 2) ?>"></div>
                                </div>
                                <div class="bar-label"><?= $day ?></div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>

                <div class="card products-card" style="animation-delay:.35s">
                    <div class="card-header">
                        <span class="card-title">Top Products</span>
                        <a href="reports.php" class="card-link">See All →</a>
                    </div>
                    <div class="products-list">
                        <?php if (empty($top_products)): ?>
                            <p style="color:var(--muted);font-size:.85rem;">No sales data yet.</p>
                        <?php else: ?>
                            <?php foreach ($top_products as $i => $p): ?>
                                <div class="product-row">
                                    <div class="product-rank"><?= $i + 1 ?></div>
                                    <div class="product-info">
                                        <div class="product-name"><?= htmlspecialchars($p['name']) ?></div>
                                        <div class="product-bar-wrap">
                                            <div class="product-bar" style="width:<?= $p['pct'] ?>%"></div>
                                        </div>
                                    </div>
                                    <div class="product-revenue">₱<?= number_format($p['revenue']) ?></div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <div class="card orders-card" style="animation-delay:.4s">
                <div class="card-header">
                    <span class="card-title">Recent Orders</span>
                    <a href="pos.php" class="card-link">View All →</a>
                </div>
                <table class="orders-table">
                    <thead>
                        <tr>
                            <th>ORDER ID</th>
                            <th>CASHIER</th>
                            <th>TOTAL</th>
                            <th>STATUS</th>
                            <th>TIME</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($recent_orders)): ?>
                            <tr><td colspan="5" style="text-align:center;color:var(--muted);padding:24px">No orders yet.</td></tr>
                        <?php else: ?>
                            <?php foreach ($recent_orders as $order): ?>
                                <tr>
                                    <td class="order-id">#<?= htmlspecialchars($order['order_number']) ?></td>
                                    <td><?= htmlspecialchars($order['cashier']) ?></td>
                                    <td class="order-total">₱<?= number_format($order['total'], 2) ?></td>
                                    <td>
                                        <span class="badge badge-<?= strtolower($order['status']) ?>">
                                            • <?= htmlspecialchars($order['status']) ?>
                                        </span>
                                    </td>
                                    <td class="order-time"><?= htmlspecialchars($order['time']) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

        </div>
    </main>

    </body>
    </html>
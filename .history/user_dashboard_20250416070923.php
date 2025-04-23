<?php
// USER REFERRAL DASHBOARD - user_dashboard.php
include 'includes/auth.php';
include 'includes/functions.php';
include 'includes/db.php';

// Redirect if not logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

$user_id = $_SESSION['user_id'];

// Get user information (removed created_at)
$stmt = $pdo->prepare("SELECT id, name, email, referral_code FROM users WHERE id = ?");
$stmt->execute([$user_id]);
$user = $stmt->fetch();

// If user doesn't have a referral code, generate one
if (empty($user['referral_code'])) {
    $referral_code = generate_referral_code($user['id']);
    $stmt = $pdo->prepare("UPDATE users SET referral_code = ? WHERE id = ?");
    $stmt->execute([$referral_code, $user['id']]);
    $user['referral_code'] = $referral_code;
}

// Get personal contribution statistics (removed last_contribution_date)
$stmt = $pdo->prepare("SELECT 
                      COUNT(*) as total_contributions,
                      SUM(amount) as total_amount,
                      AVG(amount) as average_amount,
                      MAX(amount) as largest_contribution,
                      MIN(amount) as smallest_contribution
                      FROM contributions
                      WHERE user_id = ?");
$stmt->execute([$user_id]);
$personal_stats = $stmt->fetch();

// Get referral statistics
$stmt = $pdo->prepare("WITH RECURSIVE RefTree AS (
                        SELECT id, name, referred_by, 0 as level
                        FROM users 
                        WHERE id = ?
                        
                        UNION ALL
                        
                        SELECT u.id, u.name, u.referred_by, rt.level + 1
                        FROM users u
                        JOIN RefTree rt ON u.referred_by = rt.id
                      )
                      SELECT 
                        COUNT(*) - 1 as total_referrals,
                        MAX(level) as max_depth
                      FROM RefTree");
$stmt->execute([$user_id]);
$referral_stats = $stmt->fetch();

// Get direct referrals (removed created_at from SELECT and ORDER BY; ordering by u.id DESC instead)
$stmt = $pdo->prepare("SELECT 
                      u.id, 
                      u.name, 
                      u.created_at,
                      (SELECT COUNT(*) FROM users WHERE referred_by = u.id) as their_referrals,
                      (SELECT SUM(amount) FROM contributions WHERE user_id = u.id) as contributions,
                      (SELECT COUNT(*) FROM contributions WHERE user_id = u.id) as contribution_count
                      FROM users u
                      WHERE u.referred_by = ?
                      ORDER BY u.created_at DESC");

$stmt->execute([$user_id]);
$direct_referrals = $stmt->fetchAll();

// Get downline contributions (all users under this user)
$stmt = $pdo->prepare("WITH RECURSIVE RefTree AS (
                        SELECT id
                        FROM users 
                        WHERE id = ?
                        
                        UNION ALL
                        
                        SELECT u.id
                        FROM users u
                        JOIN RefTree rt ON u.referred_by = rt.id
                      )
                      SELECT 
                        SUM(c.amount) as total_downline_contributions,
                        COUNT(DISTINCT c.user_id) as contributing_users
                      FROM contributions c
                      JOIN RefTree rt ON c.user_id = rt.id
                      WHERE c.user_id != ?");
$stmt->execute([$user_id, $user_id]);
$downline_stats = $stmt->fetch();

// Get recent contributions from downline (removed created_at, ordering by c.id DESC)
$stmt = $pdo->prepare("WITH RECURSIVE RefTree AS (
                        SELECT id
                        FROM users 
                        WHERE id = ?
                        
                        UNION ALL
                        
                        SELECT u.id
                        FROM users u
                        JOIN RefTree rt ON u.referred_by = rt.id
                      )
                      SELECT 
                        c.id,
                        c.amount,
                        c.created_at,
                        u.name,
                        (SELECT name FROM users WHERE id = u.referred_by) as referred_by
                      FROM contributions c
                      JOIN users u ON c.user_id = u.id
                      JOIN RefTree rt ON c.user_id = rt.id
                      WHERE c.user_id != ?
                      ORDER BY c.created_at DESC
                      LIMIT 10");

$stmt->execute([$user_id, $user_id]);
$recent_downline_contributions = $stmt->fetchAll();

// Remove monthly contribution queries (they relied on created_at)
$stmt = $pdo->prepare("SELECT 
                      DATE_FORMAT(created_at, '%Y-%m') as month, 
                      SUM(amount) as total 
                      FROM contributions 
                      WHERE user_id = ? 
                      GROUP BY month 
                      ORDER BY month DESC");
$stmt->execute([$user_id]);
$monthly_data = $stmt->fetchAll();
$monthly_data = [];
$downline_monthly_data = [];

// Get referral tree data for visualization
// Get referral tree data for visualization (if not already fetched)
$stmt = $pdo->prepare("WITH RECURSIVE RefTree AS (
                        SELECT id
                        FROM users 
                        WHERE id = ?
                        UNION ALL
                        SELECT u.id
                        FROM users u
                        JOIN RefTree rt ON u.referred_by = rt.id
                      )
                      SELECT 
                        u.id, 
                        u.name,
                        (SELECT SUM(amount) FROM contributions WHERE user_id = u.id) as contributions,
                        u.referred_by
                      FROM users u
                      JOIN RefTree rt ON u.id = rt.id
                      WHERE u.id != ?");
$stmt->execute([$user_id, $user_id]);
$tree_data = $stmt->fetchAll(); // Now $tree_data is populated

// Create tree data for D3.js visualization
$root = [
    'id' => $user['id'],
    'name' => $user['name'],
    'contributions' => $personal_stats['total_amount'] ?? 0,
    'children' => []
];

// Build the tree structure
$nodes_by_id = [$user['id'] => &$root];
foreach ($tree_data as $node) {
    if ($node['id'] == $user['id']) continue;
    
    $node_data = [
        'id' => $node['id'],
        'name' => $node['name'],
        'contributions' => $node['contributions'] ?? 0,
        'children' => []
    ];
    
    $nodes_by_id[$node['id']] = &$node_data;
    
    if (isset($nodes_by_id[$node['referred_by']])) {
        $nodes_by_id[$node['referred_by']]['children'][] = &$node_data;
    }
}

// Convert to JSON for JavaScript
$tree_json = json_encode($root);


// Calculate base URL for referral links
$protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https://' : 'http://';
$base_url = $protocol . $_SERVER['HTTP_HOST'];

// This is the correct web path, NOT the filesystem path
$base_url .= '/church-platform/';

// Ensure there's no trailing slash
$referral_url = rtrim($base_url, '/') . '/register.php?ref=' . urlencode($user['referral_code']);


// Function to generate referral code (if not already defined in functions.php)
function generate_referral_code($user_id) {
    $prefix = substr(str_shuffle('ABCDEFGHIJKLMNOPQRSTUVWXYZ'), 0, 3);
    $suffix = substr(str_shuffle('0123456789'), 0, 4);
    return $prefix . $user_id . $suffix;
}
?>


<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Referral Dashboard - My Account</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/3.9.1/chart.min.js"></script>
    <script src="https://d3js.org/d3.v7.min.js"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
    .tree-container {
        width: 100%;
        overflow: auto;
    }

    .node circle {
        fill: #fff;
        stroke: #4f46e5;
        stroke-width: 2px;
    }

    .node text {
        font: 12px sans-serif;
    }

    .link {
        fill: none;
        stroke: #ccc;
        stroke-width: 1.5px;
    }

    .tooltip {
        position: absolute;
        background-color: rgba(255, 255, 255, 0.9);
        border: 1px solid #ddd;
        border-radius: 4px;
        padding: 8px;
        font-size: 12px;
        pointer-events: none;
        opacity: 0;
        transition: opacity 0.3s;
        box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
        z-index: 40;
    }

    /* Loading spinner */
    .spinner {
        border: 3px solid rgba(0, 0, 0, 0.1);
        border-radius: 50%;
        border-top: 3px solid #4f46e5;
        width: 24px;
        height: 24px;
        animation: spin 1s linear infinite;
        display: inline-block;
    }

    @keyframes spin {
        0% {
            transform: rotate(0deg);
        }

        100% {
            transform: rotate(360deg);
        }
    }

    /* Alert toast */
    .toast {
        position: fixed;
        top: 20px;
        right: 20px;
        padding: 1rem;
        border-radius: 0.375rem;
        background-color: white;
        box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
        transform: translateY(-100%);
        opacity: 0;
        transition: transform 0.3s, opacity 0.3s;
        z-index: 50;
    }

    .toast.show {
        transform: translateY(0);
        opacity: 1;
    }

    /* Mobile sidebar overlay */
    .sidebar-overlay {
        position: fixed;
        top: 0;
        left: 0;
        width: 100%;
        height: 100%;
        background-color: rgba(0, 0, 0, 0.5);
        z-index: 9;
        display: none;
    }

    .sidebar-overlay.active {
        display: block;
    }
    </style>
</head>

<body class="bg-gray-100 text-gray-800">
    <!-- Toast notification for copy to clipboard -->
    <div id="toast" class="toast">
        <div class="flex items-center">
            <div class="text-green-500 mr-2"><i class="fas fa-check-circle"></i></div>
            <div id="toast-message">Referral link copied to clipboard!</div>
        </div>
    </div>

    <!-- Sidebar overlay for mobile -->
    <div id="sidebar-overlay" class="sidebar-overlay"></div>

    <!-- Header -->
    <header class="bg-indigo-700 text-white p-4 flex justify-between items-center shadow fixed w-full z-10">
        <div class="flex items-center">
            <button id="sidebar-toggle" class="mr-3 text-white md:hidden">
                <i class="fas fa-bars text-xl"></i>
            </button>
            <h1 class="text-xl font-semibold">Church Contributions</h1>
        </div>
        <div class="flex items-center space-x-4">
            <span class="hidden md:inline">Welcome, <?php echo htmlspecialchars($user['name']); ?></span>
            <a href="logout.php"
                class="bg-white text-indigo-700 px-3 py-1 rounded hover:bg-gray-100 transition text-sm md:px-4 md:py-2 md:text-base">Logout</a>
        </div>
    </header>

    <div class="flex pt-16">
        <!-- Sidebar -->
        <aside id="sidebar"
            class="bg-indigo-800 text-white w-64 min-h-screen fixed z-20 transition-transform duration-300 ease-in-out transform -translate-x-full md:translate-x-0 overflow-y-auto">
            <div class="p-4">
                <div class="flex justify-between items-center md:hidden mb-4">
                    <h2 class="text-lg font-semibold">Menu</h2>
                    <button id="close-sidebar" class="text-white">
                        <i class="fas fa-times text-xl"></i>
                    </button>
                </div>
                <nav>
                    <ul class="space-y-2">
                        <li>
                            <a href="user_dashboard.php"
                                class="flex items-center p-2 rounded hover:bg-indigo-700 transition">
                                <i class="fas fa-tachometer-alt mr-2"></i> Dashboard
                            </a>
                        </li>
                        <li>
                            <a href="referral_tree.php" class="flex items-center p-2 rounded bg-indigo-700 transition">
                                <i class="fas fa-sitemap mr-2"></i> Referral Tree
                            </a>
                        </li>
                        <li>
                            <a href="contributions.php"
                                class="flex items-center p-2 rounded hover:bg-indigo-700 transition">
                                <i class="fas fa-chart-line mr-2"></i> Contributions
                            </a>
                        </li>
                        <li>
                            <a href="settings.php" class="flex items-center p-2 rounded hover:bg-indigo-700 transition">
                                <i class="fas fa-cog mr-2"></i> Settings
                            </a>
                        </li>
                        <li>
                            <a href="logout.php" class="flex items-center p-2 rounded hover:bg-indigo-700 transition">
                                <i class="fas fa-sign-out-alt mr-2"></i> Logout
                            </a>
                        </li>
                    </ul>
                </nav>
            </div>
        </aside>

        <!-- Main Content -->
        <main class="flex-1 md:ml-64 p-4 md:p-6">
            <div class="mb-8">
                <h2 class="text-2xl font-bold mb-6">My Dashboard</h2>



                <!-- PayPal Donation Form -->
                <div class="bg-white p-6 rounded-lg shadow-lg mt-6">
                    <h2 class="text-xl font-medium text-indigo-700 mb-4">Contribute via PayPal</h2>
                    <form action="https://www.paypal.com/cgi-bin/webscr" method="post" target="_blank"
                        class="space-y-4">
                        <input type="hidden" name="cmd" value="_donations">
                        <input type="hidden" name="business" value="vkwamboka52@gmail.com">
                        <input type="hidden" name="currency_code" value="USD">
                        <input type="hidden" name="item_name" value="Church Contribution">
                        <div>
                            <label for="paypal_amount" class="block text-sm font-medium text-gray-600">Amount in
                                USD</label>
                            <input type="number" name="amount" id="paypal_amount" placeholder="Enter amount in USD"
                                class="mt-2 block w-full px-4 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-indigo-500"
                                required>
                        </div>
                        <button type="submit"
                            class="w-full py-2 px-4 bg-indigo-700 text-white rounded-lg hover:bg-indigo-800 focus:outline-none focus:ring-2 focus:ring-indigo-500">Pay
                            with PayPal</button>
                    </form>
                </div>

            </div>

            <!-- Overview Cards -->
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 md:gap-6 mb-8">
                <div class="bg-white p-4 md:p-6 rounded-lg shadow-md">
                    <div class="flex justify-between items-start">
                        <div>
                            <h3 class="text-base md:text-lg font-semibold text-gray-500">Personal Contributions</h3>
                            <p class="text-2xl md:text-3xl font-bold">
                                $<?php echo number_format($personal_stats['total_amount'] ?? 0, 2); ?></p>
                        </div>
                        <div class="bg-blue-100 rounded-full p-3">
                            <i class="fas fa-hand-holding-usd text-blue-500"></i>
                        </div>
                    </div>
                    <div class="mt-2 text-sm text-gray-600">
                        <?php echo number_format($personal_stats['total_contributions'] ?? 0); ?> contributions
                    </div>
                </div>

                <div class="bg-white p-4 md:p-6 rounded-lg shadow-md">
                    <div class="flex justify-between items-start">
                        <div>
                            <h3 class="text-base md:text-lg font-semibold text-gray-500">Downline Contributions</h3>
                            <p class="text-2xl md:text-3xl font-bold">
                                $<?php echo number_format($downline_stats['total_downline_contributions'] ?? 0, 2); ?>
                            </p>
                        </div>
                        <div class="bg-green-100 rounded-full p-3">
                            <i class="fas fa-users text-green-500"></i>
                        </div>
                    </div>
                    <div class="mt-2 text-sm text-gray-600">
                        <?php echo number_format($downline_stats['contributing_users'] ?? 0); ?> contributing members
                    </div>
                </div>

                <div class="bg-white p-4 md:p-6 rounded-lg shadow-md">
                    <div class="flex justify-between items-start">
                        <div>
                            <h3 class="text-base md:text-lg font-semibold text-gray-500">Total Network</h3>
                            <p class="text-2xl md:text-3xl font-bold">
                                $<?php echo number_format(($personal_stats['total_amount'] ?? 0) + ($downline_stats['total_downline_contributions'] ?? 0), 2); ?>
                            </p>
                        </div>
                        <div class="bg-purple-100 rounded-full p-3">
                            <i class="fas fa-network-wired text-purple-500"></i>
                        </div>
                    </div>
                    <div class="mt-2 text-sm text-gray-600">
                        Combined total of all contributions
                    </div>
                </div>

                <div class="bg-white p-4 md:p-6 rounded-lg shadow-md">
                    <div class="flex justify-between items-start">
                        <div>
                            <h3 class="text-base md:text-lg font-semibold text-gray-500">Your Referrals</h3>
                            <p class="text-2xl md:text-3xl font-bold">
                                <?php echo number_format($referral_stats['total_referrals'] ?? 0); ?></p>
                        </div>
                        <div class="bg-yellow-100 rounded-full p-3">
                            <i class="fas fa-user-plus text-yellow-500"></i>
                        </div>
                    </div>
                    <div class="mt-2 text-sm text-gray-600">
                        Max depth: <?php echo number_format($referral_stats['max_depth'] ?? 0); ?> levels
                    </div>
                </div>
            </div>

            <!-- Charts Row -->
            <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-8">
                <!-- Personal Contributions Chart -->
                <div class="bg-white p-4 md:p-6 rounded-lg shadow-md">
                    <h3 class="text-lg font-semibold mb-4">Monthly Contributions</h3>
                    <div class="h-64 md:h-80">
                        <canvas id="contributionsChart"></canvas>
                    </div>
                </div>

                <!-- Network Contributions Chart -->
                <div class="bg-white p-4 md:p-6 rounded-lg shadow-md">
                    <h3 class="text-lg font-semibold mb-4">Network Performance</h3>
                    <div class="h-64 md:h-80">
                        <canvas id="networkChart"></canvas>
                    </div>
                </div>
            </div>
            <!-- Referral Tree Visualization -->
            <section class="bg-white p-4 md:p-6 rounded-lg shadow mb-6">
                <h2 class="text-xl font-semibold mb-4">My Network Tree</h2>
                <div id="treeChart" class="w-full h-64 md:h-96 bg-gray-50 rounded border overflow-auto"></div>
            </section>

            <!-- Direct Referrals Table -->
            <div class="bg-white p-4 md:p-6 rounded-lg shadow-md mb-8">
                <h3 class="text-lg font-semibold mb-4">Direct Referrals</h3>
                <?php if (empty($direct_referrals)): ?>
                <div class="text-center py-8 text-gray-500">
                    <i class="fas fa-users text-4xl mb-3"></i>
                    <p>You haven't referred anyone yet.</p>
                    <p class="mt-2">Share your referral link to grow your network!</p>
                </div>
                <?php else: ?>
                <div class="overflow-x-auto -mx-4 md:mx-0">
                    <table class="min-w-full bg-white">
                        <thead class="bg-gray-100">
                            <tr>
                                <th class="py-2 px-3 md:py-3 md:px-4 text-left text-xs md:text-sm">Name</th>
                                <th class="py-2 px-3 md:py-3 md:px-4 text-left text-xs md:text-sm">Date Joined</th>
                                <th class="py-2 px-3 md:py-3 md:px-4 text-right text-xs md:text-sm">Their Referrals</th>
                                <th class="py-2 px-3 md:py-3 md:px-4 text-right text-xs md:text-sm">Contributions</th>
                                <th class="py-2 px-3 md:py-3 md:px-4 text-right text-xs md:text-sm">Average</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-200">
                            <?php foreach ($direct_referrals as $referral): ?>
                            <tr class="hover:bg-gray-50">
                                <td class="py-2 px-3 md:py-3 md:px-4 text-xs md:text-sm">
                                    <?php echo htmlspecialchars($referral['name']); ?></td>
                                <td class="py-2 px-3 md:py-3 md:px-4 text-xs md:text-sm">
                                    <?php echo date('M j, Y', strtotime($referral['created_at'])); ?></td>
                                <td class="py-2 px-3 md:py-3 md:px-4 text-right text-xs md:text-sm">
                                    <?php echo number_format($referral['their_referrals']); ?></td>
                                <td class="py-2 px-3 md:py-3 md:px-4 text-right text-xs md:text-sm">
                                    $<?php echo number_format($referral['contributions'] ?? 0, 2); ?></td>
                                <td class="py-2 px-3 md:py-3 md:px-4 text-right text-xs md:text-sm">
                                    $<?php 
                      echo $referral['contribution_count'] > 0 
                           ? number_format(($referral['contributions'] ?? 0) / $referral['contribution_count'], 2)
                           : '0.00';
                    ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php endif; ?>
            </div>
            <!-- Referral Link Generator -->
            <div class="bg-white p-4 md:p-6 rounded-lg shadow-md mb-8">
                <h3 class="text-lg font-semibold mb-4">My Invite Link</h3>
                <p class="mb-4 text-gray-600">Share your unique invite link with friends and family to grow your
                    network.</p>

                <div class="mb-6">
                    <div class="flex flex-col md:flex-row md:items-center md:space-x-2 mb-2">
                        <input id="referral-link" type="text" value="<?php echo htmlspecialchars($referral_url); ?>"
                            class="w-full p-3 border border-gray-300 rounded-lg bg-gray-50 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500 mb-2 md:mb-0">
                        <button id="copy-link"
                            class="bg-indigo-600 text-white px-4 py-3 rounded-lg hover:bg-indigo-700 focus:outline-none focus:ring-2 focus:ring-indigo-500 transition whitespace-nowrap">
                            <i class="fas fa-copy mr-2"></i> Copy
                        </button>
                    </div>
                    <div class="text-sm text-gray-500">Your Invite code: <span
                            class="font-mono font-bold"><?php echo htmlspecialchars($user['referral_code']); ?></span>
                    </div>
                </div>

                <div>
                    <h4 class="text-md font-medium mb-3">Share directly:</h4>
                    <div class="flex flex-wrap gap-3">
                        <a href="https://wa.me/?text=<?php echo urlencode('Join me on XYZ Platform! Use my referral link: ' . $referral_url); ?>"
                            target="_blank"
                            class="bg-green-500 text-white px-3 py-2 rounded-lg hover:bg-green-600 transition flex items-center text-sm">
                            <i class="fab fa-whatsapp mr-2"></i> WhatsApp
                        </a>
                        <a href="https://www.facebook.com/sharer/sharer.php?u=<?php echo urlencode($referral_url); ?>"
                            target="_blank"
                            class="bg-blue-600 text-white px-3 py-2 rounded-lg hover:bg-blue-700 transition flex items-center text-sm">
                            <i class="fab fa-facebook-f mr-2"></i> Facebook
                        </a>
                        <a href="https://twitter.com/intent/tweet?text=<?php echo urlencode('Join me on XYZ Platform! Use my referral link: ' . $referral_url); ?>"
                            target="_blank"
                            class="bg-blue-400 text-white px-3 py-2 rounded-lg hover:bg-blue-500 transition flex items-center text-sm">
                            <i class="fab fa-twitter mr-2"></i> Twitter
                        </a>
                        <a href="mailto:?subject=<?php echo urlencode('Join me on XYZ Platform'); ?>&body=<?php echo urlencode('Hey! I thought you might be interested in XYZ Platform. Use my referral link to sign up: ' . $referral_url); ?>"
                            class="bg-gray-600 text-white px-3 py-2 rounded-lg hover:bg-gray-700 transition flex items-center text-sm">
                            <i class="fas fa-envelope mr-2"></i> Email
                        </a>
                        <a href="sms:?body=<?php echo urlencode('Join me on XYZ Platform! Use my referral link: ' . $referral_url); ?>"
                            class="bg-purple-600 text-white px-3 py-2 rounded-lg hover:bg-purple-700 transition flex items-center text-sm">
                            <i class="fas fa-comment mr-2"></i> SMS
                        </a>
                    </div>
                </div>
            </div>

            <!-- Recent Downline Contributions -->
            <div class="bg-white p-4 md:p-6 rounded-lg shadow-md">
                <h3 class="text-lg font-semibold mb-4">Recent Downline Activity</h3>
                <?php if (empty($recent_downline_contributions)): ?>
                <div class="text-center py-8 text-gray-500">
                    <i class="fas fa-chart-line text-4xl mb-3"></i>
                    <p>No contributions from your downline yet.</p>
                    <p class="mt-2">As your network grows, you'll see their activity here.</p>
                </div>
                <?php else: ?>
                <div class="overflow-x-auto -mx-4 md:mx-0">
                    <table class="min-w-full bg-white">
                        <thead class="bg-gray-100">
                            <tr>
                                <th class="py-2 px-3 md:py-3 md:px-4 text-left text-xs md:text-sm">User</th>
                                <th class="py-2 px-3 md:py-3 md:px-4 text-left text-xs md:text-sm">Referred By</th>
                                <th class="py-2 px-3 md:py-3 md:px-4 text-right text-xs md:text-sm">Amount</th>
                                <th class="py-2 px-3 md:py-3 md:px-4 text-right text-xs md:text-sm">Date</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-200">
                            <?php foreach ($recent_downline_contributions as $contribution): ?>
                            <tr class="hover:bg-gray-50">
                                <td class="py-2 px-3 md:py-3 md:px-4 text-xs md:text-sm">
                                    <?php echo htmlspecialchars($contribution['name']); ?></td>
                                <td class="py-2 px-3 md:py-3 md:px-4 text-xs md:text-sm">
                                    <?php echo htmlspecialchars($contribution['referred_by']); ?></td>
                                <td class="py-2 px-3 md:py-3 md:px-4 text-right text-xs md:text-sm">
                                    $<?php echo number_format($contribution['amount'], 2); ?></td>
                                <td class="py-2 px-3 md:py-3 md:px-4 text-right text-xs md:text-sm">
                                    <?php echo date('M j, Y', strtotime($contribution['created_at'])); ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php endif; ?>
            </div>
    </div>
    </main>
    </div>

    <script>
    // Use the existing tree data from PHP
    const treeData = <?php echo $tree_json; ?>;

    // Convert flat data to hierarchy for D3.js
    function buildHierarchy(data, rootId) {
        // Create mapping of id to node data
        const map = {};
        data.forEach(item => {
            map[item.id] = {
                id: item.id,
                name: item.name,
                contribution: item.contributions || 0,
                treeContribution: item.tree_contribution || 0,
                childrenCount: (item.children || []).length,
                level: item.level || 0,
                children: []
            };
        });

        // Build the tree structure
        const rootNode = map[rootId];
        data.forEach(item => {
            if (item.id !== rootId && map[item.referred_by]) {
                map[item.referred_by].children.push(map[item.id]);
            }
        });

        return rootNode;
    }

    function renderTree() {
        // Clear previous tree visualization
        d3.select("#treeChart").html("");

        // Get dimensions of the container
        const container = document.getElementById('treeChart');
        const width = container.clientWidth;
        const height = container.clientHeight;

        // Create SVG container
        const svg = d3.select("#treeChart")
            .append("svg")
            .attr("width", width)
            .attr("height", height);

        // Create main group for the visualization with initial transform
        const g = svg.append("g")
            .attr("transform", "translate(50, 20)");

        // Handle case when treeData might be flat or already hierarchical
        let rootNode;
        if (Array.isArray(treeData)) {
            // If it's an array, it's flat data that needs to be converted
            rootNode = buildHierarchy(treeData, <?php echo $user_id; ?>);
        } else {
            // If it's an object, it's already hierarchical data
            rootNode = treeData;
        }

        // Create D3 hierarchy
        const root = d3.hierarchy(rootNode);

        // Create tree layout - adjust size for mobile and desktop
        const treeLayout = d3.tree()
            .size([
                width > 768 ? width - 100 : width - 40,
                height > 400 ? height - 60 : height - 40
            ]);

        // Assign positions to nodes
        treeLayout(root);

        // Draw links between nodes
        g.selectAll(".link")
            .data(root.links())
            .join("path")
            .attr("class", "link")
            .attr("d", d3.linkVertical()
                .x(d => d.x)
                .y(d => d.y))
            .attr("fill", "none")
            .attr("stroke", "#ccc")
            .attr("stroke-width", 1.5);

        // Create node groups
        const node = g.selectAll(".node")
            .data(root.descendants())
            .join("g")
            .attr("class", "node")
            .attr("transform", d => `translate(${d.x},${d.y})`)
            .attr("cursor", "pointer")
            .on("click", function(event, d) {
                if (d.data.id != <?php echo $user_id; ?>) {
                    window.location.href = "referral_tree.php?focus=" + d.data.id;
                }
            })
            .on("mouseover", function(event, d) {
                d3.select(this).select("circle").attr("r", 8);

                // Show tooltip - adjust positioning for mobile
                tooltip
                    .style("opacity", 1)
                    .html(`
              <strong>${d.data.name}</strong><br>
              Direct Referrals: ${d.data.childrenCount}<br>
              Contribution: $${d.data.contribution.toFixed(2)}<br>
              Team Contribution: $${(d.data.treeContribution || d.data.contribution).toFixed(2)}
            `)
                    .style("left", (event.pageX + 10) + "px")
                    .style("top", (event.pageY - 28) + "px");
            })
            .on("mouseout", function() {
                d3.select(this).select("circle").attr("r", 6);
                tooltip.style("opacity", 0);
            });

        // Add circles to nodes
        node.append("circle")
            .attr("r", 6)
            .attr("fill", d => {
                // Color based on level and contribution
                if (d.data.id == <?php echo $user_id; ?>) return "#4F46E5"; // Current focus
                if (d.data.treeContribution > 1000 || d.data.contribution > 1000)
            return "#10B981"; // High contribution
                return "#6366F1"; // Default
            })
            .attr("stroke", "#fff")
            .attr("stroke-width", 1.5);

        // Add labels to nodes - adjust for screen size
        node.append("text")
            .attr("dy", ".31em")
            .attr("x", d => d.children ? -8 : 8)
            .attr("text-anchor", d => d.children ? "end" : "start")
            .text(d => {
                // Truncate names on small screens
                const name = d.data.name;
                if (width < 640 && name.length > 10) {
                    return name.substring(0, 8) + '...';
                }
                return name;
            })
            .style("font-size", width < 640 ? "10px" : "12px")
            .style("fill", "#4B5563");

        // Create tooltip if it doesn't exist
        let tooltip = d3.select("body").select(".tooltip");
        if (tooltip.empty()) {
            tooltip = d3.select("body").append("div")
                .attr("class", "tooltip")
                .style("opacity", 0);
        }

        // Add zoom behavior optimized for touch devices
        const zoom = d3.zoom()
            .scaleExtent([0.5, 3])
            .on("zoom", (event) => {
                g.attr("transform", event.transform);
            });

        svg.call(zoom)
            .on("dblclick.zoom", null); // Disable double-click zoom for better mobile experience
    }

    // Initialize the tree visualization on page load
    document.addEventListener('DOMContentLoaded', function() {
        renderTree();
        setupCharts();
        setupEventListeners();
    });

    function setupCharts() {
        // Set up chart options with responsive configs
        const chartOptions = {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: {
                    position: 'top',
                    labels: {
                        boxWidth: 12,
                        font: {
                            size: window.innerWidth < 768 ? 10 : 12
                        }
                    }
                },
                tooltip: {
                    callbacks: {
                        label: function(context) {
                            let label = context.dataset.label || '';
                            if (label) {
                                label += ': ';
                            }
                            if (context.parsed.y !== null) {
                                label += new Intl.NumberFormat('en-US', {
                                    style: 'currency',
                                    currency: 'USD'
                                }).format(context.parsed.y);
                            }
                            return label;
                        }
                    }
                }
            },
            scales: {
                y: {
                    beginAtZero: true,
                    ticks: {
                        callback: function(value) {
                            return '$' + value.toLocaleString();
                        },
                        font: {
                            size: window.innerWidth < 768 ? 10 : 12
                        }
                    }
                },
                x: {
                    grid: {
                        display: false
                    },
                    ticks: {
                        font: {
                            size: window.innerWidth < 768 ? 10 : 12
                        }
                    }
                }
            }
        };

        // Monthly Contributions Chart
        const contributionsCtx = document.getElementById('contributionsChart').getContext('2d');
        const contributionsData = {
            labels: ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'],
            datasets: [{
                label: 'Personal',
                data: <?php echo json_encode($personal_monthly_contributions ?? array_fill(0, 12, 0)); ?>,
                backgroundColor: 'rgba(79, 70, 229, 0.2)',
                borderColor: 'rgba(79, 70, 229, 1)',
                borderWidth: 2,
                tension: 0.1
            }]
        };
        new Chart(contributionsCtx, {
            type: 'line',
            data: contributionsData,
            options: chartOptions
        });

        // Network Performance Chart
        const networkCtx = document.getElementById('networkChart').getContext('2d');
        const networkData = {
            labels: ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'],
            datasets: [{
                    label: 'Personal',
                    data: <?php echo json_encode($personal_monthly_contributions ?? array_fill(0, 12, 0)); ?>,
                    backgroundColor: 'rgba(79, 70, 229, 0.2)',
                    borderColor: 'rgba(79, 70, 229, 1)',
                    borderWidth: 2
                },
                {
                    label: 'Downline',
                    data: <?php echo json_encode($downline_monthly_contributions ?? array_fill(0, 12, 0)); ?>,
                    backgroundColor: 'rgba(16, 185, 129, 0.2)',
                    borderColor: 'rgba(16, 185, 129, 1)',
                    borderWidth: 2
                }
            ]
        };
        new Chart(networkCtx, {
            type: 'bar',
            data: networkData,
            options: {
                ...chartOptions,
                scales: {
                    ...chartOptions.scales,
                    x: {
                        ...chartOptions.scales.x,
                        stacked: false
                    },
                    y: {
                        ...chartOptions.scales.y,
                        stacked: false
                    }
                }
            }
        });
    }

    function setupEventListeners() {
        // Copy referral link to clipboard
        document.getElementById('copy-link').addEventListener('click', function() {
            const referralLink = document.getElementById('referral-link');
            referralLink.select();
            document.execCommand('copy');

            // Show toast notification
            const toast = document.getElementById('toast');
            toast.classList.add('show');

            // Hide toast after 3 seconds
            setTimeout(() => {
                toast.classList.remove('show');
            }, 3000);
        });

        // Mobile sidebar toggle
        document.getElementById('sidebar-toggle').addEventListener('click', function() {
            const sidebar = document.getElementById('sidebar');
            const overlay = document.getElementById('sidebar-overlay');

            sidebar.classList.remove('-translate-x-full');
            overlay.classList.add('active');
        });

        // Close sidebar on mobile
        document.getElementById('close-sidebar').addEventListener('click', function() {
            const sidebar = document.getElementById('sidebar');
            const overlay = document.getElementById('sidebar-overlay');

            sidebar.classList.add('-translate-x-full');
            overlay.classList.remove('active');
        });

        // Close sidebar when clicking overlay
        document.getElementById('sidebar-overlay').addEventListener('click', function() {
            const sidebar = document.getElementById('sidebar');
            const overlay = document.getElementById('sidebar-overlay');

            sidebar.classList.add('-translate-x-full');
            overlay.classList.remove('active');
        });

        // Refresh tree visualization
        document.getElementById('refresh-tree').addEventListener('click', function() {
            const loadingIndicator = document.getElementById('loading-indicator');
            loadingIndicator.classList.remove('hidden');

            // Simulate loading (in a real app, you'd fetch from the server)
            setTimeout(() => {
                renderTree();
                loadingIndicator.classList.add('hidden');

                // Show success toast
                const toast = document.getElementById('toast');
                document.getElementById('toast-message').textContent = 'Referral tree updated!';
                toast.classList.add('show');

                setTimeout(() => {
                    toast.classList.remove('show');
                }, 3000);
            }, 1500);
        });

        // Handle window resize for responsive charts and tree
        let resizeTimeout;
        window.addEventListener('resize', function() {
            clearTimeout(resizeTimeout);
            resizeTimeout = setTimeout(function() {
                renderTree();
                setupCharts();
            }, 250);
        });
    }
    </script>

</body>

</html>
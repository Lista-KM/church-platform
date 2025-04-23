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

// Get user information
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

// Get all available projects for the filter dropdown
$stmt = $pdo->prepare("SELECT id, name FROM projects ORDER BY name ASC");
$stmt->execute();
$projects = $stmt->fetchAll();

// Handle project filter
$project_filter = null;
if (isset($_GET['project']) && is_numeric($_GET['project'])) {
    $project_filter = (int)$_GET['project'];
}

// Get personal contribution statistics
$sql_personal_stats = "SELECT 
                      COUNT(*) as total_contributions,
                      SUM(amount) as total_amount,
                      AVG(amount) as average_amount,
                      MAX(amount) as largest_contribution,
                      MIN(amount) as smallest_contribution
                      FROM contributions
                      WHERE user_id = ?";

// Add project filter if selected
$params = [$user_id];
if ($project_filter !== null) {
    $sql_personal_stats .= " AND project_id = ?";
    $params[] = $project_filter;
}

$stmt = $pdo->prepare($sql_personal_stats);
$stmt->execute($params);
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

// Get direct referrals
$sql_direct_referrals = "SELECT 
                      u.id, 
                      u.name, 
                      u.created_at,
                      (SELECT COUNT(*) FROM users WHERE referred_by = u.id) as their_referrals";

// For contributions count and amount, respect project filter if selected
if ($project_filter !== null) {
    $sql_direct_referrals .= ", 
                      (SELECT SUM(amount) FROM contributions WHERE user_id = u.id AND project_id = ?) as contributions,
                      (SELECT COUNT(*) FROM contributions WHERE user_id = u.id AND project_id = ?) as contribution_count";
} else {
    $sql_direct_referrals .= ", 
                      (SELECT SUM(amount) FROM contributions WHERE user_id = u.id) as contributions,
                      (SELECT COUNT(*) FROM contributions WHERE user_id = u.id) as contribution_count";
}

$sql_direct_referrals .= " FROM users u
                      WHERE u.referred_by = ?
                      ORDER BY u.created_at DESC";

$params = [];
if ($project_filter !== null) {
    $params = [$project_filter, $project_filter, $user_id];
} else {
    $params = [$user_id];
}

$stmt = $pdo->prepare($sql_direct_referrals);
$stmt->execute($params);
$direct_referrals = $stmt->fetchAll();

// Get downline contributions (all users under this user)
$sql_downline = "WITH RECURSIVE RefTree AS (
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
                      WHERE c.user_id != ?";

// Add project filter if selected
if ($project_filter !== null) {
    $sql_downline .= " AND c.project_id = ?";
    $params = [$user_id, $user_id, $project_filter];
} else {
    $params = [$user_id, $user_id];
}

$stmt = $pdo->prepare($sql_downline);
$stmt->execute($params);
$downline_stats = $stmt->fetch();

// Get recent contributions from downline
$sql_recent = "WITH RECURSIVE RefTree AS (
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
                        p.name as project_name,
                        (SELECT name FROM users WHERE id = u.referred_by) as referred_by
                      FROM contributions c
                      JOIN users u ON c.user_id = u.id
                      JOIN projects p ON c.project_id = p.id
                      JOIN RefTree rt ON c.user_id = rt.id
                      WHERE c.user_id != ?";

// Add project filter if selected
if ($project_filter !== null) {
    $sql_recent .= " AND c.project_id = ?";
    $params = [$user_id, $user_id, $project_filter];
} else {
    $params = [$user_id, $user_id];
}

$sql_recent .= " ORDER BY c.created_at DESC LIMIT 10";

$stmt = $pdo->prepare($sql_recent);
$stmt->execute($params);
$recent_downline_contributions = $stmt->fetchAll();

// Monthly data arrays are set empty as per original code
$monthly_data = [];
$downline_monthly_data = [];

// Get referral tree data for visualization
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
$tree_data = $stmt->fetchAll();

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

// If your app is in a subdirectory, uncomment and modify this line:
$base_url .= '/church-platform';

// Ensure there's no duplicate slash
$referral_url = rtrim($base_url, '/') . '/landing.php?ref=' . urlencode($user['referral_code']);

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

                <!-- Project Filter -->
                <div class="bg-white p-4 md:p-6 rounded-lg shadow-md mb-6">
                    <form action="" method="get" class="flex flex-col md:flex-row md:items-center">
                        <div class="flex-grow mb-3 md:mb-0 md:mr-4">
                            <label for="project" class="block text-sm font-medium text-gray-600 mb-1">Filter by
                                Project:</label>
                            <select name="project" id="project"
                                class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-indigo-500">
                                <option value="">All Projects</option>
                                <?php foreach ($projects as $project): ?>
                                <option value="<?php echo $project['id']; ?>"
                                    <?php if ($project_filter == $project['id']) echo 'selected'; ?>>
                                    <?php echo htmlspecialchars($project['name']); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="flex-shrink-0 self-end md:self-auto">
                            <button type="submit"
                                class="w-full md:w-auto bg-indigo-600 text-white px-4 py-2 rounded-lg hover:bg-indigo-700 transition focus:outline-none focus:ring-2 focus:ring-indigo-500">
                                <i class="fas fa-filter mr-2"></i> Apply Filter
                            </button>
                            <?php if ($project_filter !== null): ?>
                            <a href="user_dashboard.php"
                                class="mt-2 md:mt-0 md:ml-2 inline-block text-center text-indigo-600 hover:text-indigo-800 text-sm">
                                <i class="fas fa-times mr-1"></i> Clear Filter
                            </a>
                            <?php endif; ?>
                        </div>
                    </form>
                </div>

                <!-- PayPal Donation Form -->
                <div class="bg-white p-6 rounded-lg shadow-lg mt-6">
                    <h2 class="text-xl font-medium text-indigo-700 mb-4">Contribute via PayPal</h2>
                    <form action="https://www.paypal.com/cgi-bin/webscr" method="post" target="_blank"
                        class="space-y-4">
                        <input type="hidden" name="cmd" value="_donations">
                        <input type="hidden" name="business" value="vkwamboka52@gmail.com">
                        <input type="hidden" name="currency_code" value="USD">
                        <input type="hidden" name="item_name" value="Church Contribution">
                        <!-- Add project selection to the form -->
                        <div>
                            <label for="donation_project" class="block text-sm font-medium text-gray-600">Select
                                Project</label>
                            <select name="item_name" id="donation_project"
                                class="mt-2 block w-full px-4 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-indigo-500">
                                <?php foreach ($projects as $project): ?>
                                <option value="<?php echo htmlspecialchars($project['name']); ?>">
                                    <?php echo htmlspecialchars($project['name']); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
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
                        <?php if ($project_filter !== null): ?>
                        <span class="ml-1 text-indigo-600">
                            (filtered)
                        </span>
                        <?php endif; ?>
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
                        <?php if ($project_filter !== null): ?>
                        <span class="ml-1 text-indigo-600">
                            (filtered)
                        </span>
                        <?php endif; ?>
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
                        <?php if ($project_filter !== null): ?>
                        <span class="ml-1 text-indigo-600">
                            (filtered)
                        </span>
                        <?php endif; ?>
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
                                <th class="py-2 px-3 md:py-3 md:px-4 text-right text-xs md:text-sm">Their Referrals
                                </th>
                                <th class="py-2 px-3 md:py-3 md:px-4 text-right text-xs md:text-sm">Contributions
                                </th>
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
                    <p class="text-sm text-gray-500">
                        Your unique referral code: <span
                            class="font-mono bg-gray-100 px-2 py-1 rounded"><?php echo $user['referral_code']; ?></span>
                    </p>
                </div>

                <!-- Social sharing options -->
                <div class="mt-4">
                    <p class="mb-2 font-medium">Share via:</p>
                    <div class="flex flex-wrap gap-2">
                        <!-- WhatsApp -->
                        <a href="https://wa.me/?text=<?php echo urlencode('Join me on Church Contributions! ' . $referral_url); ?>"
                            target="_blank"
                            class="bg-green-500 text-white px-3 py-2 rounded-lg hover:bg-green-600 transition">
                            <i class="fab fa-whatsapp mr-2"></i> WhatsApp
                        </a>
                        <!-- Facebook -->
                        <a href="https://www.facebook.com/sharer/sharer.php?u=<?php echo urlencode($referral_url); ?>"
                            target="_blank"
                            class="bg-blue-600 text-white px-3 py-2 rounded-lg hover:bg-blue-700 transition">
                            <i class="fab fa-facebook-f mr-2"></i> Facebook
                        </a>
                        <!-- Twitter -->
                        <a href="https://twitter.com/intent/tweet?text=<?php echo urlencode('Join me on Church Contributions! ' . $referral_url); ?>"
                            target="_blank"
                            class="bg-blue-400 text-white px-3 py-2 rounded-lg hover:bg-blue-500 transition">
                            <i class="fab fa-twitter mr-2"></i> Twitter
                        </a>
                        <!-- Email -->
                        <a href="mailto:?subject=Join%20me%20on%20Church%20Contributions&body=<?php echo urlencode('I\'ve been using Church Contributions and thought you might be interested. Sign up using my referral link: ' . $referral_url); ?>"
                            class="bg-gray-500 text-white px-3 py-2 rounded-lg hover:bg-gray-600 transition">
                            <i class="fas fa-envelope mr-2"></i> Email
                        </a>
                    </div>
                </div>
            </div>

            <!-- Recent Activity -->
            <div class="bg-white p-4 md:p-6 rounded-lg shadow-md mb-8">
                <h3 class="text-lg font-semibold mb-4">Recent Downline Activity</h3>
                <?php if (empty($recent_downline_contributions)): ?>
                <div class="text-center py-8 text-gray-500">
                    <i class="fas fa-chart-line text-4xl mb-3"></i>
                    <p>No recent contributions from your network.</p>
                    <p class="mt-2">Share your referral link to grow your network!</p>
                </div>
                <?php else: ?>
                <div class="overflow-x-auto -mx-4 md:mx-0">
                    <table class="min-w-full bg-white">
                        <thead class="bg-gray-100">
                            <tr>
                                <th class="py-2 px-3 md:py-3 md:px-4 text-left text-xs md:text-sm">Date</th>
                                <th class="py-2 px-3 md:py-3 md:px-4 text-left text-xs md:text-sm">Name</th>
                                <th class="py-2 px-3 md:py-3 md:px-4 text-left text-xs md:text-sm">Project</th>
                                <th class="py-2 px-3 md:py-3 md:px-4 text-left text-xs md:text-sm">Referred By</th>
                                <th class="py-2 px-3 md:py-3 md:px-4 text-right text-xs md:text-sm">Amount</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-200">
                            <?php foreach ($recent_downline_contributions as $contribution): ?>
                            <tr class="hover:bg-gray-50">
                                <td class="py-2 px-3 md:py-3 md:px-4 text-xs md:text-sm">
                                    <?php echo date('M j, Y', strtotime($contribution['created_at'])); ?></td>
                                <td class="py-2 px-3 md:py-3 md:px-4 text-xs md:text-sm">
                                    <?php echo htmlspecialchars($contribution['name']); ?></td>
                                <td class="py-2 px-3 md:py-3 md:px-4 text-xs md:text-sm">
                                    <?php echo htmlspecialchars($contribution['project_name']); ?></td>
                                <td class="py-2 px-3 md:py-3 md:px-4 text-xs md:text-sm">
                                    <?php echo $contribution['referred_by'] ? htmlspecialchars($contribution['referred_by']) : 'Direct'; ?>
                                </td>
                                <td class="py-2 px-3 md:py-3 md:px-4 text-right text-xs md:text-sm font-medium">
                                    $<?php echo number_format($contribution['amount'], 2); ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php if ($project_filter !== null): ?>
                <div class="mt-4 text-center text-sm text-indigo-600">
                    <span class="italic">Results are filtered by project</span>
                </div>
                <?php endif; ?>
                <?php endif; ?>
            </div>

            <!-- Visualization Section -->
            <div class="grid grid-cols-1 lg:grid-cols-2 gap-8 mb-8">
                <!-- Contribution Tree Visualization -->
                <div class="bg-white p-4 md:p-6 rounded-lg shadow-md h-full">
                    <h3 class="text-lg font-semibold mb-4">My Referral Network</h3>
                    <div class="tree-container h-80">
                        <div id="referral-tree"
                            class="w-full h-full bg-gray-50 rounded-lg border border-gray-200 relative flex items-center justify-center">
                            <div class="spinner"></div>
                            <div class="text-gray-500 hidden" id="loading-text">Loading visualization...</div>
                        </div>
                    </div>
                    <?php if ($project_filter !== null): ?>
                    <div class="mt-4 text-center text-sm text-indigo-600">
                        <span class="italic">Note: Network visualization shows all contributions regardless of project
                            filter</span>
                    </div>
                    <?php endif; ?>
                </div>

                <!-- Monthly Contributions Chart -->
                <div class="bg-white p-4 md:p-6 rounded-lg shadow-md h-full">
                    <h3 class="text-lg font-semibold mb-4">Contribution Trends</h3>
                    <div class="h-80">
                        <canvas id="contributions-chart"></canvas>
                    </div>
                    <?php if ($project_filter !== null): ?>
                    <div class="mt-4 text-center text-sm text-indigo-600">
                        <span class="italic">Chart shows data for selected project only</span>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </main>
    </div>

    <script>
    // JavaScript for handling mobile sidebar
    document.addEventListener('DOMContentLoaded', function() {
        const sidebar = document.getElementById('sidebar');
        const sidebarToggle = document.getElementById('sidebar-toggle');
        const closeSidebar = document.getElementById('close-sidebar');
        const overlay = document.getElementById('sidebar-overlay');

        sidebarToggle.addEventListener('click', function() {
            sidebar.classList.remove('-translate-x-full');
            overlay.classList.add('active');
        });

        function hideSidebar() {
            sidebar.classList.add('-translate-x-full');
            overlay.classList.remove('active');
        }

        closeSidebar.addEventListener('click', hideSidebar);
        overlay.addEventListener('click', hideSidebar);

        // Copy referral link to clipboard
        const copyButton = document.getElementById('copy-link');
        const referralLink = document.getElementById('referral-link');
        const toast = document.getElementById('toast');

        copyButton.addEventListener('click', function() {
            referralLink.select();
            document.execCommand('copy');

            // Show toast notification
            toast.classList.add('show');
            setTimeout(function() {
                toast.classList.remove('show');
            }, 3000);
        });

        // Show loading text after spinner has been visible for a moment
        setTimeout(function() {
            document.getElementById('loading-text').classList.remove('hidden');
        }, 1000);

        // D3.js Visualization for Referral Tree
        const treeData = <?php echo $tree_json; ?>;

        const margin = {
                top: 20,
                right: 90,
                bottom: 30,
                left: 90
            },
            width = 800 - margin.left - margin.right,
            height = 320 - margin.top - margin.bottom;

        const svg = d3.select("#referral-tree").append("svg")
            .attr("width", "100%")
            .attr("height", "100%")
            .attr("viewBox", `0 0 ${width + margin.left + margin.right} ${height + margin.top + margin.bottom}`)
            .append("g")
            .attr("transform", "translate(" + margin.left + "," + margin.top + ")");

        const tooltip = d3.select("#referral-tree").append("div")
            .attr("class", "tooltip");

        const i = 0;
        const duration = 750;

        // Assigns parent, children, height, depth
        const root = d3.hierarchy(treeData, d => d.children);
        root.x0 = height / 2;
        root.y0 = 0;

        // Tree layout
        const tree = d3.tree().size([height, width]);

        update(root);

        function update(source) {
            // Compute the new tree layout
            const treeLayout = tree(root);

            const nodes = treeLayout.descendants(),
                links = treeLayout.descendants().slice(1);

            // Normalize for fixed-depth
            nodes.forEach(d => {
                d.y = d.depth * 180
            });

            // ****************** Nodes section ***************************

            // Update the nodes...
            const node = svg.selectAll('g.node')
                .data(nodes, d => d.id || (d.id = ++i));

            // Enter any new nodes at the parent's previous position
            const nodeEnter = node.enter().append('g')
                .attr('class', 'node')
                .attr("transform", d => "translate(" + source.y0 + "," + source.x0 + ")")
                .on('mouseover', function(event, d) {
                    tooltip.transition()
                        .duration(200)
                        .style("opacity", .9);
                    tooltip.html(
                            `<strong>${d.data.name}</strong><br>Contributions: $${d.data.contributions.toFixed(2)}`
                            )
                        .style("left", (event.pageX - 170) + "px")
                        .style("top", (event.pageY - 60) + "px");
                })
                .on('mouseout', function() {
                    tooltip.transition()
                        .duration(500)
                        .style("opacity", 0);
                });

            // Add Circle for the nodes
            nodeEnter.append('circle')
                .attr('r', 1e-6)
                .style("fill", d => d._children ? "#4f46e5" : "#fff")
                .style("stroke", "#4f46e5");

            // Add labels for the nodes
            nodeEnter.append('text')
                .attr("dy", ".35em")
                .attr("x", d => d.children || d._children ? -13 : 13)
                .attr("text-anchor", d => d.children || d._children ? "end" : "start")
                .text(d => d.data.name.split(' ')[0]); // Just show first name to save space

            // UPDATE
            const nodeUpdate = nodeEnter.merge(node);

            // Transition to the proper position for the node
            nodeUpdate.transition()
                .duration(duration)
                .attr("transform", d => "translate(" + d.y + "," + d.x + ")");

            // Update the node attributes and style
            nodeUpdate.select('circle')
                .attr('r', d => {
                    // Size nodes based on contribution amount (min 5, max 15)
                    const minSize = 5;
                    const maxSize = 15;
                    const amount = d.data.contributions || 0;
                    if (amount <= 0) return minSize;
                    return Math.min(maxSize, minSize + (amount / 100));
                })
                .style("fill", d => d._children ? "#4f46e5" : "#fff")
                .attr('cursor', 'pointer');

            // Remove any exiting nodes
            const nodeExit = node.exit().transition()
                .duration(duration)
                .attr("transform", d => "translate(" + source.y + "," + source.x + ")")
                .remove();

            // On exit reduce the node circles size to 0
            nodeExit.select('circle')
                .attr('r', 1e-6);

            // On exit reduce the opacity of text labels
            nodeExit.select('text')
                .style('fill-opacity', 1e-6);

            // ****************** links section ***************************

            // Update the links...
            const link = svg.selectAll('path.link')
                .data(links, d => d.id);

            // Enter any new links at the parent's previous position
            const linkEnter = link.enter().insert('path', "g")
                .attr("class", "link")
                .attr('d', d => {
                    const o = {
                        x: source.x0,
                        y: source.y0
                    };
                    return diagonal(o, o);
                });

            // UPDATE
            const linkUpdate = linkEnter.merge(link);

            // Transition back to the parent element position
            linkUpdate.transition()
                .duration(duration)
                .attr('d', d => diagonal(d, d.parent));

            // Remove any exiting links
            link.exit().transition()
                .duration(duration)
                .attr('d', d => {
                    const o = {
                        x: source.x,
                        y: source.y
                    };
                    return diagonal(o, o);
                })
                .remove();

            // Store the old positions for transition
            nodes.forEach(d => {
                d.x0 = d.x;
                d.y0 = d.y;
            });

            // Creates a curved (diagonal) path from parent to the child nodes
            function diagonal(s, d) {
                return `M ${s.y} ${s.x}
                        C ${(s.y + d.y) / 2} ${s.x},
                          ${(s.y + d.y) / 2} ${d.x},
                          ${d.y} ${d.x}`;
            }

            // Hide the loading spinner once done
            document.querySelector('.spinner').style.display = 'none';
            document.getElementById('loading-text').classList.add('hidden');
        }

        // Chart.js for Contribution Trends
        const ctx = document.getElementById('contributions-chart').getContext('2d');

        // Example data - you should replace this with actual data from PHP
        const monthlyLabels = <?php 
            // Get monthly labels for the last 6 months
            $labels = [];
            for ($i = 5; $i >= 0; $i--) {
                $month = date('M', strtotime("-$i months"));
                $labels[] = $month;
            }
            echo json_encode($labels); 
        ?>;

        const personalData = <?php 
            // This should be calculated from your database
            $data = [0, 0, 0, 0, 0, 0]; // Placeholder
            echo json_encode($data); 
        ?>;

        const downlineData = <?php 
            // This should be calculated from your database
            $data = [0, 0, 0, 0, 0, 0]; // Placeholder
            echo json_encode($data); 
        ?>;

        new Chart(ctx, {
            type: 'line',
            data: {
                labels: monthlyLabels,
                datasets: [{
                    label: 'Personal Contributions',
                    data: personalData,
                    backgroundColor: 'rgba(79, 70, 229, 0.2)',
                    borderColor: 'rgba(79, 70, 229, 1)',
                    borderWidth: 2,
                    tension: 0.3,
                    pointRadius: 4
                }, {
                    label: 'Downline Contributions',
                    data: downlineData,
                    backgroundColor: 'rgba(16, 185, 129, 0.2)',
                    borderColor: 'rgba(16, 185, 129, 1)',
                    borderWidth: 2,
                    tension: 0.3,
                    pointRadius: 4
                }]
            },
            options: {
                plugins: {
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
                    },
                    legend: {
                        position: 'top',
                    },
                    title: {
                        display: false
                    }
                },
                responsive: true,
                maintainAspectRatio: false,
                scales: {
                    y: {
                        beginAtZero: true,
                        ticks: {
                            callback: function(value) {
                                return '$' + value;
                            }
                        }
                    }
                }
            }
        });

        // Auto-submit form when project selection changes
        document.getElementById('project').addEventListener('change', function() {
            this.form.submit();
        });
    });
    </script>
</body>

</html>
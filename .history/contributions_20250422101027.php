<?php
include 'includes/auth.php';
include 'includes/functions.php';
include 'includes/db.php';
ini_set('display_errors', 1);
error_reporting(E_ALL);
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

// Generate referral code if not exists
if (empty($user['referral_code'])) {
    $referral_code = generateReferralCode();
    $stmt = $pdo->prepare("UPDATE users SET referral_code = ? WHERE id = ?");
    $stmt->execute([$referral_code, $user_id]);
    $user['referral_code'] = $referral_code;
}

$referral_link = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http") . "://$_SERVER[HTTP_HOST]/register.php?ref=" . $user['referral_code'];

// Get tree data for filtering (all users in the downline)
$stmt = $pdo->prepare("WITH RECURSIVE RefTree AS (
                        SELECT id, name, referred_by, 0 as level
                        FROM users 
                        WHERE id = ?
                        
                        UNION ALL
                        
                        SELECT u.id, u.name, u.referred_by, rt.level + 1
                        FROM users u
                        JOIN RefTree rt ON u.referred_by = rt.id
                      )
                      SELECT id, name, level
                      FROM RefTree
                      WHERE id != ?
                      ORDER BY level, name");
$stmt->execute([$user_id, $user_id]);
$downline_users = $stmt->fetchAll();

// Set up filtering
$filter_user = $_GET['user_id'] ?? 'all';
$filter_period = $_GET['period'] ?? '30days';
$filter_type = $_GET['type'] ?? 'all';

// Build date filter based on period
$date_filter = '';
switch ($filter_period) {
    case '7days':
        $date_filter = "AND c.created_at >= DATE_SUB(CURRENT_DATE(), INTERVAL 7 DAY)";
        break;
    case '30days':
        $date_filter = "AND c.created_at >= DATE_SUB(CURRENT_DATE(), INTERVAL 30 DAY)";
        break;
    case '90days':
        $date_filter = "AND c.created_at >= DATE_SUB(CURRENT_DATE(), INTERVAL 90 DAY)";
        break;
    case 'year':
        $date_filter = "AND c.created_at >= DATE_SUB(CURRENT_DATE(), INTERVAL 1 YEAR)";
        break;
    case 'all':
    default:
        $date_filter = '';
        break;
}

// Build user filter
$user_filter = '';
if ($filter_user !== 'all') {
    $user_filter = "AND c.user_id = " . intval($filter_user);
}

// Get contributions with pagination
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$per_page = 10;
$offset = ($page - 1) * $per_page;

// Get total records for pagination
$stmt = $pdo->prepare("WITH RECURSIVE RefTree AS (
                        SELECT id FROM users WHERE id = ?
                        UNION ALL
                        SELECT u.id FROM users u JOIN RefTree rt ON u.referred_by = rt.id
                      )
                      SELECT COUNT(*) as total
                      FROM contributions c
                      JOIN users u ON c.user_id = u.id
                      JOIN RefTree rt ON c.user_id = rt.id
                      WHERE c.user_id != ? $date_filter $user_filter");
$stmt->execute([$user_id, $user_id]);
$total_records = $stmt->fetchColumn();

$total_pages = ceil($total_records / $per_page);

// Get contributions with pagination
$stmt = $pdo->prepare("WITH RECURSIVE RefTree AS (
                        SELECT id, name, referred_by FROM users WHERE id = ?
                        UNION ALL
                        SELECT u.id, u.name, u.referred_by 
                        FROM users u 
                        JOIN RefTree rt ON u.referred_by = rt.id
                      )
                      SELECT 
                        c.id,
                        c.amount,
                      
                        c.created_at,
                        u.name as user_name,
                        (SELECT name FROM users WHERE id = u.referred_by) as referred_by,
                        (SELECT COUNT(*) FROM users WHERE referred_by = u.id) as their_referrals
                      FROM contributions c
                      JOIN users u ON c.user_id = u.id
                      JOIN RefTree rt ON c.user_id = rt.id
                      WHERE c.user_id != ? $date_filter $user_filter
                      ORDER BY c.created_at DESC
                      LIMIT $offset, $per_page");
$stmt->execute([$user_id, $user_id]);
$contributions = $stmt->fetchAll();

// Get summary statistics
$stmt = $pdo->prepare("WITH RECURSIVE RefTree AS (
                        SELECT id FROM users WHERE id = ?
                        UNION ALL
                        SELECT u.id FROM users u JOIN RefTree rt ON u.referred_by = rt.id
                      )
                      SELECT 
                        COUNT(*) as total_contributions,
                        SUM(amount) as total_amount,
                        AVG(amount) as average_amount,
                        COUNT(DISTINCT user_id) as unique_contributors
                      FROM contributions c
                      JOIN RefTree rt ON c.user_id = rt.id
                      WHERE c.user_id != ? $date_filter $user_filter");
$stmt->execute([$user_id, $user_id]);
$summary = $stmt->fetch();

// Get user's personal contribution total
$stmt = $pdo->prepare("SELECT SUM(amount) as personal_total FROM contributions WHERE user_id = ?");
$stmt->execute([$user_id]);
$personal_contribution = $stmt->fetchColumn();

// Get combined total (personal + downline)
$combined_total = ($personal_contribution ?? 0) + ($summary['total_amount'] ?? 0);

// Get contribution by level data
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
                        rt.level,
                        COUNT(c.id) as contributions_count,
                        SUM(c.amount) as total_amount,
                        COUNT(DISTINCT c.user_id) as unique_contributors
                      FROM contributions c
                      JOIN RefTree rt ON c.user_id = rt.id
                      WHERE c.user_id != ? $date_filter
                      GROUP BY rt.level
                      ORDER BY rt.level");
$stmt->execute([$user_id, $user_id]);
$level_data = $stmt->fetchAll();

// Get top contributors
$stmt = $pdo->prepare("WITH RECURSIVE RefTree AS (
                        SELECT id FROM users WHERE id = ?
                        UNION ALL
                        SELECT u.id FROM users u JOIN RefTree rt ON u.referred_by = rt.id
                      )
                      SELECT 
                        u.id,
                        u.name,
                        COUNT(c.id) as contributions_count,
                        SUM(c.amount) as total_amount,
                        MAX(c.created_at) as last_contribution,
                        (SELECT name FROM users WHERE id = u.referred_by) as referred_by
                      FROM contributions c
                      JOIN users u ON c.user_id = u.id
                      JOIN RefTree rt ON c.user_id = rt.id
                      WHERE c.user_id != ? $date_filter
                      GROUP BY u.id
                      ORDER BY total_amount DESC
                      LIMIT 5");
$stmt->execute([$user_id, $user_id]);
$top_contributors = $stmt->fetchAll();

// Get daily contribution data for the last 30 days
$stmt = $pdo->prepare("WITH RECURSIVE RefTree AS (
                        SELECT id FROM users WHERE id = ?
                        UNION ALL
                        SELECT u.id FROM users u JOIN RefTree rt ON u.referred_by = rt.id
                      ),
                      date_range AS (
                        SELECT CURDATE() - INTERVAL (a.a + (10 * b.a) + (100 * c.a)) DAY AS date
                        FROM (SELECT 0 AS a UNION ALL SELECT 1 UNION ALL SELECT 2 UNION ALL SELECT 3 UNION ALL SELECT 4 UNION ALL SELECT 5 UNION ALL SELECT 6 UNION ALL SELECT 7 UNION ALL SELECT 8 UNION ALL SELECT 9) AS a
                        CROSS JOIN (SELECT 0 AS a UNION ALL SELECT 1 UNION ALL SELECT 2) AS b
                        CROSS JOIN (SELECT 0 AS a) AS c
                        WHERE CURDATE() - INTERVAL (a.a + (10 * b.a) + (100 * c.a)) DAY >= CURDATE() - INTERVAL 30 DAY
                      )
                      SELECT 
                        DATE_FORMAT(d.date, '%Y-%m-%d') as contribution_date,
                        COALESCE(SUM(c.amount), 0) as daily_total
                      FROM date_range d
                      LEFT JOIN contributions c ON DATE(c.created_at) = d.date AND c.user_id IN (SELECT id FROM RefTree WHERE id != ?)
                      GROUP BY d.date
                      ORDER BY d.date");
$stmt->execute([$user_id, $user_id]);
$daily_data = $stmt->fetchAll();

// Function to generate referral code
function generateReferralCode($length = 8) {
    $characters = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZ';
    $code = '';
    for ($i = 0; $i < $length; $i++) {
        $code .= $characters[rand(0, strlen($characters) - 1)];
    }
    return $code;
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Downline Analytics - My Account</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/3.9.1/chart.min.js"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
    /* For the vertical tree view */
    .tree {
        --spacing: 1.5rem;
        --radius: 10px;
    }

    .tree li {
        display: block;
        position: relative;
        padding-left: calc(2 * var(--spacing) - var(--radius) - 2px);
    }

    .tree ul {
        margin-left: calc(var(--radius) - var(--spacing));
        padding-left: 0;
    }

    .tree ul li {
        border-left: 2px solid #ddd;
    }

    .tree ul li:last-child {
        border-color: transparent;
    }

    .tree ul li::before {
        content: '';
        display: block;
        position: absolute;
        top: calc(var(--spacing) / -2);
        left: -2px;
        width: calc(var(--spacing) + 2px);
        height: calc(var(--spacing) + 1px);
        border: solid #ddd;
        border-width: 0 0 2px 2px;
    }

    .tree summary {
        display: block;
        cursor: pointer;
    }

    .tree summary::marker,
    .tree summary::-webkit-details-marker {
        display: none;
    }

    .tree summary:focus {
        outline: none;
    }

    .tree summary:focus-visible {
        outline: 1px dotted #000;
    }

    .tree li::after,
    .tree summary::before {
        content: '';
        display: block;
        position: absolute;
        top: calc(var(--spacing) / 2 - var(--radius));
        left: calc(var(--spacing) - var(--radius) - 1px);
        width: calc(2 * var(--radius));
        height: calc(2 * var(--radius));
        border-radius: 50%;
        background: white;
        border: 1px solid #ddd;
    }

    .tree summary::before {
        z-index: 1;
        background: #4f46e5;
        border-color: #4338ca;
    }

    /* Tooltip styles */
    .tooltip {
        position: relative;
        display: inline-block;
        cursor: pointer;
    }

    .tooltip .tooltiptext {
        visibility: hidden;
        width: 200px;
        background-color: #2d3748;
        color: #fff;
        text-align: center;
        border-radius: 6px;
        padding: 8px;
        position: absolute;
        z-index: 1;
        bottom: 125%;
        left: 50%;
        margin-left: -100px;
        opacity: 0;
        transition: opacity 0.3s;
    }

    .tooltip .tooltiptext::after {
        content: "";
        position: absolute;
        top: 100%;
        left: 50%;
        margin-left: -5px;
        border-width: 5px;
        border-style: solid;
        border-color: #2d3748 transparent transparent transparent;
    }

    .tooltip:hover .tooltiptext {
        visibility: visible;
        opacity: 1;
    }
    </style>
</head>

<body class="bg-gray-100 text-gray-800">
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
                class="bg-white text-indigo-700 px-4 py-2 rounded hover:bg-gray-100 transition">Logout</a>
        </div>
    </header>

    <div class="flex pt-16">
        <!-- Sidebar -->
        <aside id="sidebar"
            class="bg-indigo-800 text-white w-64 min-h-screen fixed z-10 transition-transform duration-300 ease-in-out md:translate-x-0 transform -translate-x-full">
            <div class="p-4">

                <nav>
                    <ul class="space-y-2">
                        <li>
                            <a href="user_dashboard.php"><i class="fas fa-tachometer-alt mr-2"></i> Dashboard</a>

                        </li>
                        <li>
                            <a href="referral_tree.php" class="bg-indigo-700"><i class="fas fa-sitemap mr-2"></i>
                                Referral Tree</a>

                        </li>
                        <li>
                            <a href="contributions.php"><i class="fas fa-chart-line mr-2"></i> Contributions</a>

                        </li>
                        <!-- <li>
                            <a href="settings.php"><i class="fas fa-cog mr-2"></i> Settings</a>

                        </li>-->
                        <li>
                            <a href="logout.php"><i class="fas fa-sign-out-alt mr-2"></i> Logout</a>

                        </li>

                    </ul>
                </nav>
            </div>
        </aside>

        <!-- Main Content -->
        <main class="flex-1 md:ml-64 p-6">
            <div class="mb-8">
                <h2 class="text-2xl font-bold mb-6">Downline Contribution Analytics</h2>

                <!-- Referral Section -->
                <div class="bg-white p-6 rounded-lg shadow-md mb-6">
                    <h3 class="text-lg font-semibold mb-4">My Referral Link</h3>
                    <div class="flex flex-col md:flex-row items-start md:items-center gap-4">
                        <div class="flex-1">
                            <div class="flex">
                                <input type="text" id="referral-link"
                                    value="<?php echo htmlspecialchars($referral_link); ?>"
                                    class="w-full p-2 border border-gray-300 rounded-l-md bg-gray-50 text-gray-800"
                                    readonly>
                                <button id="copy-link"
                                    class="bg-indigo-600 text-white px-4 py-2 rounded-r-md hover:bg-indigo-700 focus:outline-none">
                                    <i class="fas fa-copy"></i>
                                </button>
                            </div>
                            <p class="text-sm text-gray-500 mt-2">Share this link with friends to earn rewards on their
                                contributions</p>
                        </div>
                        <div class="flex flex-wrap gap-2">
                            <a href="https://wa.me/?text=<?php echo urlencode('Join me on XYZ Platform! Use my referral link: ' . $referral_link); ?>"
                                target="_blank"
                                class="bg-green-500 text-white p-2 rounded-full hover:bg-green-600 transition"
                                title="Share on WhatsApp">
                                <i class="fab fa-whatsapp"></i>
                            </a>
                            <a href="https://www.facebook.com/sharer/sharer.php?u=<?php echo urlencode($referral_link); ?>"
                                target="_blank"
                                class="bg-blue-600 text-white p-2 rounded-full hover:bg-blue-700 transition"
                                title="Share on Facebook">
                                <i class="fab fa-facebook-f"></i>
                            </a>
                            <a href="https://twitter.com/intent/tweet?text=<?php echo urlencode('Join me on XYZ Platform! Use my referral link: ' . $referral_link); ?>"
                                target="_blank"
                                class="bg-blue-400 text-white p-2 rounded-full hover:bg-blue-500 transition"
                                title="Share on Twitter">
                                <i class="fab fa-twitter"></i>
                            </a>
                            <a href="mailto:?subject=Join%20me%20on%20XYZ%20Platform&body=<?php echo urlencode('Hello! Join me on XYZ Platform using my referral link: ' . $referral_link); ?>"
                                class="bg-red-500 text-white p-2 rounded-full hover:bg-red-600 transition"
                                title="Share via Email">
                                <i class="fas fa-envelope"></i>
                            </a>
                            <a href="sms:?body=<?php echo urlencode('Join me on XYZ Platform! Use my referral link: ' . $referral_link); ?>"
                                class="bg-yellow-500 text-white p-2 rounded-full hover:bg-yellow-600 transition"
                                title="Share via SMS">
                                <i class="fas fa-sms"></i>
                            </a>
                        </div>
                    </div>
                    <div class="mt-4">
                        <p class="font-medium">My Referral Code: <span
                                class="bg-gray-100 px-2 py-1 rounded"><?php echo htmlspecialchars($user['referral_code']); ?></span>
                        </p>
                    </div>
                </div>

                <!-- Filter Controls -->
                <div class="bg-white p-4 rounded-lg shadow-md mb-6">
                    <form action="" method="get" class="grid grid-cols-1 md:grid-cols-3 gap-4">
                        <div>
                            <label for="period" class="block text-sm font-medium text-gray-700 mb-1">Time Period</label>
                            <select id="period" name="period"
                                class="w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring focus:ring-indigo-200 focus:ring-opacity-50">
                                <option value="7days" <?php echo $filter_period == '7days' ? 'selected' : ''; ?>>Last 7
                                    Days</option>
                                <option value="30days" <?php echo $filter_period == '30days' ? 'selected' : ''; ?>>Last
                                    30 Days</option>
                                <option value="90days" <?php echo $filter_period == '90days' ? 'selected' : ''; ?>>Last
                                    90 Days</option>
                                <option value="year" <?php echo $filter_period == 'year' ? 'selected' : ''; ?>>Last Year
                                </option>
                                <option value="all" <?php echo $filter_period == 'all' ? 'selected' : ''; ?>>All Time
                                </option>
                            </select>
                        </div>

                        <div>
                            <label for="user_id" class="block text-sm font-medium text-gray-700 mb-1">Referral</label>
                            <select id="user_id" name="user_id"
                                class="w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring focus:ring-indigo-200 focus:ring-opacity-50">
                                <option value="all">All Downline</option>
                                <?php foreach ($downline_users as $downline_user): ?>
                                <?php $level_prefix = str_repeat('— ', $downline_user['level']); ?>
                                <option value="<?php echo $downline_user['id']; ?>"
                                    <?php echo $filter_user == $downline_user['id'] ? 'selected' : ''; ?>>
                                    <?php echo $level_prefix . htmlspecialchars($downline_user['name']); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="flex items-end">
                            <button type="submit"
                                class="bg-indigo-600 text-white px-4 py-2 rounded-md hover:bg-indigo-700 focus:outline-none focus:ring-2 focus:ring-indigo-500">
                                <i class="fas fa-filter mr-2"></i> Apply Filters
                            </button>
                            <a href="downline_contributions.php"
                                class="ml-2 text-gray-600 hover:text-gray-800 px-4 py-2">
                                <i class="fas fa-times mr-1"></i> Clear
                            </a>
                        </div>
                    </form>
                </div>

                <!-- Summary Cards -->
                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6 mb-8">
                    <div class="bg-white p-6 rounded-lg shadow-md">
                        <div class="flex justify-between items-start">
                            <div>
                                <h3 class="text-lg font-semibold text-gray-500">Your Contribution</h3>
                                <p class="text-3xl font-bold">
                                    $<?php echo number_format($personal_contribution ?? 0, 2); ?></p>
                            </div>
                            <div class="bg-indigo-100 rounded-full p-3">
                                <i class="fas fa-user-circle text-indigo-500"></i>
                            </div>
                        </div>
                    </div>

                    <div class="bg-white p-6 rounded-lg shadow-md">
                        <div class="flex justify-between items-start">
                            <div>
                                <h3 class="text-lg font-semibold text-gray-500">Downline Contributions</h3>
                                <p class="text-3xl font-bold">
                                    $<?php echo number_format($summary['total_amount'] ?? 0, 2); ?></p>
                            </div>
                            <div class="bg-blue-100 rounded-full p-3">
                                <i class="fas fa-money-bill-wave text-blue-500"></i>
                            </div>
                        </div>
                        <div class="mt-2 text-sm text-gray-600">
                            <?php echo number_format($summary['total_contributions'] ?? 0); ?> transactions
                        </div>
                    </div>

                    <div class="bg-white p-6 rounded-lg shadow-md bg-gradient-to-r from-indigo-50 to-blue-50">
                        <div class="flex justify-between items-start">
                            <div>
                                <h3 class="text-lg font-semibold text-gray-500">Combined Total</h3>
                                <p class="text-3xl font-bold">$<?php echo number_format($combined_total, 2); ?></p>
                            </div>
                            <div class="bg-purple-100 rounded-full p-3">
                                <i class="fas fa-chart-pie text-purple-500"></i>
                            </div>
                        </div>
                        <div class="mt-2 text-sm text-gray-600">
                            You + your entire downline
                        </div>
                    </div>

                    <div class="bg-white p-6 rounded-lg shadow-md">
                        <div class="flex justify-between items-start">
                            <div>
                                <h3 class="text-lg font-semibold text-gray-500">Contributing Members</h3>
                                <p class="text-3xl font-bold">
                                    <?php echo number_format($summary['unique_contributors'] ?? 0); ?></p>
                            </div>
                            <div class="bg-green-100 rounded-full p-3">
                                <i class="fas fa-users text-green-500"></i>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Charts Section -->
                <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-8">
                    <!-- Contribution Trend Chart -->
                    <div class="bg-white p-4 rounded-lg shadow-md">
                        <h3 class="text-lg font-semibold mb-4">Contribution Trend (Last 30 Days)</h3>
                        <div>
                            <canvas id="contributionTrendChart" height="250"></canvas>
                        </div>
                    </div>

                    <!-- Contribution by Level Chart -->
                    <div class="bg-white p-4 rounded-lg shadow-md">
                        <h3 class="text-lg font-semibold mb-4">Contributions by Referral Level</h3>
                        <div>
                            <canvas id="contributionLevelChart" height="250"></canvas>
                        </div>
                    </div>
                </div>

                <!-- Quick Visualization of the Referral Tree -->
                <div class="bg-white p-6 rounded-lg shadow-md mb-6">
                    <div class="flex justify-between items-center mb-4">
                        <h3 class="text-lg font-semibold">Referral Tree Quick View</h3>
                        <a href="referral_tree.php" class="text-indigo-600 hover:text-indigo-800">View Full Tree <i
                                class="fas fa-arrow-right ml-1"></i></a>
                    </div>

                    <div class="tree overflow-x-auto" id="referral-tree">
                        <ul>
                            <li>
                                <details open>
                                    <summary class="flex items-center pl-8 py-2">
                                        <span class="font-medium ml-2"><?php echo htmlspecialchars($user['name']); ?>
                                            (You)</span>
                                        <span
                                            class="ml-2 bg-indigo-100 text-indigo-800 text-xs px-2 py-1 rounded">$<?php echo number_format($personal_contribution ?? 0, 2); ?></span>
                                    </summary>

                                    <ul id="tree-first-level">
                                        <?php 
                    // Fetch first level direct referrals for quick tree
                    $stmt = $pdo->prepare("
                      SELECT 
                        u.id, 
                        u.name, 
                        (SELECT COUNT(*) FROM users WHERE referred_by = u.id) as has_children,
                        COALESCE((SELECT SUM(amount) FROM contributions WHERE user_id = u.id), 0) as contribution_total
                      FROM users u 
                      WHERE u.referred_by = ? 
                      ORDER BY u.name
                    ");
                    $stmt->execute([$user_id]);
                    $direct_referrals = $stmt->fetchAll();
                    
                    if (empty($direct_referrals)) {
                      echo '<li><div class="py-2 pl-8 text-gray-500">No direct referrals yet</div></li>';
                    } else {
                      foreach ($direct_referrals as $referral):
                    ?>
                                        <li>
                                            <details>
                                                <summary class="flex items-center pl-8 py-2">
                                                    <span
                                                        class="font-medium ml-2"><?php echo htmlspecialchars($referral['name']); ?></span>
                                                    <span
                                                        class="ml-2 bg-blue-100 text-blue-800 text-xs px-2 py-1 rounded">$<?php echo number_format($referral['contribution_total'], 2); ?></span>
                                                    <span
                                                        class="ml-2 bg-blue-100 text-blue-800 text-xs px-2 py-1 rounded">$<?php echo number_format($referral['contribution_total'], 2); ?></span>
                                                    <?php if ($referral['has_children'] > 0): ?>
                                                    <span
                                                        class="ml-2 text-xs text-gray-500">(<?php echo $referral['has_children']; ?>
                                                        referrals)</span>
                                                    <?php endif; ?>
                                                </summary>

                                                <?php if ($referral['has_children'] > 0): ?>
                                                <ul>
                                                    <li class="py-2 pl-8 text-blue-600 hover:underline cursor-pointer"
                                                        onclick="window.location='referral_tree.php?focus=<?php echo $referral['id']; ?>'">
                                                        <i class="fas fa-eye mr-1"></i> View
                                                        <?php echo htmlspecialchars($referral['name']); ?>'s downline
                                                    </li>
                                                </ul>
                                                <?php endif; ?>
                                            </details>
                                        </li>
                                        <?php 
                      endforeach;
                    }
                    ?>
                                    </ul>
                                </details>
                            </li>
                        </ul>
                    </div>
                </div>

                <!-- Top Contributors Section -->
                <div class="bg-white p-6 rounded-lg shadow-md mb-6">
                    <h3 class="text-lg font-semibold mb-4">Top Contributors</h3>

                    <?php if (empty($top_contributors)): ?>
                    <p class="text-gray-500">No contribution data available for your downline.</p>
                    <?php else: ?>
                    <div class="overflow-x-auto">
                        <table class="min-w-full bg-white">
                            <thead class="bg-gray-50">
                                <tr>
                                    <th
                                        class="py-3 px-4 text-left text-sm font-medium text-gray-500 uppercase tracking-wider">
                                        Name</th>
                                    <th
                                        class="py-3 px-4 text-left text-sm font-medium text-gray-500 uppercase tracking-wider">
                                        Total Amount</th>
                                    <th
                                        class="py-3 px-4 text-left text-sm font-medium text-gray-500 uppercase tracking-wider">
                                        Contributions</th>
                                    <th
                                        class="py-3 px-4 text-left text-sm font-medium text-gray-500 uppercase tracking-wider">
                                        Referred By</th>
                                    <th
                                        class="py-3 px-4 text-left text-sm font-medium text-gray-500 uppercase tracking-wider">
                                        Last Contribution</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-200">
                                <?php foreach ($top_contributors as $contributor): ?>
                                <tr class="hover:bg-gray-50">
                                    <td class="py-3 px-4 whitespace-nowrap">
                                        <div class="flex items-center">
                                            <div
                                                class="h-8 w-8 flex-shrink-0 bg-indigo-100 text-indigo-700 rounded-full flex items-center justify-center">
                                                <i class="fas fa-user"></i>
                                            </div>
                                            <div class="ml-3">
                                                <p class="text-gray-900 font-medium">
                                                    <?php echo htmlspecialchars($contributor['name']); ?></p>
                                            </div>
                                        </div>
                                    </td>
                                    <td class="py-3 px-4 whitespace-nowrap">
                                        <div class="text-gray-900">
                                            $<?php echo number_format($contributor['total_amount'], 2); ?></div>
                                    </td>
                                    <td class="py-3 px-4 whitespace-nowrap">
                                        <div class="text-gray-700"><?php echo $contributor['contributions_count']; ?>
                                            contributions</div>
                                    </td>
                                    <td class="py-3 px-4 whitespace-nowrap">
                                        <div class="text-gray-700">
                                            <?php echo htmlspecialchars($contributor['referred_by']); ?></div>
                                    </td>
                                    <td class="py-3 px-4 whitespace-nowrap">
                                        <div class="text-gray-700">
                                            <?php echo date('M j, Y', strtotime($contributor['last_contribution'])); ?>
                                        </div>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php endif; ?>

                    <?php if (count($top_contributors) > 0): ?>
                    <div class="mt-4 text-right">
                        <a href="#all-contributions" class="text-indigo-600 hover:text-indigo-800 font-medium">
                            View All Contributions <i class="fas fa-arrow-down ml-1"></i>
                        </a>
                    </div>
                    <?php endif; ?>
                </div>

                <!-- All Contributions Section -->
                <div id="all-contributions" class="bg-white p-6 rounded-lg shadow-md mb-6">
                    <h3 class="text-lg font-semibold mb-4">Downline Contribution History</h3>

                    <?php if (empty($contributions)): ?>
                    <p class="text-gray-500">No contributions found for the selected filters.</p>
                    <?php else: ?>
                    <div class="overflow-x-auto">
                        <table class="min-w-full bg-white">
                            <thead class="bg-gray-50">
                                <tr>
                                    <th
                                        class="py-3 px-4 text-left text-sm font-medium text-gray-500 uppercase tracking-wider">
                                        Date</th>

                                    <th
                                        class="py-3 px-4 text-left text-sm font-medium text-gray-500 uppercase tracking-wider">
                                        Amount</th>
                                    <th
                                        class="py-3 px-4 text-left text-sm font-medium text-gray-500 uppercase tracking-wider">
                                    </th>

                                    <th
                                        class="py-3 px-4 text-left text-sm font-medium text-gray-500 uppercase tracking-wider">
                                        Referred By</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-200">
                                <?php foreach ($contributions as $contribution): ?>
                                <tr class="hover:bg-gray-50">

                                    <td class="py-3 px-4 whitespace-nowrap">
                                        <div class="flex items-center">
                                            <div class="text-gray-900 font-medium">
                                                <?php echo htmlspecialchars($contribution['user_name']); ?></div>
                                            <?php if ($contribution['their_referrals'] > 0): ?>
                                            <div class="ml-2 tooltip">
                                                <span
                                                    class="bg-blue-100 text-blue-800 text-xs px-2 py-0.5 rounded-full">
                                                    <?php echo $contribution['their_referrals']; ?>
                                                </span>
                                                <span class="tooltiptext">This user has referred
                                                    <?php echo $contribution['their_referrals']; ?> people</span>
                                            </div>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                    <td class="py-3 px-4 whitespace-nowrap">
                                        <div class="text-gray-900 font-medium">
                                            $<?php echo number_format($contribution['amount'], 2); ?></div>
                                    </td>
                                    <td class="py-3 px-4 whitespace-nowrap">
                                        <?php 
                        $type_badge_class = '';
                        switch ($contribution['type']) {
                          case 'donation':
                            $type_badge_class = 'bg-green-100 text-green-800';
                            break;
                          case 'subscription':
                            $type_badge_class = 'bg-blue-100 text-blue-800';
                            break;
                          case 'one-time':
                            $type_badge_class = 'bg-purple-100 text-purple-800';
                            break;
                          default:
                            $type_badge_class = 'bg-gray-100 text-gray-800';
                        }
                        ?>
                                        <span
                                            class="px-2 py-1 inline-flex text-xs leading-5 font-medium rounded-full <?php echo $type_badge_class; ?>">
                                            <?php echo ucfirst($contribution['type']); ?>
                                        </span>
                                    </td>
                                    <td class="py-3 px-4 whitespace-nowrap">
                                        <div class="text-gray-700">
                                            <?php echo htmlspecialchars($contribution['referred_by']); ?></div>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                    <!-- Pagination -->
                    <?php if ($total_pages > 1): ?>
                    <div class="mt-4 flex justify-between items-center">
                        <div class="text-sm text-gray-700">
                            Showing <span class="font-medium"><?php echo $offset + 1; ?></span> to
                            <span class="font-medium"><?php echo min($offset + $per_page, $total_records); ?></span> of
                            <span class="font-medium"><?php echo $total_records; ?></span> contributions
                        </div>

                        <div class="flex space-x-1">
                            <?php 
                  // Pagination URL builder
                  function buildPaginationUrl($page) {
                    $params = $_GET;
                    $params['page'] = $page;
                    return '?' . http_build_query($params);
                  }
                  ?>

                            <?php if ($page > 1): ?>
                            <a href="<?php echo buildPaginationUrl($page - 1); ?>"
                                class="px-3 py-2 border rounded-md text-sm text-gray-700 hover:bg-gray-50">
                                Previous
                            </a>
                            <?php endif; ?>

                            <?php 
                  $range = 2;
                  $show_dots = false;
                  
                  for ($i = 1; $i <= $total_pages; $i++) {
                    if ($i == 1 || $i == $total_pages || ($i >= $page - $range && $i <= $page + $range)) {
                      if ($i == $page) {
                        echo '<span class="px-3 py-2 border rounded-md bg-indigo-50 border-indigo-500 text-indigo-700">' . $i . '</span>';
                      } else {
                        echo '<a href="' . buildPaginationUrl($i) . '" class="px-3 py-2 border rounded-md text-sm text-gray-700 hover:bg-gray-50">' . $i . '</a>';
                      }
                      $show_dots = true;
                    } elseif ($show_dots) {
                      echo '<span class="px-3 py-2 text-gray-500">...</span>';
                      $show_dots = false;
                    }
                  }
                  ?>

                            <?php if ($page < $total_pages): ?>
                            <a href="<?php echo buildPaginationUrl($page + 1); ?>"
                                class="px-3 py-2 border rounded-md text-sm text-gray-700 hover:bg-gray-50">
                                Next
                            </a>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php endif; ?>
                    <?php endif; ?>
                </div>
            </div>
        </main>
    </div>

    <!-- JavaScript for functionality -->
    <script>
    // Toggle sidebar on mobile
    document.getElementById('sidebar-toggle').addEventListener('click', function() {
        const sidebar = document.getElementById('sidebar');
        sidebar.classList.toggle('-translate-x-full');
    });

    // Copy referral link
    document.getElementById('copy-link').addEventListener('click', function() {
        const linkInput = document.getElementById('referral-link');
        linkInput.select();
        linkInput.setSelectionRange(0, 99999); // For mobile devices

        try {
            navigator.clipboard.writeText(linkInput.value);

            // Show copied feedback
            const button = this;
            const originalHTML = button.innerHTML;
            button.innerHTML = '<i class="fas fa-check"></i>';
            button.classList.add('bg-green-600');

            setTimeout(function() {
                button.innerHTML = originalHTML;
                button.classList.remove('bg-green-600');
            }, 2000);
        } catch (err) {
            console.error('Failed to copy: ', err);
            document.execCommand('copy');
        }
    });

    // Chart.js - Contribution Trend Chart
    const createContributionTrendChart = () => {
        const ctx = document.getElementById('contributionTrendChart').getContext('2d');

        // Prepare data
        const dates = <?php echo json_encode(array_column($daily_data, 'contribution_date')); ?>;
        const amounts = <?php echo json_encode(array_column($daily_data, 'daily_total')); ?>;

        // Format dates for display
        const formattedDates = dates.map(date => {
            const d = new Date(date);
            return d.toLocaleDateString('en-US', {
                month: 'short',
                day: 'numeric'
            });
        });

        const chart = new Chart(ctx, {
            type: 'line',
            data: {
                labels: formattedDates,
                datasets: [{
                    label: 'Downline Contributions',
                    data: amounts,
                    backgroundColor: 'rgba(79, 70, 229, 0.1)',
                    borderColor: 'rgba(79, 70, 229, 0.8)',
                    borderWidth: 2,
                    tension: 0.4,
                    fill: true,
                    pointBackgroundColor: 'rgba(79, 70, 229, 1)',
                    pointRadius: 3,
                    pointHoverRadius: 5
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                scales: {
                    y: {
                        beginAtZero: true,
                        ticks: {
                            callback: function(value) {
                                return '$' + value.toLocaleString();
                            }
                        }
                    },
                    x: {
                        grid: {
                            display: false
                        },
                        ticks: {
                            maxRotation: 45,
                            minRotation: 45
                        }
                    }
                },
                plugins: {
                    tooltip: {
                        callbacks: {
                            label: function(context) {
                                return '$' + parseFloat(context.raw).toLocaleString(undefined, {
                                    minimumFractionDigits: 2,
                                    maximumFractionDigits: 2
                                });
                            }
                        }
                    },
                    legend: {
                        display: false
                    }
                }
            }
        });
    };

    // Chart.js - Contribution by Level Chart
    const createContributionLevelChart = () => {
        const ctx = document.getElementById('contributionLevelChart').getContext('2d');

        // Prepare data
        const levels =
            <?php echo json_encode(array_map(function($item) { return 'Level ' . $item['level']; }, $level_data)); ?>;
        const amounts = <?php echo json_encode(array_column($level_data, 'total_amount')); ?>;
        const contributors = <?php echo json_encode(array_column($level_data, 'unique_contributors')); ?>;

        const chart = new Chart(ctx, {
            type: 'bar',
            data: {
                labels: levels,
                datasets: [{
                        label: 'Total Amount',
                        data: amounts,
                        backgroundColor: 'rgba(79, 70, 229, 0.7)',
                        borderColor: 'rgba(79, 70, 229, 1)',
                        borderWidth: 1,
                        yAxisID: 'y'
                    },
                    {
                        label: 'Unique Contributors',
                        data: contributors,
                        type: 'line',
                        backgroundColor: 'rgba(59, 130, 246, 0.7)',
                        borderColor: 'rgba(59, 130, 246, 1)',
                        borderWidth: 2,
                        yAxisID: 'y1',
                        tension: 0.4
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                scales: {
                    y: {
                        beginAtZero: true,
                        position: 'left',
                        title: {
                            display: true,
                            text: 'Amount ($)'
                        },
                        ticks: {
                            callback: function(value) {
                                return '$' + value.toLocaleString();
                            }
                        }
                    },
                    y1: {
                        beginAtZero: true,
                        position: 'right',
                        title: {
                            display: true,
                            text: 'Contributors'
                        },
                        grid: {
                            drawOnChartArea: false
                        }
                    }
                },
                plugins: {
                    tooltip: {
                        callbacks: {
                            label: function(context) {
                                if (context.dataset.label === 'Total Amount') {
                                    return 'Total: $' + parseFloat(context.raw).toLocaleString(
                                        undefined, {
                                            minimumFractionDigits: 2,
                                            maximumFractionDigits: 2
                                        });
                                } else {
                                    return 'Contributors: ' + context.raw;
                                }
                            }
                        }
                    }
                }
            }
        });
    };

    // Initialize charts when the DOM is loaded
    document.addEventListener('DOMContentLoaded', function() {
        createContributionTrendChart();
        createContributionLevelChart();

        // Real-time update functionality - for demonstration purposes
        // In a real app, you would use WebSockets or long-polling
        const simulateRealTimeUpdates = () => {
            // This is a placeholder - in a real app, you would connect to a WebSocket server
            console.log('Real-time update system initialized');

            // Check for updates every 30 seconds
            setInterval(() => {
                fetch('api/check_contribution_updates.php')
                    .then(response => response.json())
                    .then(data => {
                        if (data.hasUpdates) {
                            // Refresh the page or update specific elements
                            window.location.reload();
                        }
                    })
                    .catch(error => console.error('Error checking for updates:', error));
            }, 30000);
        };

        // Uncomment this in production with actual backend implementation
        // simulateRealTimeUpdates();
    });
    </script>
</body>

</html>
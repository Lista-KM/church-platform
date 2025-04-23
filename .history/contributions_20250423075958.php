<?php
// Error reporting for debugging
ini_set('display_errors', 1);
error_reporting(E_ALL);

// Include files with error checking
$include_files = ['includes/auth.php', 'includes/functions.php', 'includes/db.php'];
foreach ($include_files as $file) {
    if (!file_exists($file)) {
        die("Error: Required file '$file' not found.");
    }
    include_once $file;
}

// Redirect if not logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

$user_id = $_SESSION['user_id'];

// Error handling function
function handleDatabaseError($stmt, $error_message) {
    if ($stmt === false) {
        die("Database Error: $error_message - " . $GLOBALS['pdo']->errorInfo()[2]);
    }
}

try {
    // Get user information with error handling
    $stmt = $pdo->prepare("SELECT id, name, email, referral_code FROM users WHERE id = ?");
    handleDatabaseError($stmt, "Failed to prepare user query");
    
    $result = $stmt->execute([$user_id]);
    handleDatabaseError($result, "Failed to execute user query");
    
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$user) {
        die("Error: User not found.");
    }

    // Generate referral code if not exists
    if (empty($user['referral_code'])) {
        // Ensure the function exists
        if (!function_exists('generateReferralCode')) {
            function generateReferralCode($length = 8) {
                $characters = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZ';
                $code = '';
                for ($i = 0; $i < $length; $i++) {
                    $code .= $characters[rand(0, strlen($characters) - 1)];
                }
                return $code;
            }
        }
        
        $referral_code = generateReferralCode();
        $stmt = $pdo->prepare("UPDATE users SET referral_code = ? WHERE id = ?");
        handleDatabaseError($stmt, "Failed to prepare referral code update");
        
        $result = $stmt->execute([$referral_code, $user_id]);
        handleDatabaseError($result, "Failed to update referral code");
        
        $user['referral_code'] = $referral_code;
    }

    $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http";
    $host = isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : 'localhost';
    $referral_link = "$protocol://$host/register.php?ref=" . $user['referral_code'];

    // Simplified downline users query
    $stmt = $pdo->prepare("
        WITH RECURSIVE RefTree AS (
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
        ORDER BY level, name
    ");
    handleDatabaseError($stmt, "Failed to prepare downline users query");
    
    $result = $stmt->execute([$user_id, $user_id]);
    handleDatabaseError($result, "Failed to execute downline users query");
    
    $downline_users = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Get all projects
    $stmt = $pdo->prepare("SELECT id, name FROM projects ORDER BY name");
    handleDatabaseError($stmt, "Failed to prepare projects query");
    
    $result = $stmt->execute();
    handleDatabaseError($result, "Failed to execute projects query");
    
    $projects = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Set up filtering with validation
    $filter_user = isset($_GET['user_id']) ? preg_replace('/[^0-9]/', '', $_GET['user_id']) : 'all';
    $filter_period = isset($_GET['period']) ? preg_replace('/[^a-z0-9]/', '', $_GET['period']) : '30days';
    $filter_type = isset($_GET['type']) ? preg_replace('/[^a-z0-9]/', '', $_GET['type']) : 'all';
    $filter_project = (isset($_GET['project_id']) && is_numeric($_GET['project_id']))
                  ? (int) $_GET['project_id']
                  : 'all';

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

    // Build user filter with proper SQL injection protection
    $user_filter = '';
    if ($filter_user !== 'all') {
        $user_filter = "AND c.user_id = :filter_user";
    }

    // Build project filter with proper SQL injection protection
    $project_filter = '';
    if ($filter_project !== 'all') {
        $project_filter = "AND c.project_id = :filter_project";
    }

    // Get pagination parameters with validation
    $page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
    $per_page = 10;
    $offset = ($page - 1) * $per_page;

    // Get total records for pagination with safer parameter binding
    $count_sql = "
        WITH RECURSIVE RefTree AS (
            SELECT id FROM users WHERE id = :user_id
            UNION ALL
            SELECT u.id FROM users u JOIN RefTree rt ON u.referred_by = rt.id
        )
        SELECT COUNT(*) as total
        FROM contributions c
        JOIN users u ON c.user_id = u.id
        JOIN RefTree rt ON c.user_id = rt.id
        WHERE c.user_id != :user_id $date_filter";
    
    $params = ['user_id' => $user_id];
    
    if ($filter_user !== 'all') {
        $count_sql .= " $user_filter";
        $params['filter_user'] = $filter_user;
    }
    
    if ($filter_project !== 'all') {
        $count_sql .= " $project_filter";
        $params['filter_project'] = $filter_project;
    }
    
    $stmt = $pdo->prepare($count_sql);
    handleDatabaseError($stmt, "Failed to prepare count query");
    
    $result = $stmt->execute($params);
    handleDatabaseError($result, "Failed to execute count query");
    
    $total_records = $stmt->fetchColumn();
    $total_pages = ceil($total_records / $per_page);

    // Get contributions with pagination using parameterized query
    $contributions_sql = "
        WITH RECURSIVE RefTree AS (
            SELECT id, name, referred_by FROM users WHERE id = :user_id
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
            p.name as project_name,
            (SELECT name FROM users WHERE id = u.referred_by) as referred_by,
            (SELECT COUNT(*) FROM users WHERE referred_by = u.id) as their_referrals
        FROM contributions c
        JOIN users u ON c.user_id = u.id
        JOIN projects p ON c.project_id = p.id
        JOIN RefTree rt ON c.user_id = rt.id
        WHERE c.user_id != :user_id $date_filter";
    
    $params = ['user_id' => $user_id];
    
    if ($filter_user !== 'all') {
        $contributions_sql .= " $user_filter";
        $params['filter_user'] = $filter_user;
    }
    
    if ($filter_project !== 'all') {
        $contributions_sql .= " $project_filter";
        $params['filter_project'] = $filter_project;
    }
    
    $contributions_sql .= " ORDER BY c.created_at DESC LIMIT :offset, :per_page";
    
    $stmt = $pdo->prepare($contributions_sql);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->bindValue(':per_page', $per_page, PDO::PARAM_INT);
    
    foreach ($params as $key => $value) {
        if ($key !== 'offset' && $key !== 'per_page') {
            $stmt->bindValue(":$key", $value);
        }
    }
    
    handleDatabaseError($stmt, "Failed to prepare contributions query");
    
    $result = $stmt->execute();
    handleDatabaseError($result, "Failed to execute contributions query");
    
    $contributions = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Get summary statistics with safer parameter binding
    $summary_sql = "
        WITH RECURSIVE RefTree AS (
            SELECT id FROM users WHERE id = :user_id
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
        WHERE c.user_id != :user_id $date_filter";
    
    $params = ['user_id' => $user_id];
    
    if ($filter_user !== 'all') {
        $summary_sql .= " $user_filter";
        $params['filter_user'] = $filter_user;
    }
    
    if ($filter_project !== 'all') {
        $summary_sql .= " $project_filter";
        $params['filter_project'] = $filter_project;
    }
    
    $stmt = $pdo->prepare($summary_sql);
    handleDatabaseError($stmt, "Failed to prepare summary query");
    
    $result = $stmt->execute($params);
    handleDatabaseError($result, "Failed to execute summary query");
    
    $summary = $stmt->fetch(PDO::FETCH_ASSOC);

    // Get user's personal contribution total - properly include project filter
    $personal_sql = "SELECT COALESCE(SUM(amount), 0) as personal_total FROM contributions WHERE user_id = :user_id";
    $personal_params = ['user_id' => $user_id];
    
    if ($filter_project !== 'all') {
        $personal_sql .= " AND project_id = :filter_project";
        $personal_params['filter_project'] = $filter_project;
    }
    
    // Add date filter to personal contributions to be consistent with other queries
    if (!empty($date_filter)) {
        $date_filter_personal = str_replace('c.', '', $date_filter); // Remove the 'c.' table alias
        $personal_sql .= " " . $date_filter_personal;
    }
    
    $stmt = $pdo->prepare($personal_sql);
    handleDatabaseError($stmt, "Failed to prepare personal contribution query");
    
    $result = $stmt->execute($personal_params);
    handleDatabaseError($result, "Failed to execute personal contribution query");
    
    $personal_contribution = $stmt->fetchColumn();

    // Get combined total (personal + downline)
    $combined_total = ($personal_contribution ?? 0) + ($summary['total_amount'] ?? 0);

    // Get contribution by level data with safer parameter binding
    $level_sql = "
        WITH RECURSIVE RefTree AS (
            SELECT id, name, referred_by, 0 as level
            FROM users 
            WHERE id = :user_id
            
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
        WHERE c.user_id != :user_id $date_filter";
    
    $params = ['user_id' => $user_id];
    
    if ($filter_user !== 'all') {
        $level_sql .= " $user_filter";
        $params['filter_user'] = $filter_user;
    }
    
    if ($filter_project !== 'all') {
        $level_sql .= " $project_filter";
        $params['filter_project'] = $filter_project;
    }
    
    $level_sql .= " GROUP BY rt.level ORDER BY rt.level";
    
    $stmt = $pdo->prepare($level_sql);
    handleDatabaseError($stmt, "Failed to prepare level data query");
    
    $result = $stmt->execute($params);
    handleDatabaseError($result, "Failed to execute level data query");
    
    $level_data = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Get top contributors with safer parameter binding
    $top_sql = "
        WITH RECURSIVE RefTree AS (
            SELECT id FROM users WHERE id = :user_id
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
        WHERE c.user_id != :user_id $date_filter";
    
    $params = ['user_id' => $user_id];
    
    if ($filter_project !== 'all') {
        $top_sql .= " $project_filter";
        $params['filter_project'] = $filter_project;
    }
    
    $top_sql .= " GROUP BY u.id ORDER BY total_amount DESC LIMIT 5";
    
    $stmt = $pdo->prepare($top_sql);
    handleDatabaseError($stmt, "Failed to prepare top contributors query");
    
    $result = $stmt->execute($params);
    handleDatabaseError($result, "Failed to execute top contributors query");
    
    $top_contributors = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // FIXED: Get daily contribution data with corrected LEFT JOIN and WHERE clause structure
    $daily_sql = "
    WITH RECURSIVE RefTree AS (
        SELECT id FROM users WHERE id = :user_id
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
    LEFT JOIN (
        SELECT c.* 
        FROM contributions c 
        JOIN RefTree rt ON c.user_id = rt.id 
        WHERE c.user_id != :user_id
        " . ($filter_project !== 'all' ? "AND c.project_id = :filter_project" : "") . "
    ) c ON DATE(c.created_at) = d.date
    GROUP BY d.date 
    ORDER BY d.date";
    
    $params = ['user_id' => $user_id];
    
    if ($filter_project !== 'all') {
        $params['filter_project'] = $filter_project;
    }
    
    $stmt = $pdo->prepare($daily_sql);
    handleDatabaseError($stmt, "Failed to prepare daily data query");
    
    $result = $stmt->execute($params);
    handleDatabaseError($result, "Failed to execute daily data query");
    
    $daily_data = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Get project distribution data with safer parameter binding
    $project_sql = "
        WITH RECURSIVE RefTree AS (
            SELECT id FROM users WHERE id = :user_id
            UNION ALL
            SELECT u.id FROM users u JOIN RefTree rt ON u.referred_by = rt.id
        )
        SELECT 
            p.id,
            p.name as project_name,
            COUNT(c.id) as contributions_count,
            SUM(c.amount) as total_amount
        FROM contributions c
        JOIN projects p ON c.project_id = p.id
        JOIN RefTree rt ON c.user_id = rt.id
        WHERE c.user_id != :user_id $date_filter";
    
    $params = ['user_id' => $user_id];
    
    if ($filter_user !== 'all') {
        $project_sql .= " $user_filter";
        $params['filter_user'] = $filter_user;
    }
    
    if ($filter_project !== 'all') {
        $project_sql .= " $project_filter";
        $params['filter_project'] = $filter_project;
    }
    
    $project_sql .= " GROUP BY p.id ORDER BY total_amount DESC";
    
    $stmt = $pdo->prepare($project_sql);
    handleDatabaseError($stmt, "Failed to prepare project distribution query");
    
    $result = $stmt->execute($params);
    handleDatabaseError($result, "Failed to execute project distribution query");
    
    $project_distribution = $stmt->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    die("Database Error: " . $e->getMessage());
} catch (Exception $e) {
    die("Error: " . $e->getMessage());
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
                            <a href="referral_tree.php"><i class="fas fa-sitemap mr-2"></i>
                                Referral Tree</a>

                        </li>
                        <li>
                            <a href="contributions.php" class="bg-indigo-700"><i class="fas fa-sitemap mr-2"></i>
                                Contributions</a>

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


                <!-- Filter Controls -->
                <div class="bg-white p-4 rounded-lg shadow-md mb-6">
                    <form action="" method="get" class="grid grid-cols-1 md:grid-cols-4 gap-4">

                        <!-- 1) Time Period -->
                        <div>
                            <label for="period" class="block text-sm font-medium text-gray-700 mb-1">Time Period</label>
                            <select id="period" name="period" … <!-- options… -->
                            </select>
                        </div>

                        <!-- 2) Referral (downline user) -->
                        <div>
                            <label for="user_id" class="block text-sm font-medium text-gray-700 mb-1">Referral</label>
                            <select id="user_id" name="user_id" … <!-- options… -->
                            </select>
                        </div>

                        <!-- 3) Project -->
                        <div>
                            <label for="project_id" class="block text-sm font-medium text-gray-700 mb-1">
                                Project
                            </label>
                            <select id="project_id" name="project_id"
                                class="w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring focus:ring-indigo-200 focus:ring-opacity-50">
                                <option value="all">All Projects</option>
                                <?php foreach($projects as $proj): ?>
                                <option value="<?= $proj['id'] ?>"
                                    <?= $filter_project === (int)$proj['id'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($proj['name']) ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <!-- 4) Buttons -->
                        <div class="flex items-end">
                            <button type="submit" …><i class="fas fa-filter mr-2"></i> Apply Filters</button>
                            <?php if($filter_project !== 'all' || $filter_user!=='all' || $filter_period!=='30days'): ?>
                            <a href="contributions.php" class="ml-2 text-gray-600 hover:text-gray-800 px-4 py-2">
                                <i class="fas fa-times mr-1"></i> Clear
                            </a>
                            <?php endif; ?>
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

                <!-- Project Distribution Chart -->
                <div class="bg-white p-6 rounded-lg shadow-md mb-6">
                    <h3 class="text-lg font-semibold mb-4">Project Distribution</h3>
                    <?php if (empty($project_distribution)): ?>
                    <div class="text-center py-6 text-gray-500">
                        <i class="fas fa-chart-pie text-gray-300 text-5xl mb-3"></i>
                        <p>No project contribution data available for the selected filters.</p>
                    </div>
                    <?php else: ?>
                    <div>
                        <canvas id="projectDistributionChart" height="250"></canvas>
                    </div>
                    <div class="mt-4 overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-200">
                            <thead>
                                <tr>
                                    <th
                                        class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                        Project</th>
                                    <th
                                        class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                        Contributions</th>
                                    <th
                                        class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                        Total Amount</th>
                                    <th
                                        class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                        Percentage</th>
                                </tr>
                            </thead>
                            <tbody class="bg-white divide-y divide-gray-200">
                                <?php 
                                    $total_project_amount = array_sum(array_column($project_distribution, 'total_amount'));
                                    foreach ($project_distribution as $project): 
                                        $percentage = $total_project_amount > 0 ? ($project['total_amount'] / $total_project_amount) * 100 : 0;
                                    ?>
                                <tr>
                                    <td class="px-4 py-2 whitespace-nowrap text-sm font-medium text-gray-900">
                                        <?php echo htmlspecialchars($project['project_name']); ?></td>
                                    <td class="px-4 py-2 whitespace-nowrap text-sm text-gray-500">
                                        <?php echo number_format($project['contributions_count']); ?></td>
                                    <td class="px-4 py-2 whitespace-nowrap text-sm text-gray-900">
                                        $<?php echo number_format($project['total_amount'], 2); ?></td>
                                    <td class="px-4 py-2 whitespace-nowrap text-sm text-gray-500">
                                        <?php echo number_format($percentage, 1); ?>%</td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php endif; ?>
                </div>

                <!-- Top Contributors -->
                <div class="bg-white p-6 rounded-lg shadow-md mb-6">
                    <h3 class="text-lg font-semibold mb-4">Top Contributors</h3>
                    <?php if (empty($top_contributors)): ?>
                    <div class="text-center py-6 text-gray-500">
                        <i class="fas fa-medal text-gray-300 text-5xl mb-3"></i>
                        <p>No contribution data available for the selected filters.</p>
                    </div>
                    <?php else: ?>
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-200">
                            <thead>
                                <tr>
                                    <th
                                        class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                        Name</th>
                                    <th
                                        class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                        Referred By</th>
                                    <th
                                        class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                        Contributions</th>
                                    <th
                                        class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                        Total Amount</th>
                                    <th
                                        class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                        Last Contribution</th>
                                </tr>
                            </thead>
                            <tbody class="bg-white divide-y divide-gray-200">
                                <?php foreach ($top_contributors as $contributor): ?>
                                <tr>
                                    <td class="px-4 py-2 whitespace-nowrap text-sm font-medium text-gray-900">
                                        <?php echo htmlspecialchars($contributor['name']); ?></td>
                                    <td class="px-4 py-2 whitespace-nowrap text-sm text-gray-500">
                                        <?php echo htmlspecialchars($contributor['referred_by'] ?? 'Direct'); ?></td>
                                    <td class="px-4 py-2 whitespace-nowrap text-sm text-gray-500">
                                        <?php echo number_format($contributor['contributions_count']); ?></td>
                                    <td class="px-4 py-2 whitespace-nowrap text-sm text-gray-900">
                                        $<?php echo number_format($contributor['total_amount'], 2); ?></td>
                                    <td class="px-4 py-2 whitespace-nowrap text-sm text-gray-500">
                                        <?php echo date('M j, Y', strtotime($contributor['last_contribution'])); ?></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php endif; ?>
                </div>

                <!-- Recent Contributions with Pagination -->
                <div class="bg-white p-6 rounded-lg shadow-md mb-6">
                    <h3 class="text-lg font-semibold mb-4">Recent Contributions</h3>
                    <?php if (empty($contributions)): ?>
                    <div class="text-center py-8 text-gray-500">
                        <i class="fas fa-receipt text-gray-300 text-5xl mb-3"></i>
                        <p>No contribution data available for the selected filters.</p>
                    </div>
                    <?php else: ?>
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-200">
                            <thead>
                                <tr>
                                    <th
                                        class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                        Date</th>
                                    <th
                                        class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                        Name</th>
                                    <th
                                        class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                        Referred By</th>
                                    <th
                                        class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                        Amount</th>
                                    <th
                                        class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                        Project</th>
                                </tr>
                            </thead>
                            <tbody class="bg-white divide-y divide-gray-200">
                                <?php foreach ($contributions as $contribution): ?>
                                <tr>
                                    <td class="px-4 py-2 whitespace-nowrap text-sm text-gray-500">
                                        <?php echo date('M j, Y', strtotime($contribution['created_at'])); ?></td>
                                    <td class="px-4 py-2 whitespace-nowrap text-sm font-medium text-gray-900">
                                        <?php echo htmlspecialchars($contribution['user_name']); ?></td>
                                    <td class="px-4 py-2 whitespace-nowrap text-sm text-gray-500">
                                        <?php echo htmlspecialchars($contribution['referred_by'] ?? 'Direct'); ?></td>
                                    <td class="px-4 py-2 whitespace-nowrap text-sm text-gray-900">
                                        $<?php echo number_format($contribution['amount'], 2); ?></td>
                                    <td class="px-4 py-2 whitespace-nowrap text-sm text-gray-500">
                                        <?php echo htmlspecialchars($contribution['project_name']); ?></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>

                    </div>

                    <!-- Pagination -->
                    <?php if ($total_pages > 1): ?>
                    <div class="mt-6 flex justify-center">
                        <nav class="relative z-0 inline-flex rounded-md shadow-sm -space-x-px" aria-label="Pagination">
                            <?php if ($page > 1): ?>
                            <a href="?page=<?php echo $page - 1; ?>&period=<?php echo $filter_period; ?>&user_id=<?php echo $filter_user; ?>&project_id=<?php echo $filter_project; ?>"
                                class="relative inline-flex items-center px-2 py-2 rounded-l-md border border-gray-300 bg-white text-sm font-medium text-gray-500 hover:bg-gray-50">
                                <span class="sr-only">Previous</span>
                                <i class="fas fa-chevron-left"></i>
                            </a>
                            <?php endif; ?>

                            <?php
                                $start_page = max(1, $page - 2);
                                $end_page = min($total_pages, $page + 2);
                                
                                if ($start_page > 1) {
                                    echo '<span class="relative inline-flex items-center px-4 py-2 border border-gray-300 bg-white text-sm font-medium text-gray-700">...</span>';
                                }
                                
                                for ($i = $start_page; $i <= $end_page; $i++): 
                                ?>
                            <a href="?page=<?php echo $i; ?>&period=<?php echo $filter_period; ?>&user_id=<?php echo $filter_user; ?>&project_id=<?php echo $filter_project; ?>"
                                class="relative inline-flex items-center px-4 py-2 border border-gray-300 bg-white text-sm font-medium <?php echo $i == $page ? 'text-indigo-600 bg-indigo-50' : 'text-gray-700 hover:bg-gray-50'; ?>">
                                <?php echo $i; ?>
                            </a>
                            <?php endfor; ?>

                            <?php
                                if ($end_page < $total_pages) {
                                    echo '<span class="relative inline-flex items-center px-4 py-2 border border-gray-300 bg-white text-sm font-medium text-gray-700">...</span>';
                                }
                                ?>

                            <?php if ($page < $total_pages): ?>
                            <a href="?page=<?php echo $page + 1; ?>&period=<?php echo $filter_period; ?>&user_id=<?php echo $filter_user; ?>&project_id=<?php echo $filter_project; ?>"
                                class="relative inline-flex items-center px-2 py-2 rounded-r-md border border-gray-300 bg-white text-sm font-medium text-gray-500 hover:bg-gray-50">
                                <span class="sr-only">Next</span>
                                <i class="fas fa-chevron-right"></i>
                            </a>
                            <?php endif; ?>
                        </nav>
                    </div>
                    <?php endif; ?>
                    <?php endif; ?>
                </div>
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
                            <p class="text-sm text-gray-500 mt-2">Share this link with friends</p>
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

            </div>
        </main>
    </div>

    <script>
    // Toggle sidebar on mobile
    document.getElementById('sidebar-toggle').addEventListener('click', function() {
        const sidebar = document.getElementById('sidebar');
        sidebar.classList.toggle('-translate-x-full');
    });

    // Copy referral link functionality
    document.getElementById('copy-link').addEventListener('click', function() {
        const referralLink = document.getElementById('referral-link');
        referralLink.select();
        document.execCommand('copy');

        // Show feedback
        this.innerHTML = '<i class="fas fa-check"></i>';
        setTimeout(() => {
            this.innerHTML = '<i class="fas fa-copy"></i>';
        }, 2000);
    });

    // Initialize charts
    document.addEventListener('DOMContentLoaded', function() {
        // Chart color scheme
        const colors = {
            primary: '#4f46e5',
            secondary: '#60a5fa',
            tertiary: '#818cf8',
            quaternary: '#c7d2fe',
            light: '#e5e7eb',
            dark: '#374151'
        };

        // Contribution Trend Chart
        const trendCtx = document.getElementById('contributionTrendChart').getContext('2d');
        const trendChart = new Chart(trendCtx, {
            type: 'line',
            data: {
                labels: [
                    <?php foreach ($daily_data as $data): ?> '<?php echo date('M j', strtotime($data['contribution_date'])); ?>',
                    <?php endforeach; ?>
                ],
                datasets: [{
                    label: 'Daily Contributions',
                    data: [
                        <?php foreach ($daily_data as $data): ?>
                        <?php echo $data['daily_total']; ?>,
                        <?php endforeach; ?>
                    ],
                    backgroundColor: 'rgba(79, 70, 229, 0.2)',
                    borderColor: colors.primary,
                    borderWidth: 2,
                    tension: 0.3,
                    pointBackgroundColor: colors.primary
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
                                return '$' + value;
                            }
                        }
                    }
                },
                plugins: {
                    tooltip: {
                        callbacks: {
                            label: function(context) {
                                return '$' + context.raw.toFixed(2);
                            }
                        }
                    }
                }
            }
        });

        // Contribution by Level Chart
        const levelCtx = document.getElementById('contributionLevelChart').getContext('2d');
        const levelChart = new Chart(levelCtx, {
            type: 'bar',
            data: {
                labels: [
                    <?php foreach ($level_data as $data): ?> 'Level <?php echo $data['level']; ?>',
                    <?php endforeach; ?>
                ],
                datasets: [{
                    label: 'Total Amount',
                    data: [
                        <?php foreach ($level_data as $data): ?>
                        <?php echo $data['total_amount']; ?>,
                        <?php endforeach; ?>
                    ],
                    backgroundColor: [
                        colors.primary,
                        colors.secondary,
                        colors.tertiary,
                        colors.quaternary,
                        colors.dark
                    ]
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
                                return '$' + value;
                            }
                        }
                    }
                },
                plugins: {
                    tooltip: {
                        callbacks: {
                            label: function(context) {
                                return [
                                    'Total: $' + context.raw.toFixed(2),
                                    'Contributors: <?php foreach ($level_data as $index => $data): ?>' +
                                    (context.dataIndex === <?php echo $index; ?> ?
                                        <?php echo $data['unique_contributors']; ?> : '') +
                                    '<?php endforeach; ?>'
                                ];
                            }
                        }
                    }
                }
            }
        });

        // Project Distribution Chart
        <?php if (!empty($project_distribution)): ?>
        const projectCtx = document.getElementById('projectDistributionChart').getContext('2d');
        const projectChart = new Chart(projectCtx, {
            type: 'doughnut',
            data: {
                labels: [
                    <?php foreach ($project_distribution as $project): ?> '<?php echo addslashes($project['project_name']); ?>',
                    <?php endforeach; ?>
                ],
                datasets: [{
                    data: [
                        <?php foreach ($project_distribution as $project): ?>
                        <?php echo $project['total_amount']; ?>,
                        <?php endforeach; ?>
                    ],
                    backgroundColor: [
                        '#4f46e5', '#60a5fa', '#818cf8', '#c7d2fe', '#e0e7ff',
                        '#2563eb', '#3b82f6', '#60a5fa', '#93c5fd', '#bfdbfe',
                        '#7c3aed', '#8b5cf6', '#a78bfa', '#c4b5fd', '#ddd6fe'
                    ],
                    borderWidth: 1
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        position: 'right',
                        labels: {
                            boxWidth: 15
                        }
                    },
                    tooltip: {
                        callbacks: {
                            label: function(context) {
                                const value = context.raw;
                                const total = context.dataset.data.reduce((a, b) => a + b, 0);
                                const percentage = Math.round((value / total) * 100);
                                return `$${value.toFixed(2)} (${percentage}%)`;
                            }
                        }
                    }
                }
            }
        });
        <?php endif; ?>
    });
    </script>
</body>

</html>
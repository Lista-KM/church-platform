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
    $filter_project = isset($_GET['project_id']) ? preg_replace('/[^0-9]/', '', $_GET['project_id']) : 'all';

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

    // Get user's personal contribution total - FIXED: added project filter here
    $personal_sql = "SELECT COALESCE(SUM(amount), 0) as personal_total FROM contributions WHERE user_id = :user_id";
    $personal_params = ['user_id' => $user_id];
    
    if ($filter_project !== 'all') {
        $personal_sql .= " AND project_id = :filter_project";
        $personal_params['filter_project'] = $filter_project;
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

                        <!-- New Project Filter Dropdown -->
                        <div>
                            <label for="project_id" class="block text-sm font-medium text-gray-700 mb-1">Project</label>
                            <select id="project_id" name="project_id"
                                class="w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring focus:ring-indigo-200 focus:ring-opacity-50">
                                <option value="all">All Projects</option>
                                <?php foreach ($projects as $project): ?>
                                <option value="<?php echo $project['id']; ?>"
                                    <?php echo $filter_project == $project['id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($project['name']); ?>
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
                            <div class="bg-green-100 rounded-full p-3">
                                <i class="fas fa-users text-green-500"></i>
                            </div>
                        </div>
                    </div>

                    <div class="bg-white p-6 rounded-lg shadow-md">
                        <div class="flex justify-between items-start">
                            <div>
                                <h3 class="text-lg font-semibold text-gray-500">Combined Total</h3>
                                <p class="text-3xl font-bold">$<?php echo number_format($combined_total ?? 0, 2); ?></p>
                            </div>
                            <div class="bg-purple-100 rounded-full p-3">
                                <i class="fas fa-chart-pie text-purple-500"></i>
                            </div>
                        </div>
                    </div>

                    <div class="bg-white p-6 rounded-lg shadow-md">
                        <div class="flex justify-between items-start">
                            <div>
                                <h3 class="text-lg font-semibold text-gray-500">Unique Contributors</h3>
                                <p class="text-3xl font-bold">
                                    <?php echo number_format($summary['unique_contributors'] ?? 0); ?></p>
                            </div>
                            <div class="bg-blue-100 rounded-full p-3">
                                <i class="fas fa-user-friends text-blue-500"></i>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Charts Section -->
                <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-8">
                    <!-- Daily Contribution Chart -->
                    <div class="bg-white p-6 rounded-lg shadow-md">
                        <h3 class="text-lg font-semibold mb-4">Daily Contribution Trend</h3>
                        <canvas id="dailyContributionChart" width="400" height="300"></canvas>
                    </div>

                    <!-- Project Distribution Chart -->
                    <div class="bg-white p-6 rounded-lg shadow-md">
                        <h3 class="text-lg font-semibold mb-4">Project Distribution</h3>
                        <canvas id="projectDistributionChart" width="400" height="300"></canvas>
                    </div>
                </div>

                <!-- Level Breakdown -->
                <div class="bg-white p-6 rounded-lg shadow-md mb-8">
                    <h3 class="text-lg font-semibold mb-4">Contribution by Referral Level</h3>
                    <div class="overflow-x-auto">
                        <table class="min-w-full bg-white">
                            <thead class="bg-gray-100">
                                <tr>
                                    <th class="py-3 px-4 text-left">Level</th>
                                    <th class="py-3 px-4 text-left">Contributors</th>
                                    <th class="py-3 px-4 text-left">Contributions</th>
                                    <th class="py-3 px-4 text-left">Total Amount</th>
                                    <th class="py-3 px-4 text-left">Average</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($level_data as $level): ?>
                                <tr class="border-b hover:bg-gray-50">
                                    <td class="py-3 px-4"><?php echo $level['level']; ?></td>
                                    <td class="py-3 px-4"><?php echo $level['unique_contributors']; ?></td>
                                    <td class="py-3 px-4"><?php echo $level['contributions_count']; ?></td>
                                    <td class="py-3 px-4">$<?php echo number_format($level['total_amount'], 2); ?></td>
                                    <td class="py-3 px-4">
                                        $<?php echo $level['contributions_count'] > 0 ? number_format($level['total_amount'] / $level['contributions_count'], 2) : '0.00'; ?>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- Top Contributors -->
                <div class="bg-white p-6 rounded-lg shadow-md mb-8">
                    <h3 class="text-lg font-semibold mb-4">Top Contributors</h3>
                    <div class="overflow-x-auto">
                        <table class="min-w-full bg-white">
                            <thead class="bg-gray-100">
                                <tr>
                                    <th class="py-3 px-4 text-left">Name</th>
                                    <th class="py-3 px-4 text-left">Contributions</th>
                                    <th class="py-3 px-4 text-left">Total Amount</th>
                                    <th class="py-3 px-4 text-left">Last Contribution</th>
                                    <th class="py-3 px-4 text-left">Referred By</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($top_contributors as $contributor): ?>
                                <tr class="border-b hover:bg-gray-50">
                                    <td class="py-3 px-4"><?php echo htmlspecialchars($contributor['name']); ?></td>
                                    <td class="py-3 px-4"><?php echo $contributor['contributions_count']; ?></td>
                                    <td class="py-3 px-4">$<?php echo number_format($contributor['total_amount'], 2); ?>
                                    </td>
                                    <td class="py-3 px-4">
                                        <?php echo date('M d, Y', strtotime($contributor['last_contribution'])); ?></td>
                                    <td class="py-3 px-4">
                                        <?php echo htmlspecialchars($contributor['referred_by'] ?? 'Direct'); ?></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- Contribution History -->
                <div class="bg-white p-6 rounded-lg shadow-md mb-8">
                    <h3 class="text-lg font-semibold mb-4">Contribution History</h3>
                    <div class="overflow-x-auto">
                        <table class="min-w-full bg-white">
                            <thead class="bg-gray-100">
                                <tr>
                                    <th class="py-3 px-4 text-left">Date</th>
                                    <th class="py-3 px-4 text-left">Contributor</th>
                                    <th class="py-3 px-4 text-left">Project</th>
                                    <th class="py-3 px-4 text-left">Amount</th>
                                    <th class="py-3 px-4 text-left">Referred By</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($contributions as $contribution): ?>
                                <tr class="border-b hover:bg-gray-50">
                                    <td class="py-3 px-4">
                                        <?php echo date('M d, Y', strtotime($contribution['created_at'])); ?></td>
                                    <td class="py-3 px-4"><?php echo htmlspecialchars($contribution['user_name']); ?>
                                    </td>
                                    <td class="py-3 px-4"><?php echo htmlspecialchars($contribution['project_name']); ?>
                                    </td>
                                    <td class="py-3 px-4">$<?php echo number_format($contribution['amount'], 2); ?></td>
                                    <td class="py-3 px-4">
                                        <?php echo htmlspecialchars($contribution['referred_by'] ?? 'Direct'); ?></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                    <!-- Pagination Controls -->
                    <?php if ($total_pages > 1): ?>
                    <div class="flex justify-center items-center mt-6">
                        <nav class="relative z-0 inline-flex rounded-md shadow-sm -space-x-px" aria-label="Pagination">
                            <?php if ($page > 1): ?>
                            <a href="?page=<?php echo $page - 1; ?>&period=<?php echo $filter_period; ?>&user_id=<?php echo $filter_user; ?>&project_id=<?php echo $filter_project; ?>"
                                class="relative inline-flex items-center px-2 py-2 rounded-l-md border border-gray-300 bg-white text-sm font-medium text-gray-500 hover:bg-gray-50">
                                <span class="sr-only">Previous</span>
                                <i class="fas fa-chevron-left"></i>
                            </a>
                            <?php endif; ?>

                            <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                            <a href="?page=<?php echo $i; ?>&period=<?php echo $filter_period; ?>&user_id=<?php echo $filter_user; ?>&project_id=<?php echo $filter_project; ?>"
                                class="relative inline-flex items-center px-4 py-2 border border-gray-300 bg-white text-sm font-medium <?php echo $i == $page ? 'text-indigo-600 bg-indigo-50' : 'text-gray-700 hover:bg-gray-50'; ?>">
                                <?php echo $i; ?>
                            </a>
                            <?php endfor; ?>

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
                </div>
        </main>
    </div>

    <script>
    // Toggle sidebar on mobile
    document.getElementById('sidebar-toggle').addEventListener('click', function() {
        const sidebar = document.getElementById('sidebar');
        sidebar.classList.toggle('translate-x-0');
        sidebar.classList.toggle('-translate-x-full');
    });

    // Initialize charts with project filter data
    document.addEventListener('DOMContentLoaded', function() {
        // Daily Contribution Chart
        const dailyCtx = document.getElementById('dailyContributionChart').getContext('2d');
        const dailyChart = new Chart(dailyCtx, {
            type: 'line',
            data: {
                labels: [
                    <?php foreach ($daily_data as $data): ?> '<?php echo date("M d", strtotime($data["contribution_date"])); ?>',
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
                    borderColor: 'rgba(79, 70, 229, 1)',
                    borderWidth: 2,
                    tension: 0.1
                }]
            },
            options: {
                responsive: true,
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
                                return '$' + context.parsed.y.toFixed(2);
                            }
                        }
                    }
                }
            }
        });

        // Project Distribution Chart
        const projectCtx = document.getElementById('projectDistributionChart').getContext('2d');
        const projectChart = new Chart(projectCtx, {
            type: 'doughnut',
            data: {
                labels: [
                    <?php foreach ($project_distribution as $data): ?> '<?php echo htmlspecialchars($data["project_name"]); ?>',
                    <?php endforeach; ?>
                ],
                datasets: [{
                    data: [
                        <?php foreach ($project_distribution as $data): ?>
                        <?php echo $data['total_amount']; ?>,
                        <?php endforeach; ?>
                    ],
                    backgroundColor: [
                        'rgba(79, 70, 229, 0.7)',
                        'rgba(59, 130, 246, 0.7)',
                        'rgba(16, 185, 129, 0.7)',
                        'rgba(245, 158, 11, 0.7)',
                        'rgba(239, 68, 68, 0.7)',
                        'rgba(139, 92, 246, 0.7)',
                        'rgba(236, 72, 153, 0.7)',
                    ],
                    borderWidth: 1
                }]
            },
            options: {
                responsive: true,
                plugins: {
                    legend: {
                        position: 'bottom',
                    },
                    tooltip: {
                        callbacks: {
                            label: function(context) {
                                const total = context.dataset.data.reduce((a, b) => a + b, 0);
                                const value = context.parsed;
                                const percentage = ((value / total) * 100).toFixed(1);
                                return `$${value.toFixed(2)} (${percentage}%)`;
                            }
                        }
                    }
                }
            }
        });
    });
    </script>

</body>

</html>
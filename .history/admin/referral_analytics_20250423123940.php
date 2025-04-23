<?php
include '../includes/auth.php';
include '../includes/functions.php';
include '../includes/db.php';

if (!($_SESSION['is_admin'] ?? false)) {
    header("Location: dashboard.php");
    exit();
}

$stmt = $pdo->query("SELECT 
                    COUNT(*) as total_users,
                    SUM(CASE WHEN referred_by IS NOT NULL THEN 1 ELSE 0 END) as referred_users,
                    COUNT(DISTINCT referred_by) as referrers
                    FROM users");
$referral_stats = $stmt->fetch();

$referral_percentage = 0;
if ($referral_stats['total_users'] > 0) {
    $referral_percentage = ($referral_stats['referred_users'] / $referral_stats['total_users']) * 100;
}

$stmt = $pdo->query("SELECT 
                    r.id,
                    r.name,
                    COUNT(u.id) as referral_count,
                    (SELECT SUM(c.amount) 
                     FROM contributions c 
                     JOIN users ru ON c.user_id = ru.id 
                     WHERE ru.referred_by = r.id) as indirect_contributions
                    FROM users r
                    JOIN users u ON u.referred_by = r.id
                    GROUP BY r.id
                    ORDER BY referral_count DESC, indirect_contributions DESC
                    LIMIT 10");
$top_referrers = $stmt->fetchAll();

$stmt = $pdo->query("WITH RECURSIVE ReferralChain AS (
                        SELECT 
                            id, 
                            name, 
                            referred_by, 
                            0 as depth, 
                            id as root_id,
                            CAST(id AS CHAR(50)) as path
                        FROM users 
                        WHERE referred_by IS NULL
                        
                        UNION ALL
                        
                        SELECT 
                            u.id, 
                            u.name, 
                            u.referred_by, 
                            rc.depth + 1, 
                            rc.root_id,
                            CONCAT(rc.path, ',', u.id)
                        FROM users u
                        JOIN ReferralChain rc ON u.referred_by = rc.id
                    )
                    SELECT 
                        root_id,
                        MAX(depth) as max_depth,
                        COUNT(*) - 1 as chain_size
                    FROM ReferralChain
                    GROUP BY root_id
                    ORDER BY chain_size DESC, max_depth DESC
                    LIMIT 10");
$referral_chains = $stmt->fetchAll();

$stmt = $pdo->query("WITH RECURSIVE ReferralChain AS (
                        SELECT 
                            id, 
                            name, 
                            referred_by, 
                            0 as depth
                        FROM users 
                        WHERE referred_by IS NULL
                        
                        UNION ALL
                        
                        SELECT 
                            u.id, 
                            u.name, 
                            u.referred_by, 
                            rc.depth + 1
                        FROM users u
                        JOIN ReferralChain rc ON u.referred_by = rc.id
                    )
                    SELECT 
                        depth,
                        COUNT(*) as users_count
                    FROM ReferralChain
                    GROUP BY depth
                    ORDER BY depth");
$depth_distribution = $stmt->fetchAll();

$stmt = $pdo->query("SELECT 
                    'Referred' as type,
                    COUNT(DISTINCT c.user_id) as contributors,
                    SUM(c.amount) as total,
                    AVG(c.amount) as average
                    FROM contributions c
                    JOIN users u ON c.user_id = u.id
                    WHERE u.referred_by IS NOT NULL
                    
                    UNION ALL
                    
                    SELECT 
                    'Non-Referred' as type,
                    COUNT(DISTINCT c.user_id) as contributors,
                    SUM(c.amount) as total,
                    AVG(c.amount) as average
                    FROM contributions c
                    JOIN users u ON c.user_id = u.id
                    WHERE u.referred_by IS NULL");
$contribution_comparison = $stmt->fetchAll();

$stmt = $pdo->query("SELECT 
                    u.name as user_name,
                    u.email,
                    r.name as referrer_name,
                    (SELECT SUM(amount) FROM contributions WHERE user_id = u.id) as contributions
                    FROM users u
                    JOIN users r ON u.referred_by = r.id
                    LIMIT 10");

$recent_referrals = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Referral Analytics - Admin Dashboard</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/3.9.1/chart.min.js"></script>
    <script src="https://d3js.org/d3.v7.min.js"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>

<body class="bg-gray-100 text-gray-800">
    <!-- Header -->
    <header class="bg-indigo-700 text-white p-4 flex justify-between items-center shadow fixed w-full z-10">
        <div class="flex items-center">
            <button id="sidebar-toggle" class="mr-3 text-white md:hidden">
                <i class="fas fa-bars text-xl"></i>
            </button>
            <h1 class="text-xl font-semibold">Admin Dashboard</h1>
        </div>
        <div class="flex items-center space-x-4">
            <span class="hidden md:inline">Welcome, <?php echo htmlspecialchars($_SESSION['name'] ?? 'Admin'); ?></span>
            <a href="../logout.php"
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
                            <a href="dashboard.php" class="block py-2 px-4 hover:bg-indigo-700 rounded transition">
                                <i class="fas fa-tachometer-alt mr-2"></i> Dashboard
                            </a>
                        </li>
                        <li>
                            <a href="manage_users.php" class="block py-2 px-4 hover:bg-indigo-700 rounded transition">
                                <i class="fas fa-users mr-2"></i> Users
                            </a>
                        </li>
                        <li>
                            <a href="contribution_reports.php"
                                class="block py-2 px-4 hover:bg-indigo-700 rounded transition">
                                <i class="fas fa-hand-holding-usd mr-2"></i> Contributions
                            </a>
                        </li>
                        <li>
                            <a href="referral_analytics.php" class="bg-indigo-700 block py-2 px-4 rounded transition">
                                <i class="fas fa-chart-line mr-2"></i> Referral Analytics
                            </a>
                        </li>
                        <!-- <li>
                            <a href="settings.php" class="block py-2 px-4 hover:bg-indigo-700 rounded transition">
                                <i class="fas fa-cog mr-2"></i> Settings
                            </a>
                        </li> -->
                        <li class="pt-6 border-t border-indigo-700 mt-6">
                            <a href="../logout.php" class="flex items-center p-2 rounded hover:bg-indigo-900">
                                <i class="fas fa-sign-out-alt mr-3"></i>
                                <span>Logout</span>
                            </a>
                        </li>
                    </ul>
                </nav>
            </div>
        </aside>

        <main class="flex-1 md:ml-64 p-6">
            <div class="mb-8">
                <h2 class="text-2xl font-bold mb-4">Referral Analytics</h2>

                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6 mb-8">
                    <div class="bg-white p-6 rounded-lg shadow-md">
                        <div class="flex justify-between items-start">
                            <div>
                                <h3 class="text-lg font-semibold text-gray-500">Total Users</h3>
                                <p class="text-3xl font-bold">
                                    <?php echo number_format($referral_stats['total_users']); ?></p>
                            </div>
                            <div class="bg-blue-100 rounded-full p-3">
                                <i class="fas fa-users text-blue-500"></i>
                            </div>
                        </div>
                    </div>

                    <div class="bg-white p-6 rounded-lg shadow-md">
                        <div class="flex justify-between items-start">
                            <div>
                                <h3 class="text-lg font-semibold text-gray-500">Referred Users</h3>
                                <p class="text-3xl font-bold">
                                    <?php echo number_format($referral_stats['referred_users']); ?></p>
                            </div>
                            <div class="bg-green-100 rounded-full p-3">
                                <i class="fas fa-user-plus text-green-500"></i>
                            </div>
                        </div>
                        <div class="mt-2 text-sm">
                            <span
                                class="text-green-600 font-medium"><?php echo number_format($referral_percentage, 1); ?>%</span>
                            of all users
                        </div>
                    </div>

                    <div class="bg-white p-6 rounded-lg shadow-md">
                        <div class="flex justify-between items-start">
                            <div>
                                <h3 class="text-lg font-semibold text-gray-500">Active Referrers</h3>
                                <p class="text-3xl font-bold"><?php echo number_format($referral_stats['referrers']); ?>
                                </p>
                            </div>
                            <div class="bg-purple-100 rounded-full p-3">
                                <i class="fas fa-user-friends text-purple-500"></i>
                            </div>
                        </div>
                    </div>

                    <div class="bg-white p-6 rounded-lg shadow-md">
                        <div class="flex justify-between items-start">
                            <div>
                                <h3 class="text-lg font-semibold text-gray-500">Max Chain Depth</h3>
                                <p class="text-3xl font-bold">
                                    <?php 
                    $max_chain_depth = 0;
                    foreach ($referral_chains as $chain) {
                      if ($chain['max_depth'] > $max_chain_depth) {
                        $max_chain_depth = $chain['max_depth'];
                      }
                    }
                    echo $max_chain_depth;
                  ?>
                                </p>
                            </div>
                            <div class="bg-yellow-100 rounded-full p-3">
                                <i class="fas fa-sitemap text-yellow-500"></i>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-8">
                    <div class="bg-white p-6 rounded-lg shadow-md">
                        <h3 class="text-lg font-semibold mb-4">Referral Depth Distribution</h3>
                        <div>
                            <canvas id="depthChart" width="400" height="300"></canvas>
                        </div>
                    </div>

                    <!-- Contribution Comparison Chart -->
                    <div class="bg-white p-6 rounded-lg shadow-md">
                        <h3 class="text-lg font-semibold mb-4">Referred vs Non-Referred Contributions</h3>
                        <div>
                            <canvas id="contributionChart" width="400" height="300"></canvas>
                        </div>
                    </div>
                </div>

                <!-- Top Referrers Table -->
                <div class="bg-white p-6 rounded-lg shadow-md mb-8">
                    <h3 class="text-lg font-semibold mb-4">Top Referrers</h3>
                    <div class="overflow-x-auto">
                        <table class="min-w-full bg-white">
                            <thead class="bg-gray-100">
                                <tr>
                                    <th class="py-3 px-4 text-left">Rank</th>
                                    <th class="py-3 px-4 text-left">Name</th>
                                    <th class="py-3 px-4 text-right">Referrals</th>
                                    <th class="py-3 px-4 text-right">Indirect Contributions</th>
                                    <th class="py-3 px-4 text-center">Actions</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-200">
                                <?php $rank = 1; foreach ($top_referrers as $referrer): ?>
                                <tr class="hover:bg-gray-50">
                                    <td class="py-3 px-4"><?php echo $rank++; ?></td>
                                    <td class="py-3 px-4"><?php echo htmlspecialchars($referrer['name']); ?></td>
                                    <td class="py-3 px-4 text-right">
                                        <?php echo number_format($referrer['referral_count']); ?></td>
                                    <td class="py-3 px-4 text-right">
                                        $<?php echo number_format($referrer['indirect_contributions'] ?? 0, 2); ?></td>
                                    <td class="py-3 px-4 text-center">
                                        <a href="user_detail.php?id=<?php echo $referrer['id']; ?>"
                                            class="text-blue-600 hover:text-blue-800 mr-3">
                                            <i class="fas fa-eye"></i>
                                        </a>
                                        <a href="referral_tree.php?id=<?php echo $referrer['id']; ?>"
                                            class="text-green-600 hover:text-green-800">
                                            <i class="fas fa-sitemap"></i>
                                        </a>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- Recent Referrals Table -->
                <div class="bg-white p-6 rounded-lg shadow-md">
                    <h3 class="text-lg font-semibold mb-4">Recent Referrals</h3>
                    <div class="overflow-x-auto">
                        <table class="min-w-full bg-white">
                            <thead class="bg-gray-100">
                                <tr>
                                    <th class="py-3 px-4 text-left">User</th>
                                    <th class="py-3 px-4 text-left">Email</th>
                                    <th class="py-3 px-4 text-left">Referred By</th>
                                    <th class="py-3 px-4 text-left">Date Joined</th>
                                    <th class="py-3 px-4 text-right">Contributions</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-200">
                                <?php foreach ($recent_referrals as $referral): ?>
                                <tr class="hover:bg-gray-50">
                                    <td class="py-3 px-4"><?php echo htmlspecialchars($referral['user_name']); ?></td>
                                    <td class="py-3 px-4"><?php echo htmlspecialchars($referral['email']); ?></td>
                                    <td class="py-3 px-4"><?php echo htmlspecialchars($referral['referrer_name']); ?>
                                    </td>
                                    <td class="py-3 px-4">
                                        <?php echo date('M j, Y', strtotime($referral['created_at'])); ?></td>
                                    <td class="py-3 px-4 text-right">
                                        $<?php echo number_format($referral['contributions'] ?? 0, 2); ?></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </main>
    </div>

    <script>
    document.getElementById('sidebar-toggle').addEventListener('click', function() {
        const sidebar = document.getElementById('sidebar');
        sidebar.classList.toggle('-translate-x-full');
    });

    const depthCtx = document.getElementById('depthChart').getContext('2d');
    const depthChart = new Chart(depthCtx, {
        type: 'bar',
        data: {
            labels: [
                <?php 
            foreach ($depth_distribution as $row) {
              echo $row['depth'] == 0 ? '"Direct",' : '"Level ' . $row['depth'] . '",';
            }
          ?>
            ],
            datasets: [{
                label: 'Number of Users',
                data: [
                    <?php 
              foreach ($depth_distribution as $row) {
                echo $row['users_count'] . ',';
              }
            ?>
                ],
                backgroundColor: 'rgba(99, 102, 241, 0.6)',
                borderColor: 'rgba(99, 102, 241, 1)',
                borderWidth: 1
            }]
        },
        options: {
            responsive: true,
            plugins: {
                legend: {
                    position: 'top',
                },
                title: {
                    display: true,
                    text: 'User Distribution by Referral Depth'
                }
            },
            scales: {
                y: {
                    beginAtZero: true
                }
            }
        }
    });

    const contribCtx = document.getElementById('contributionChart').getContext('2d');
    const contributionChart = new Chart(contribCtx, {
        type: 'bar',
        data: {
            labels: ['Total Contributions', 'Average Contribution'],
            datasets: [
                <?php foreach ($contribution_comparison as $row): ?> {
                    label: '<?php echo $row['type']; ?>',
                    data: [
                        <?php echo $row['total']; ?>,
                        <?php echo $row['average']; ?>
                    ],
                    backgroundColor: '<?php echo $row['type'] == 'Referred' ? 'rgba(16, 185, 129, 0.6)' : 'rgba(239, 68, 68, 0.6)'; ?>',
                    borderColor: '<?php echo $row['type'] == 'Referred' ? 'rgba(16, 185, 129, 1)' : 'rgba(239, 68, 68, 1)'; ?>',
                    borderWidth: 1
                },
                <?php endforeach; ?>
            ]
        },
        options: {
            responsive: true,
            plugins: {
                legend: {
                    position: 'top',
                },
                title: {
                    display: true,
                    text: 'Referred vs Non-Referred User Contributions'
                }
            },
            scales: {
                y: {
                    beginAtZero: true,
                    ticks: {
                        callback: function(value) {
                            return '$' + value.toLocaleString();
                        }
                    }
                }
            }
        }
    });
    </script>
</body>

</html>
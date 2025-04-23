<?php
include '../includes/auth.php';
include '../includes/functions.php';
include '../includes/db.php';

if (!($_SESSION['is_admin'] ?? false)) {
    header("Location: dashboard.php");
    exit();
}

$start_date = $_GET['start_date'] ?? date('Y-m-d', strtotime('-6 months'));
$end_date = $_GET['end_date'] ?? date('Y-m-d');

$project_id = $_GET['project_id'] ?? 'all';

$projects_stmt = $pdo->query("SELECT id, name FROM projects ORDER BY name");
$projects = $projects_stmt->fetchAll();

$base_where = "contributed_at BETWEEN ? AND ?";
$params = [$start_date, $end_date];

if ($project_id !== 'all') {
    $base_where .= " AND project_id = ?";
    $params[] = $project_id;
}

$stmt = $pdo->prepare("SELECT 
                      SUM(amount) as total,
                      AVG(amount) as average,
                      COUNT(*) as count,
                      MAX(amount) as highest,
                      MIN(amount) as lowest
                    FROM contributions
                    WHERE $base_where");
$stmt->execute($params);
$summary = $stmt->fetch();

$stmt_monthly = $pdo->prepare("SELECT 
                            DATE_FORMAT(contributed_at, '%Y-%m') as month,
                            SUM(amount) as total,
                            COUNT(*) as count,
                            AVG(amount) as average
                          FROM contributions 
                          WHERE $base_where
                          GROUP BY DATE_FORMAT(contributed_at, '%Y-%m')
                          ORDER BY month ASC");
$stmt_monthly->execute($params);
$monthly_data = $stmt_monthly->fetchAll();

$stmt_dow = $pdo->prepare("SELECT 
                          DAYNAME(contributed_at) as day_name,
                          SUM(amount) as total,
                          COUNT(*) as count,
                          AVG(amount) as average
                        FROM contributions
                        WHERE $base_where
                        GROUP BY DAYNAME(contributed_at)
                        ORDER BY FIELD(DAYNAME(contributed_at), 'Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday')");
$stmt_dow->execute($params);
$day_of_week_data = $stmt_dow->fetchAll();

$stmt_top = $pdo->prepare("SELECT 
                        users.name, 
                        SUM(contributions.amount) as total,
                        COUNT(*) as frequency,
                        MAX(contributions.contributed_at) as last_contribution
                      FROM contributions
                      JOIN users ON users.id = contributions.user_id
                      WHERE $base_where
                      GROUP BY contributions.user_id
                      ORDER BY total DESC
                      LIMIT 10");
$stmt_top->execute($params);
$top_contributors = $stmt_top->fetchAll();

$stmt_size = $pdo->prepare("SELECT 
                          CASE 
                            WHEN amount < 50 THEN 'Under $50'
                            WHEN amount >= 50 AND amount < 100 THEN '$50-$99'
                            WHEN amount >= 100 AND amount < 250 THEN '$100-$249'
                            WHEN amount >= 250 AND amount < 500 THEN '$250-$499'
                            WHEN amount >= 500 AND amount < 1000 THEN '$500-$999'
                            ELSE '$1000+'
                          END as range_label,
                          COUNT(*) as count,
                          SUM(amount) as total
                        FROM contributions
                        WHERE $base_where
                        GROUP BY range_label
                        ORDER BY MIN(amount)");
$stmt_size->execute($params);
$size_distribution = $stmt_size->fetchAll();

$recent_params = $params;
$recent_where = $base_where;

$stmt_recent = $pdo->prepare("SELECT 
                           users.name,
                           contributions.amount,
                           contributions.contributed_at,
                           projects.name as project_name
                         FROM contributions
                         JOIN users ON users.id = contributions.user_id
                         JOIN projects ON projects.id = contributions.project_id
                         WHERE $recent_where
                         ORDER BY contributions.contributed_at DESC
                         LIMIT 20");
$stmt_recent->execute($recent_params);
$recent_contributions = $stmt_recent->fetchAll();
?>


<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Contribution Reports - Admin Dashboard</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/3.9.1/chart.min.js"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
    <script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
</head>

<body class="bg-gray-100 text-gray-800">
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
        <aside id="sidebar"
            class="bg-indigo-800 text-white w-64 min-h-screen fixed z-10 transition-transform duration-300 ease-in-out md:translate-x-0 transform -translate-x-full">
            <div class="p-4">

                <nav>
                    <ul class="space-y-2">
                        <li>
                            <a href="dashboard.php" class="flex items-center p-2 rounded hover:bg-indigo-900">
                                <i class="fas fa-tachometer-alt mr-3"></i>
                                <span>Dashboard</span>
                            </a>
                        </li>
                        <li>
                            <a href="manage_users.php" class="flex items-center p-2 rounded hover:bg-indigo-900">
                                <i class="fas fa-users mr-3"></i>
                                <span>Manage Users</span>
                            </a>
                        </li>
                        <li>
                            <a href="contribution_reports.php" class="flex items-center p-2 rounded bg-indigo-900">
                                <i class="fas fa-chart-line mr-3"></i>
                                <span>Contribution Reports</span>
                            </a>
                        </li>
                        <li>
                            <a href="referral_analytics.php" class="flex items-center p-2 rounded hover:bg-indigo-900">
                                <i class="fas fa-sitemap mr-3"></i>
                                <span>Referral Analytics</span>
                            </a>
                        </li>
                        <!-- <li class="pt-6 border-t border-indigo-700 mt-6">
                            <a href="settings.php" class="flex items-center p-2 rounded hover:bg-indigo-900">
                                <i class="fas fa-cog mr-3"></i>
                                <span>Settings</span>
                            </a>
                        </li>-->
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

        <main class="flex-1 p-6 md:ml-64 transition-all duration-300 ease-in-out">
            <div class="bg-white rounded-lg shadow p-6 mb-6">
                <h3 class="text-xl font-semibold mb-4">Filter Reports</h3>
                <form action="" method="GET" class="flex flex-col md:flex-row md:items-end gap-4">
                    <div class="flex-1">
                        <label for="start_date" class="block text-sm font-medium text-gray-700 mb-1">Start Date</label>
                        <input type="text" id="start_date" name="start_date"
                            class="datepicker w-full px-4 py-2 border rounded" value="<?php echo $start_date; ?>">
                    </div>
                    <div class="flex-1">
                        <label for="end_date" class="block text-sm font-medium text-gray-700 mb-1">End Date</label>
                        <input type="text" id="end_date" name="end_date"
                            class="datepicker w-full px-4 py-2 border rounded" value="<?php echo $end_date; ?>">
                    </div>
                    <div class="flex-1">
                        <label for="project_id" class="block text-sm font-medium text-gray-700 mb-1">Project</label>
                        <select id="project_id" name="project_id" class="w-full px-4 py-2 border rounded">
                            <option value="all" <?php echo $project_id === 'all' ? 'selected' : ''; ?>>All Projects
                            </option>
                            <?php foreach($projects as $project): ?>
                            <option value="<?php echo $project['id']; ?>"
                                <?php echo $project_id == $project['id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($project['name']); ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <button type="submit"
                            class="bg-indigo-600 text-white px-4 py-2 rounded hover:bg-indigo-700">Apply Filter</button>
                    </div>
                </form>
            </div>

            <?php if($project_id !== 'all'): ?>
            <div class="bg-blue-50 border-l-4 border-blue-500 p-4 mb-6">
                <div class="flex">
                    <div class="flex-shrink-0">
                        <i class="fas fa-filter text-blue-600"></i>
                    </div>
                    <div class="ml-3">
                        <p class="text-sm text-blue-700">
                            Filtering contributions for project:
                            <strong>
                                <?php 
                                    $project_name = "Unknown";
                                    foreach($projects as $p) {
                                        if($p['id'] == $project_id) {
                                            $project_name = $p['name'];
                                            break;
                                        }
                                    }
                                    echo htmlspecialchars($project_name); 
                                ?>
                            </strong>
                            <a href="?start_date=<?php echo $start_date; ?>&end_date=<?php echo $end_date; ?>&project_id=all"
                                class="ml-2 text-blue-700 underline">
                                Clear filter
                            </a>
                        </p>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-5 gap-4 mb-6">
                <div class="bg-white rounded-lg shadow p-4">
                    <h4 class="text-gray-500 text-sm mb-1">Total Contributions</h4>
                    <p class="text-2xl font-bold text-indigo-600">
                        $<?php echo number_format($summary['total'] ?? 0, 2); ?></p>
                </div>
                <div class="bg-white rounded-lg shadow p-4">
                    <h4 class="text-gray-500 text-sm mb-1">Number of Contributions</h4>
                    <p class="text-2xl font-bold text-indigo-600"><?php echo number_format($summary['count'] ?? 0); ?>
                    </p>
                </div>
                <div class="bg-white rounded-lg shadow p-4">
                    <h4 class="text-gray-500 text-sm mb-1">Average Contribution</h4>
                    <p class="text-2xl font-bold text-indigo-600">
                        $<?php echo number_format($summary['average'] ?? 0, 2); ?></p>
                </div>
                <div class="bg-white rounded-lg shadow p-4">
                    <h4 class="text-gray-500 text-sm mb-1">Largest Contribution</h4>
                    <p class="text-2xl font-bold text-indigo-600">
                        $<?php echo number_format($summary['highest'] ?? 0, 2); ?></p>
                </div>
                <div class="bg-white rounded-lg shadow p-4">
                    <h4 class="text-gray-500 text-sm mb-1">Smallest Contribution</h4>
                    <p class="text-2xl font-bold text-indigo-600">
                        $<?php echo number_format($summary['lowest'] ?? 0, 2); ?></p>
                </div>
            </div>

            <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-6">
                <div class="bg-white rounded-lg shadow p-6">
                    <h3 class="text-xl font-semibold mb-4">Monthly Contribution Trends</h3>
                    <canvas id="monthlyChart" height="300"></canvas>
                </div>

                <div class="bg-white rounded-lg shadow p-6">
                    <h3 class="text-xl font-semibold mb-4">Contributions by Day of Week</h3>
                    <canvas id="dowChart" height="300"></canvas>
                </div>
            </div>

            <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-6">
                <div class="bg-white rounded-lg shadow p-6">
                    <h3 class="text-xl font-semibold mb-4">Contribution Size Distribution</h3>
                    <canvas id="sizeChart" height="300"></canvas>
                </div>

                <div class="bg-white rounded-lg shadow p-6">
                    <h3 class="text-xl font-semibold mb-4">Top Contributors</h3>
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-200">
                            <thead class="bg-gray-50">
                                <tr>
                                    <th
                                        class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                        Contributor</th>
                                    <th
                                        class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                        Total</th>
                                    <th
                                        class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                        Frequency</th>
                                    <th
                                        class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                        Last Contribution</th>
                                </tr>
                            </thead>
                            <tbody class="bg-white divide-y divide-gray-200">
                                <?php foreach ($top_contributors as $contributor): ?>
                                <tr>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <?php echo htmlspecialchars($contributor['name']); ?></td>
                                    <td class="px-6 py-4 whitespace-nowrap font-medium text-green-600">
                                        $<?php echo number_format($contributor['total'], 2); ?></td>
                                    <td class="px-6 py-4 whitespace-nowrap"><?php echo $contributor['frequency']; ?>
                                        times</td>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <?php echo date('M j, Y', strtotime($contributor['last_contribution'])); ?></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <!-- Recent Contributions -->
            <div class="bg-white rounded-lg shadow p-6 mb-6">
                <h3 class="text-xl font-semibold mb-4">Recent Contributions</h3>
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200">
                        <thead class="bg-gray-50">
                            <tr>
                                <th
                                    class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                    Contributor</th>
                                <th
                                    class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                    Amount</th>
                                <th
                                    class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                    Date</th>
                                <th
                                    class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                    Project</th>
                            </tr>
                        </thead>
                        <tbody class="bg-white divide-y divide-gray-200">
                            <?php foreach ($recent_contributions as $contribution): ?>
                            <tr>
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <?php echo htmlspecialchars($contribution['name']); ?></td>
                                <td class="px-6 py-4 whitespace-nowrap font-medium text-green-600">
                                    $<?php echo number_format($contribution['amount'], 2); ?></td>
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <?php echo date('M j, Y g:i A', strtotime($contribution['contributed_at'])); ?></td>
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <?php echo htmlspecialchars($contribution['project_name']); ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="bg-white rounded-lg shadow p-6">
                <h3 class="text-xl font-semibold mb-4">Export Reports</h3>
                <div class="flex flex-wrap gap-4">
                    <a href="export_report.php?type=csv&start=<?php echo $start_date; ?>&end=<?php echo $end_date; ?>&project_id=<?php echo $project_id; ?>"
                        class="bg-gray-600 text-white px-4 py-2 rounded hover:bg-gray-700 flex items-center">
                        <i class="fas fa-file-csv mr-2"></i> Export CSV
                    </a>
                    <a href="export_report.php?type=excel&start=<?php echo $start_date; ?>&end=<?php echo $end_date; ?>&project_id=<?php echo $project_id; ?>"
                        class="bg-green-600 text-white px-4 py-2 rounded hover:bg-green-700 flex items-center">
                        <i class="fas fa-file-excel mr-2"></i> Export Excel
                    </a>
                    <a href="export_report.php?type=pdf&start=<?php echo $start_date; ?>&end=<?php echo $end_date; ?>&project_id=<?php echo $project_id; ?>"
                        class="bg-red-600 text-white px-4 py-2 rounded hover:bg-red-700 flex items-center">
                        <i class="fas fa-file-pdf mr-2"></i> Export PDF
                    </a>
                </div>
            </div>
        </main>
    </div>

    <footer class="bg-indigo-700 text-white text-center py-4 mt-8">
        <p>&copy; 2025 Church - Admin Panel</p>
    </footer>

    <script>
    const sidebarToggle = document.getElementById('sidebar-toggle');
    const sidebar = document.getElementById('sidebar');
    const main = document.querySelector('main');

    sidebarToggle.addEventListener('click', () => {
        sidebar.classList.toggle('-translate-x-full');
        main.classList.toggle('md:ml-0');
    });

    window.addEventListener('resize', () => {
        if (window.innerWidth >= 768) {
            main.classList.add('md:ml-64');
            sidebar.classList.add('md:translate-x-0');
        } else {
            sidebar.classList.add('-translate-x-full');
            main.classList.remove('md:ml-0');
        }
    });

    flatpickr(".datepicker", {
        dateFormat: "Y-m-d",
        allowInput: true
    });

    const monthlyCtx = document.getElementById('monthlyChart').getContext('2d');
    const monthlyChart = new Chart(monthlyCtx, {
        type: 'line',
        data: {
            labels: [
                <?php foreach ($monthly_data as $data): ?> "<?php echo date('M Y', strtotime($data['month'] . '-01')); ?>",
                <?php endforeach; ?>
            ],
            datasets: [{
                label: 'Total Contributions ($)',
                data: [
                    <?php foreach ($monthly_data as $data): ?>
                    <?php echo $data['total']; ?>,
                    <?php endforeach; ?>
                ],
                borderColor: 'rgba(79, 70, 229, 1)',
                backgroundColor: 'rgba(79, 70, 229, 0.1)',
                borderWidth: 2,
                fill: true,
                tension: 0.4
            }, {
                label: 'Number of Contributions',
                data: [
                    <?php foreach ($monthly_data as $data): ?>
                    <?php echo $data['count']; ?>,
                    <?php endforeach; ?>
                ],
                borderColor: 'rgba(16, 185, 129, 1)',
                backgroundColor: 'transparent',
                borderWidth: 2,
                borderDash: [5, 5],
                yAxisID: 'y1'
            }]
        },
        options: {
            responsive: true,
            scales: {
                y: {
                    beginAtZero: true,
                    title: {
                        display: true,
                        text: 'Amount ($)'
                    },
                    ticks: {
                        callback: function(value) {
                            return '$' + value;
                        }
                    }
                },
                y1: {
                    beginAtZero: true,
                    position: 'right',
                    title: {
                        display: true,
                        text: 'Count'
                    },
                    grid: {
                        drawOnChartArea: false
                    }
                }
            }
        }
    });

    const dowCtx = document.getElementById('dowChart').getContext('2d');
    const dowChart = new Chart(dowCtx, {
        type: 'bar',
        data: {
            labels: [
                <?php foreach ($day_of_week_data as $data): ?> "<?php echo $data['day_name']; ?>",
                <?php endforeach; ?>
            ],
            datasets: [{
                label: 'Total Contributions ($)',
                data: [
                    <?php foreach ($day_of_week_data as $data): ?>
                    <?php echo $data['total']; ?>,
                    <?php endforeach; ?>
                ],
                backgroundColor: 'rgba(79, 70, 229, 0.7)'
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
            }
        }
    });

    const sizeCtx = document.getElementById('sizeChart').getContext('2d');
    const sizeChart = new Chart(sizeCtx, {
        type: 'pie',
        data: {
            labels: [
                <?php foreach ($size_distribution as $data): ?> "<?php echo $data['range_label']; ?> (<?php echo $data['count']; ?>)",
                <?php endforeach; ?>
            ],
            datasets: [{
                data: [
                    <?php foreach ($size_distribution as $data): ?>
                    <?php echo $data['total']; ?>,
                    <?php endforeach; ?>
                ],
                backgroundColor: [
                    'rgba(79, 70, 229, 0.7)',
                    'rgba(16, 185, 129, 0.7)',
                    'rgba(245, 158, 11, 0.7)',
                    'rgba(239, 68, 68, 0.7)',
                    'rgba(99, 102, 241, 0.7)',
                    'rgba(6, 182, 212, 0.7)'
                ]
            }]
        },
        options: {
            responsive: true,
            plugins: {
                legend: {
                    position: 'right'
                },
                tooltip: {
                    callbacks: {
                        label: function(context) {
                            const value = context.raw;
                            const label = context.label;
                            return label + ': $' + value.toLocaleString();
                        }
                    }
                }
            }
        }
    });
    </script>
</body>

</html>
<?php
include '../includes/auth.php';
include '../includes/functions.php';
include '../includes/db.php';

if (!($_SESSION['is_admin'] ?? false)) {
    header("Location: dashboard.php");
    exit();
}

$projects_stmt = $pdo->query("SELECT id, name FROM projects ORDER BY name");
$projects = $projects_stmt->fetchAll();

$project_filter = isset($_GET['project_id']) ? (int)$_GET['project_id'] : null;
$project_filter_sql = $project_filter ? "WHERE project_id = $project_filter" : "";
$project_join_sql = "LEFT JOIN contributions ON users.id = contributions.user_id";

$total_sql = "SELECT SUM(amount) as total FROM contributions";
if ($project_filter) {
    $total_sql .= " WHERE project_id = $project_filter";
}
$stmt = $pdo->query($total_sql);
$total_contributions = $stmt->fetch()['total'] ?? 0;

$monthly_sql = "SELECT 
                DATE_FORMAT(contributed_at, '%Y-%m') as month,
                SUM(amount) as total 
              FROM contributions";
if ($project_filter) {
    $monthly_sql .= " WHERE project_id = $project_filter";
}
$monthly_sql .= " GROUP BY DATE_FORMAT(contributed_at, '%Y-%m') 
                ORDER BY month DESC 
                LIMIT 6";
$stmt_monthly = $pdo->query($monthly_sql);
$monthly_data = $stmt_monthly->fetchAll();

$avg_sql = "SELECT AVG(amount) as average FROM contributions";
if ($project_filter) {
    $avg_sql .= " WHERE project_id = $project_filter";
}
$stmt_avg = $pdo->query($avg_sql);
$avg_contribution = $stmt_avg->fetch()['average'] ?? 0;

$contributors_sql = "SELECT COUNT(DISTINCT user_id) as total FROM contributions";
if ($project_filter) {
    $contributors_sql .= " WHERE project_id = $project_filter";
}
$stmt_contributors = $pdo->query($contributors_sql);
$total_contributors = $stmt_contributors->fetch()['total'] ?? 0;

$top_sql = "SELECT users.name, SUM(contributions.amount) as total 
          FROM contributions 
          JOIN users ON users.id = contributions.user_id";
if ($project_filter) {
    $top_sql .= " WHERE contributions.project_id = $project_filter";
}
$top_sql .= " GROUP BY user_id 
            ORDER BY total DESC 
            LIMIT 5";
$stmt_top = $pdo->query($top_sql);
$top_contributors = $stmt_top->fetchAll();

$recent_sql = "SELECT users.name, contributions.amount, contributions.contributed_at, projects.name as project_name 
           FROM contributions 
           JOIN users ON users.id = contributions.user_id
           JOIN projects ON projects.id = contributions.project_id";
if ($project_filter) {
    $recent_sql .= " WHERE contributions.project_id = $project_filter";
}
$recent_sql .= " ORDER BY contributed_at DESC 
             LIMIT 10";
$stmt_recent = $pdo->query($recent_sql);
$recent_contributions = $stmt_recent->fetchAll();

$tree_sql = "SELECT users.name, users.email, users.id, users.referred_by, 
           COALESCE(SUM(contributions.amount),0) as total_contribution 
           FROM users";
if ($project_filter) {
    $tree_sql .= " LEFT JOIN contributions ON users.id = contributions.user_id AND contributions.project_id = $project_filter";
} else {
    $tree_sql .= " LEFT JOIN contributions ON users.id = contributions.user_id";
}
$tree_sql .= " GROUP BY users.id";
$tree_stmt = $pdo->query($tree_sql);
$tree_data = $tree_stmt->fetchAll();
$users = $tree_data;

$active_project_name = "All Projects";
if ($project_filter) {
    $project_name_stmt = $pdo->prepare("SELECT name FROM projects WHERE id = ?");
    $project_name_stmt->execute([$project_filter]);
    $active_project_name = $project_name_stmt->fetch()['name'] ?? 'Unknown Project';
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Dashboard</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://d3js.org/d3.v7.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/3.9.1/chart.min.js"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
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
                            <a href="dashboard.php"
                                class="flex items-center p-2 rounded bg-indigo-900 hover:bg-indigo-900">
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
                            <a href="contribution_reports.php"
                                class="flex items-center p-2 rounded hover:bg-indigo-900">
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
                        <li>
                            <a href="manage_projects.php" class="flex items-center p-2 rounded hover:bg-indigo-900">
                                <i class="fas fa-project-diagram mr-3"></i>
                                <span>Manage Projects</span>
                            </a>
                        </li>
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

        <main class="flex-1 p-6 md:ml-64 transition-all duration-300 ease-in-out overflow-x-hidden">
            <div class="bg-white rounded-lg shadow p-6 mb-6">
                <h3 class="text-xl font-semibold mb-4">Filter by Project</h3>
                <div class="flex flex-col md:flex-row md:items-center gap-4">
                    <form action="" method="get" class="flex flex-col md:flex-row md:items-center gap-4 w-full">
                        <div class="flex-grow">
                            <select name="project_id" id="project-filter" class="w-full p-2 border rounded">
                                <option value="">All Projects</option>
                                <?php foreach ($projects as $project): ?>
                                <option value="<?php echo $project['id']; ?>"
                                    <?php echo $project_filter == $project['id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($project['name']); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <button type="submit" class="bg-indigo-600 text-white px-4 py-2 rounded hover:bg-indigo-700">
                            Apply Filter
                        </button>
                        <?php if ($project_filter): ?>
                        <a href="admin_dashboard.php"
                            class="bg-gray-200 text-gray-700 px-4 py-2 rounded hover:bg-gray-300">
                            Clear Filter
                        </a>
                        <?php endif; ?>
                    </form>
                </div>
                <?php if ($project_filter): ?>
                <div class="mt-4 p-3 bg-indigo-50 rounded">
                    <p class="text-indigo-700">
                        <i class="fas fa-filter mr-2"></i>
                        Showing contributions for:
                        <strong><?php echo htmlspecialchars($active_project_name); ?></strong>
                    </p>
                </div>
                <?php endif; ?>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6 mb-6">
                <div class="bg-white rounded-lg shadow p-6">
                    <div class="flex items-center">
                        <div class="p-3 rounded-full bg-indigo-100 text-indigo-800 mr-4">
                            <i class="fas fa-dollar-sign"></i>
                        </div>
                        <div>
                            <p class="text-sm text-gray-500">Total Contributions</p>
                            <p class="text-2xl font-bold">$<?php echo number_format($total_contributions, 2); ?></p>
                        </div>
                    </div>
                </div>

                <div class="bg-white rounded-lg shadow p-6">
                    <div class="flex items-center">
                        <div class="p-3 rounded-full bg-green-100 text-green-800 mr-4">
                            <i class="fas fa-users"></i>
                        </div>
                        <div>
                            <p class="text-sm text-gray-500">Total Contributors</p>
                            <p class="text-2xl font-bold"><?php echo $total_contributors; ?></p>
                        </div>
                    </div>
                </div>

                <div class="bg-white rounded-lg shadow p-6">
                    <div class="flex items-center">
                        <div class="p-3 rounded-full bg-yellow-100 text-yellow-800 mr-4">
                            <i class="fas fa-chart-bar"></i>
                        </div>
                        <div>
                            <p class="text-sm text-gray-500">Average Contribution</p>
                            <p class="text-2xl font-bold">$<?php echo number_format($avg_contribution, 2); ?></p>
                        </div>
                    </div>
                </div>

                <div class="bg-white rounded-lg shadow p-6">
                    <div class="flex items-center">
                        <div class="p-3 rounded-full bg-red-100 text-red-800 mr-4">
                            <i class="fas fa-sitemap"></i>
                        </div>
                        <div>
                            <p class="text-sm text-gray-500">Total Referral Chains</p>
                            <p class="text-2xl font-bold">
                                <?php echo count(array_filter($users, function($u) { return !$u['referred_by']; })); ?>
                            </p>
                        </div>
                    </div>
                </div>
            </div>

            <div class="bg-white rounded-lg shadow p-6 mb-6">
                <h3 class="text-xl font-semibold mb-4">Recent Contributions</h3>
                <div class="overflow-x-auto">
                    <table class="w-full table-auto">
                        <thead class="bg-indigo-100 text-indigo-800">
                            <tr>
                                <th class="p-3 text-left">Contributor</th>
                                <th class="p-3 text-left">Project</th>
                                <th class="p-3 text-left">Amount</th>
                                <th class="p-3 text-left">Date</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($recent_contributions as $contribution): ?>
                            <tr class="border-b hover:bg-gray-50">
                                <td class="p-3"><?php echo htmlspecialchars($contribution['name']); ?></td>
                                <td class="p-3"><?php echo htmlspecialchars($contribution['project_name']); ?></td>
                                <td class="p-3 font-semibold text-green-600">
                                    $<?php echo number_format($contribution['amount'], 2); ?></td>
                                <td class="p-3">
                                    <?php echo date('M j, Y g:i A', strtotime($contribution['contributed_at'])); ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="bg-white rounded-lg shadow p-6 mb-6">
                <div class="flex justify-between items-center mb-4">
                    <h3 class="text-xl font-semibold">All Users & Contributions</h3>
                    <div>
                        <input type="text" id="userSearch" placeholder="Search users..."
                            class="px-4 py-2 border rounded">
                    </div>
                </div>
                <div class="overflow-x-auto">
                    <table class="w-full table-auto" id="userTable">
                        <thead class="bg-indigo-600 text-white">
                            <tr>
                                <th class="p-3 text-left">User</th>
                                <th class="p-3 text-left">Email</th>
                                <th class="p-3 text-left">Referred By</th>
                                <th class="p-3 text-left">Total Contribution</th>
                                <th class="p-3 text-left">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($users as $user): ?>
                            <tr class="border-b hover:bg-gray-50">
                                <td class="p-3"><?php echo htmlspecialchars($user['name']); ?></td>
                                <td class="p-3"><?php echo htmlspecialchars($user['email']); ?></td>
                                <td class="p-3">
                                    <?php 
                  if ($user['referred_by']) {
                    $ref_stmt = $pdo->prepare("SELECT name FROM users WHERE id = ?");
                    $ref_stmt->execute([$user['referred_by']]);
                    $ref_name = $ref_stmt->fetch()['name'] ?? 'Unknown';
                    echo htmlspecialchars($ref_name);
                  } else {
                    echo 'None';
                  }
                  ?>
                                </td>
                                <td class="p-3 font-semibold text-green-600">
                                    $<?php echo number_format($user['total_contribution'] ?? 0, 2); ?></td>
                                <td class="p-3">
                                    <a href="user_detail.php?id=<?php echo $user['id']; ?>"
                                        class="text-indigo-600 hover:text-indigo-800">
                                        <i class="fas fa-eye mr-1"></i> View
                                    </a>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="bg-white rounded-lg shadow p-6 mb-6">
                <h3 class="text-xl font-semibold mb-4">Referral Tree (Interactive)</h3>
                <div id="treeChart" class="w-full h-96 bg-gray-50 rounded border overflow-auto"></div>
            </div>

            <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-6">
                <div class="bg-white rounded-lg shadow p-6">
                    <h3 class="text-xl font-semibold mb-4">Monthly Contributions</h3>
                    <canvas id="contributionChart" height="300"></canvas>
                </div>

                <div class="bg-white rounded-lg shadow p-6">
                    <h3 class="text-xl font-semibold mb-4">Top Contributors</h3>
                    <ul class="divide-y">
                        <?php foreach ($top_contributors as $contributor): ?>
                        <li class="py-3 flex justify-between items-center">
                            <span class="font-medium"><?php echo htmlspecialchars($contributor['name']); ?></span>
                            <span
                                class="text-green-600 font-bold">$<?php echo number_format($contributor['total'], 2); ?></span>
                        </li>
                        <?php endforeach; ?>
                    </ul>
                </div>
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

            const userSearch = document.getElementById('userSearch');
            const userTable = document.getElementById('userTable');

            userSearch.addEventListener('keyup', function() {
                const searchText = this.value.toLowerCase();
                const rows = userTable.querySelectorAll('tbody tr');

                rows.forEach(row => {
                    const text = row.textContent.toLowerCase();
                    row.style.display = text.includes(searchText) ? '' : 'none';
                });
            });

            const months = [
                <?php foreach (array_reverse($monthly_data) as $data): ?> "<?php echo date('M Y', strtotime($data['month'] . '-01')); ?>",
                <?php endforeach; ?>
            ];

            const contributionData = [
                <?php foreach (array_reverse($monthly_data) as $data): ?>
                <?php echo $data['total']; ?>,
                <?php endforeach; ?>
            ];

            const ctx = document.getElementById('contributionChart').getContext('2d');
            new Chart(ctx, {
                type: 'bar',
                data: {
                    labels: months,
                    datasets: [{
                        label: 'Monthly Contributions ($)',
                        data: contributionData,
                        backgroundColor: 'rgba(79, 70, 229, 0.7)',
                        borderColor: 'rgba(79, 70, 229, 1)',
                        borderWidth: 1
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

            const width = document.getElementById('treeChart').clientWidth;
            const height = document.getElementById('treeChart').clientHeight;

            const svg = d3.select("#treeChart")
                .append("svg")
                .attr("width", width)
                .attr("height", height);

            const g = svg.append("g")
                .attr("transform", "translate(50, 20)");

            const treeData = {
                name: "Organization Root",
                children: [
                    <?php foreach ($users as $user):
          if (!$user['referred_by']) { ?> {
                        name: "<?php echo addslashes($user['name']); ?>",
                        value: <?php echo $user['total_contribution'] ?? 0; ?>,
                        children: [
                            <?php
              $stmt2 = $pdo->prepare("SELECT name, id FROM users WHERE referred_by = ?");
              $stmt2->execute([$user['id']]);
              $refChildren = $stmt2->fetchAll();
              foreach ($refChildren as $child): 
                $stmt3 = $pdo->prepare("SELECT name, id FROM users WHERE referred_by = ?");
                $stmt3->execute([$child['id']]);
                $grandChildren = $stmt3->fetchAll();
              ?> {
                                name: "<?php echo addslashes($child['name']); ?>",
                                children: [
                                    <?php foreach ($grandChildren as $grandChild): ?> {
                                        name: "<?php echo addslashes($grandChild['name']); ?>"
                                    },
                                    <?php endforeach; ?>
                                ]
                            },
                            <?php endforeach; ?>
                        ]
                    },
                    <?php } endforeach; ?>
                ]
            };

            const root = d3.hierarchy(treeData);


            const treeLayout = d3.tree()
                .size([width - 100, height - 40]);

            treeLayout(root);

            g.selectAll(".link")
                .data(root.links())
                .join("path")
                .attr("class", "link")
                .attr("d", d3.linkVertical()
                    .x(d => d.x)
                    .y(d => d.y))
                .attr("fill", "none")
                .attr("stroke", "#ccc");

            const node = g.selectAll(".node")
                .data(root.descendants())
                .join("g")
                .attr("class", "node")
                .attr("transform", d => `translate(${d.x},${d.y})`)
                .attr("cursor", "pointer")
                .on("mouseover", function() {
                    d3.select(this).select("circle").attr("r", 8);
                })
                .on("mouseout", function() {
                    d3.select(this).select("circle").attr("r", 6);
                });


            node.append("circle")
                .attr("r", 6)
                .attr("fill", d => d.depth === 0 ? "#4F46E5" : (d.data.value > 1000 ? "#10B981" : "#6366F1"));

            node.append("text")
                .attr("dy", ".31em")
                .attr("x", d => d.children ? -8 : 8)
                .attr("text-anchor", d => d.children ? "end" : "start")
                .text(d => d.data.name)
                .style("font-size", "12px");

            const zoom = d3.zoom()
                .scaleExtent([0.5, 3])
                .on("zoom", (event) => {
                    g.attr("transform", event.transform);
                });

            svg.call(zoom);
            </script>
</body>

</html>
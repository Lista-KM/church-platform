<!-- Add this JavaScript to the bottom of your page, before the closing body tag -->
<script>
// Chart.js implementation for project distribution
document.addEventListener('DOMContentLoaded', function() {
    // Only initialize chart if the canvas element exists
    if (document.getElementById('projectDistributionChart')) {
        const ctx = document.getElementById('projectDistributionChart').getContext('2d');

        // Get data from PHP (assuming you've encoded it to JSON)
        const projectData = <?php echo json_encode(array_map(function($item) {
            return [
                'name' => $item['project_name'],
                'amount' => (float)$item['total_amount']
            ];
        }, $project_distribution)); ?>;

        // Extract labels and data
        const labels = projectData.map(item => item.name);
        const data = projectData.map(item => item.amount);

        // Generate random colors for each project
        const backgroundColors = projectData.map(() => {
            const r = Math.floor(Math.random() * 200 + 55);
            const g = Math.floor(Math.random() * 200 + 55);
            const b = Math.floor(Math.random() * 200 + 55);
            return `rgba(${r}, ${g}, ${b}, 0.7)`;
        });

        // Create the chart
        new Chart(ctx, {
            type: 'pie',
            data: {
                labels: labels,
                datasets: [{
                    data: data,
                    backgroundColor: backgroundColors,
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
                                const total = context.chart.data.datasets[0].data.reduce((a, b) =>
                                    a + b, 0);
                                const percentage = Math.round((value / total) * 100);
                                return `${context.label}: ${value.toLocaleString()} (${percentage}%)`;
                            }
                        }
                    }
                }
            }
        });
    }

    // Add line chart for project trend over time
    if (document.getElementById('projectTrendChart')) {
        const ctx = document.getElementById('projectTrendChart').getContext('2d');

        // Get data from PHP
        const trendData = <?php echo json_encode($project_trend_data ?? []); ?>;

        // Create datasets for each project
        const datasets = [];
        const projectNames = [...new Set(trendData.map(item => item.project_name))];

        projectNames.forEach((project, index) => {
            // Generate a unique color for each project
            const r = Math.floor(Math.random() * 200 + 55);
            const g = Math.floor(Math.random() * 200 + 55);
            const b = Math.floor(Math.random() * 200 + 55);
            const color = `rgba(${r}, ${g}, ${b}, 1)`;

            // Filter data for this project
            const projectData = trendData
                .filter(item => item.project_name === project)
                .map(item => ({
                    x: item.date,
                    y: parseFloat(item.amount)
                }));

            datasets.push({
                label: project,
                data: projectData,
                borderColor: color,
                backgroundColor: color.replace('1)', '0.1)'),
                tension: 0.1
            });
        });

        // Create the chart
        new Chart(ctx, {
            type: 'line',
            data: {
                datasets: datasets
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                scales: {
                    x: {
                        type: 'time',
                        time: {
                            unit: 'day',
                            tooltipFormat: 'MMM d, yyyy'
                        },
                        title: {
                            display: true,
                            text: 'Date'
                        }
                    },
                    y: {
                        beginAtZero: true,
                        title: {
                            display: true,
                            text: 'Contribution Amount'
                        }
                    }
                },
                plugins: {
                    legend: {
                        position: 'top'
                    },
                    tooltip: {
                        callbacks: {
                            label: function(context) {
                                return `${context.dataset.label}: ${context.raw.y.toLocaleString()}`;
                            }
                        }
                    }
                }
            }
        });
    }

    // Auto-submit form when filters change
    document.querySelectorAll('.filter-form select').forEach(select => {
        select.addEventListener('change', function() {
            this.closest('form').submit();
        });
    });

    // Toggle project details
    document.querySelectorAll('.project-toggle').forEach(button => {
        button.addEventListener('click', function() {
            const targetId = this.getAttribute('data-target');
            const targetElement = document.getElementById(targetId);

            if (targetElement) {
                const isVisible = targetElement.style.display !== 'none';
                targetElement.style.display = isVisible ? 'none' : 'block';
                this.textContent = isVisible ? 'Show Details' : 'Hide Details';
            }
        });
    });
});

// Function to export current filtered data to CSV
function exportToCSV() {
    const tableData = [];
    const headers = [];

    // Get headers
    document.querySelectorAll('.contributions-table thead th').forEach(th => {
        headers.push(th.textContent.trim());
    });
    tableData.push(headers);

    // Get row data
    document.querySelectorAll('.contributions-table tbody tr').forEach(row => {
        const rowData = [];
        row.querySelectorAll('td').forEach(cell => {
            rowData.push(cell.textContent.trim());
        });
        tableData.push(rowData);
    });

    // Create CSV content
    let csvContent = 'data:text/csv;charset=utf-8,';
    tableData.forEach(row => {
        csvContent += row.join(',') + '\r\n';
    });

    // Download file
    const encodedUri = encodeURI(csvContent);
    const link = document.createElement('a');
    link.setAttribute('href', encodedUri);
    link.setAttribute('download', 'contributions_export.csv');
    document.body.appendChild(link);
    link.click();
    document.body.removeChild(link);
}
</script>

<?php
// Add this at the top of your PHP section, after the other database queries

// Get project trend data for the last 30 days (for line chart)
if ($filter_project === 'all') {
    // If no specific project is selected, get data for all projects
    $stmt = $pdo->prepare("WITH RECURSIVE RefTree AS (
                          SELECT id FROM users WHERE id = ?
                          UNION ALL
                          SELECT u.id FROM users u JOIN RefTree rt ON u.referred_by = rt.id
                        ),
                        date_range AS (
                          SELECT CURDATE() - INTERVAL (a.a + (10 * b.a)) DAY AS date
                          FROM (SELECT 0 AS a UNION ALL SELECT 1 UNION ALL SELECT 2 UNION ALL SELECT 3 UNION ALL SELECT 4 UNION ALL SELECT 5 UNION ALL SELECT 6 UNION ALL SELECT 7 UNION ALL SELECT 8 UNION ALL SELECT 9) AS a
                          CROSS JOIN (SELECT 0 AS a UNION ALL SELECT 1 UNION ALL SELECT 2 UNION ALL SELECT 3) AS b
                          WHERE CURDATE() - INTERVAL (a.a + (10 * b.a)) DAY >= CURDATE() - INTERVAL 30 DAY
                        )
                        SELECT 
                          DATE_FORMAT(d.date, '%Y-%m-%d') as date,
                          p.name as project_name,
                          COALESCE(SUM(c.amount), 0) as amount
                        FROM date_range d
                        CROSS JOIN projects p
                        LEFT JOIN contributions c ON DATE(c.created_at) = d.date 
                            AND c.project_id = p.id
                            AND c.user_id IN (SELECT id FROM RefTree WHERE id != ?) $user_filter
                        GROUP BY d.date, p.id
                        ORDER BY d.date, p.name");
    $stmt->execute([$user_id, $user_id]);
} else {
    // If specific project is selected
    $stmt = $pdo->prepare("WITH RECURSIVE RefTree AS (
                          SELECT id FROM users WHERE id = ?
                          UNION ALL
                          SELECT u.id FROM users u JOIN RefTree rt ON u.referred_by = rt.id
                        ),
                        date_range AS (
                          SELECT CURDATE() - INTERVAL (a.a + (10 * b.a)) DAY AS date
                          FROM (SELECT 0 AS a UNION ALL SELECT 1 UNION ALL SELECT 2 UNION ALL SELECT 3 UNION ALL SELECT 4 UNION ALL SELECT 5 UNION ALL SELECT 6 UNION ALL SELECT 7 UNION ALL SELECT 8 UNION ALL SELECT 9) AS a
                          CROSS JOIN (SELECT 0 AS a UNION ALL SELECT 1 UNION ALL SELECT 2 UNION ALL SELECT 3) AS b
                          WHERE CURDATE() - INTERVAL (a.a + (10 * b.a)) DAY >= CURDATE() - INTERVAL 30 DAY
                        )
                        SELECT 
                          DATE_FORMAT(d.date, '%Y-%m-%d') as date,
                          p.name as project_name,
                          COALESCE(SUM(c.amount), 0) as amount
                        FROM date_range d
                        JOIN projects p ON p.id = ?
                        LEFT JOIN contributions c ON DATE(c.created_at) = d.date 
                            AND c.project_id = p.id
                            AND c.user_id IN (SELECT id FROM RefTree WHERE id != ?) $user_filter
                        GROUP BY d.date
                        ORDER BY d.date");
    $stmt->execute([$user_id, $filter_project, $user_id]);
}
$project_trend_data = $stmt->fetchAll();

// Get project details if a specific project is selected
$project_details = null;
if ($filter_project !== 'all') {
    $stmt = $pdo->prepare("SELECT 
                          p.*,
                          COUNT(DISTINCT c.user_id) as unique_contributors,
                          MIN(c.created_at) as first_contribution,
                          MAX(c.created_at) as latest_contribution
                        FROM projects p
                        LEFT JOIN contributions c ON p.id = c.project_id
                        WHERE p.id = ?
                        GROUP BY p.id");
    $stmt->execute([$filter_project]);
    $project_details = $stmt->fetch();
    
    // Get top contributors for this specific project
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
                          MAX(c.created_at) as last_contribution
                        FROM contributions c
                        JOIN users u ON c.user_id = u.id
                        JOIN RefTree rt ON c.user_id = rt.id
                        WHERE c.project_id = ? AND c.user_id != ? $date_filter
                        GROUP BY u.id
                        ORDER BY total_amount DESC
                        LIMIT 5");
    $stmt->execute([$user_id, $filter_project, $user_id]);
    $project_top_contributors = $stmt->fetchAll();
}
?>

<!-- Add this to your HTML section after the filters form -->
<div class="dashboard-actions">
    <button onclick="exportToCSV()" class="export-button">Export to CSV</button>
    <?php if ($filter_project !== 'all' && !empty($project_details)): ?>
    <a href="?user_id=<?php echo $filter_user; ?>&period=<?php echo $filter_period; ?>&project_id=all"
        class="back-button">Back to All Projects</a>
    <?php endif; ?>
</div>

<!-- Project Details Section (if a specific project is selected) -->
<?php if ($filter_project !== 'all' && !empty($project_details)): ?>
<div class="project-details-container">
    <div class="project-header">
        <h2><?php echo htmlspecialchars($project_details['name']); ?></h2>
        <div class="project-meta">
            <span>Started:
                <?php echo date('M d, Y', strtotime($project_details['first_contribution'] ?? $project_details['created_at'])); ?></span>
            <span>Contributors: <?php echo $project_details['unique_contributors']; ?></span>
            <span>Last Activity:
                <?php echo date('M d, Y', strtotime($project_details['latest_contribution'] ?? $project_details['updated_at'])); ?></span>
        </div>
    </div>

    <div class="project-description">
        <?php echo nl2br(htmlspecialchars($project_details['description'] ?? '')); ?>
    </div>

    <div class="project-stats">
        <div class="stat-card">
            <div class="stat-value"><?php echo number_format($summary['total_amount'] ?? 0, 2); ?></div>
            <div class="stat-label">Total Contributions</div>
        </div>
        <div class="stat-card">
            <div class="stat-value"><?php echo $summary['total_contributions'] ?? 0; ?></div>
            <div class="stat-label">Transactions</div>
        </div>
        <div class="stat-card">
            <div class="stat-value"><?php echo number_format($summary['average_amount'] ?? 0, 2); ?></div>
            <div class="stat-label">Average Amount</div>
        </div>
    </div>

    <!-- Project trend chart -->
    <div class="chart-container">
        <h3>Project Trend (Last 30 Days)</h3>
        <canvas id="projectTrendChart" height="250"></canvas>
    </div>

    <!-- Top contributors for this project -->
    <div class="top-contributors">
        <h3>Top Contributors to <?php echo htmlspecialchars($project_details['name']); ?></h3>
        <div class="contributor-cards">
            <?php foreach ($project_top_contributors as $contributor): ?>
            <div class="contributor-card">
                <div class="contributor-name"><?php echo htmlspecialchars($contributor['name']); ?></div>
                <div class="contributor-amount"><?php echo number_format($contributor['total_amount'], 2); ?></div>
                <div class="contributor-count"><?php echo $contributor['contributions_count']; ?> contributions</div>
                <div class="contributor-last">Last:
                    <?php echo date('M d, Y', strtotime($contributor['last_contribution'])); ?></div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>
<?php else: ?>
<!-- Project Distribution Section (for "All Projects" view) -->
<div class="dashboard-widget project-distribution">
    <h3>Contributions by Project</h3>
    <div class="project-distribution-charts">
        <div class="chart-container">
            <canvas id="projectDistributionChart" height="300"></canvas>
        </div>
        <div class="project-distribution-table">
            <table>
                <thead>
                    <tr>
                        <th>Project</th>
                        <th>Contributions</th>
                        <th>Total Amount</th>
                        <th>Percentage</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php 
                    $total_project_amount = array_sum(array_column($project_distribution, 'total_amount'));
                    foreach ($project_distribution as $project): 
                        $percentage = $total_project_amount > 0 ? ($project['total_amount'] / $total_project_amount) * 100 : 0;
                    ?>
                    <tr>
                        <td><?php echo htmlspecialchars($project['project_name']); ?></td>
                        <td><?php echo $project['contributions_count']; ?></td>
                        <td><?php echo number_format($project['total_amount'], 2); ?></td>
                        <td><?php echo number_format($percentage, 1); ?>%</td>
                        <td>
                            <a href="?user_id=<?php echo $filter_user; ?>&period=<?php echo $filter_period; ?>&project_id=<?php echo $project['id']; ?>"
                                class="view-details-button">View Details</a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Project Trend Section -->
<div class="dashboard-widget project-trends">
    <h3>Project Trends (Last 30 Days)</h3>
    <div class="chart-container">
        <canvas id="projectTrendChart" height="300"></canvas>
    </div>
</div>
<?php endif; ?>

<!-- CSS Styles for the Project Features -->
<style>
/* General Styles */
.dashboard-actions {
    display: flex;
    justify-content: flex-end;
    margin-bottom: 20px;
    gap: 10px;
}

.export-button,
.back-button {
    padding: 8px 16px;
    background-color: #f0f0f0;
    border: 1px solid #ddd;
    border-radius: 4px;
    cursor: pointer;
    text-decoration: none;
    color: #333;
    font-size: 14px;
}

.export-button:hover,
.back-button:hover {
    background-color: #e0e0e0;
}

/* Project Details */
.project-details-container {
    background-color: #f9f9f9;
    border-radius: 8px;
    padding: 20px;
    margin-bottom: 20px;
    box-shadow: 0 2px 5px rgba(0, 0, 0, 0.1);
}

.project-header {
    margin-bottom: 20px;
    border-bottom: 1px solid #eee;
    padding-bottom: 15px;
}

.project-header h2 {
    margin: 0 0 10px 0;
    color: #333;
    font-size: 24px;
}

.project-meta {
    display: flex;
    gap: 20px;
    color: #666;
    font-size: 14px;
}

.project-description {
    margin-bottom: 20px;
    line-height: 1.6;
}

/* Project Stats */
.project-stats {
    display: flex;
    gap: 20px;
    margin-bottom: 30px;
}

.stat-card {
    background-color: #fff;
    border-radius: 6px;
    padding: 15px;
    flex: 1;
    text-align: center;
    box-shadow: 0 2px 4px rgba(0, 0, 0, 0.05);
}

.stat-value {
    font-size: 24px;
    font-weight: bold;
    color: #2c6ecb;
    margin-bottom: 5px;
}

.stat-label {
    color: #666;
    font-size: 14px;
}

/* Chart Containers */
.chart-container {
    background-color: #fff;
    border-radius: 6px;
    padding: 20px;
    margin-bottom: 30px;
    box-shadow: 0 2px 4px rgba(0, 0, 0, 0.05);
}

.chart-container h3 {
    margin-top: 0;
    margin-bottom: 15px;
    font-size: 18px;
    color: #333;
}

/* Top Contributors */
.top-contributors {
    margin-bottom: 30px;
}

.top-contributors h3 {
    margin-bottom: 15px;
    font-size: 18px;
    color: #333;
}

.contributor-cards {
    display: flex;
    gap: 15px;
    overflow-x: auto;
    padding-bottom: 10px;
}

.contributor-card {
    background-color: #fff;
    border-radius: 6px;
    padding: 15px;
    min-width: 200px;
    box-shadow: 0 2px 4px rgba(0, 0, 0, 0.05);
}

.contributor-name {
    font-weight: bold;
    margin-bottom: 10px;
    color: #333;
}

.contributor-amount {
    font-size: 20px;
    color: #2c6ecb;
    margin-bottom: 5px;
}

.contributor-count,
.contributor-last {
    font-size: 13px;
    color: #666;
}

/* Project Distribution Table */
.project-distribution-table {
    margin-top: 20px;
}

.project-distribution-table table {
    width: 100%;
    border-collapse: collapse;
}

.project-distribution-table th,
.project-distribution-table td {
    padding: 10px;
    text-align: left;
    border-bottom: 1px solid #eee;
}

.project-distribution-table th {
    background-color: #f5f5f5;
    font-weight: bold;
}

.view-details-button {
    display: inline-block;
    padding: 5px 10px;
    background-color: #2c6ecb;
    color: white;
    border-radius: 4px;
    text-decoration: none;
    font-size: 13px;
}

.view-details-button:hover {
    background-color: #1e5eb8;
}

/* Project Distribution Chart Section */
.project-distribution-charts {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 20px;
}

@media (max-width: 992px) {
    .project-distribution-charts {
        grid-template-columns: 1fr;
    }

    .project-stats {
        flex-direction: column;
    }
}
</style>

<!-- Make sure to include Chart.js in your header -->
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script src="https://cdn.jsdelivr.net/npm/moment@2.29.1/moment.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/chartjs-adapter-moment@1.0.0/dist/chartjs-adapter-moment.min.js"></script>
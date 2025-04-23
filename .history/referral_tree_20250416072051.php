<?php
// REFERRAL TREE PAGE - referral_tree.php
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

// Focus on a specific user if requested (and in downline)
$focus_id = isset($_GET['focus']) ? (int)$_GET['focus'] : $user_id;

// Verify the focus user is in the current user's downline or is the user themselves
if ($focus_id != $user_id) {
    $stmt = $pdo->prepare("WITH RECURSIVE RefTree AS (
                            SELECT id FROM users WHERE id = ?
                            UNION ALL
                            SELECT u.id FROM users u JOIN RefTree rt ON u.referred_by = rt.id
                          )
                          SELECT COUNT(*) FROM RefTree WHERE id = ?");
    $stmt->execute([$user_id, $focus_id]);
    
    if ($stmt->fetchColumn() == 0) {
        // If not in downline, reset to user
        $focus_id = $user_id;
    }
}

// Get focus user details if not the logged-in user
$focus_user = $user;
if ($focus_id != $user_id) {
    $stmt = $pdo->prepare("SELECT id, name, email, referred_by FROM users WHERE id = ?");
    $stmt->execute([$focus_id]);
    $focus_user = $stmt->fetch();
}

// Get direct children of the focus user
$stmt = $pdo->prepare("WITH user_data AS (
                        SELECT 
                            u.id, 
                            u.name, 
                            u.email,
                            (SELECT COUNT(*) FROM users WHERE referred_by = u.id) as referral_count,
                            COALESCE((SELECT SUM(amount) FROM contributions WHERE user_id = u.id), 0) as contribution_amount
                        FROM users u
                        WHERE u.referred_by = ?
                      )
                      SELECT * FROM user_data
                      ORDER BY referral_count DESC, contribution_amount DESC, name ASC");
$stmt->execute([$focus_id]);
$direct_referrals = $stmt->fetchAll();

// Get the complete tree data for visualization
$stmt = $pdo->prepare("WITH RECURSIVE RefTree AS (
                        SELECT 
                            id, 
                            name, 
                            referred_by, 
                            0 as level,
                            CAST(id as CHAR(255)) as path
                        FROM users 
                        WHERE id = ?
                        
                        UNION ALL
                        
                        SELECT 
                            u.id, 
                            u.name, 
                            u.referred_by, 
                            rt.level + 1,
                            CONCAT(rt.path, ',', u.id)
                        FROM users u
                        JOIN RefTree rt ON u.referred_by = rt.id
                      )
                      SELECT 
                        rt.id,
                        rt.name,
                        rt.referred_by,
                        rt.level,
                        rt.path,
                        (SELECT COUNT(*) FROM users WHERE referred_by = rt.id) as children_count,
                        COALESCE((SELECT SUM(amount) FROM contributions WHERE user_id = rt.id), 0) as contribution_amount,
                        (
                            SELECT COALESCE(SUM(c.amount), 0)
                            FROM contributions c
                            JOIN users u ON c.user_id = u.id
                            WHERE FIND_IN_SET(u.id, rt.path)
                        ) as tree_contribution
                      FROM RefTree rt
                      ORDER BY level, name");
$stmt->execute([$focus_id]);
$tree_data = $stmt->fetchAll();

// Prepare tree JSON for D3.js visualization
$tree_json = json_encode($tree_data);

// Get breadcrumb navigation
$breadcrumb = [];
if ($focus_id != $user_id) {
    $current_id = $focus_user['referred_by'];
    $breadcrumb_ids = [];
    
    // Get all parent IDs up to the current user
    while ($current_id != $user_id && $current_id != null) {
        $breadcrumb_ids[] = $current_id;
        $stmt = $pdo->prepare("SELECT referred_by FROM users WHERE id = ?");
        $stmt->execute([$current_id]);
        $current_id = $stmt->fetchColumn();
    }
    
    // Add current user ID
    $breadcrumb_ids[] = $user_id;
    
    // Reverse array to get top-down hierarchy
    $breadcrumb_ids = array_reverse($breadcrumb_ids);
    
    // Get names for breadcrumb
    foreach ($breadcrumb_ids as $id) {
        $stmt = $pdo->prepare("SELECT id, name FROM users WHERE id = ?");
        $stmt->execute([$id]);
        $breadcrumb[] = $stmt->fetch();
    }
}

// Get overall contributions summary (self and downline)
// Here we sum the contributions for the entire tree for the focus user.
$stmt = $pdo->prepare("WITH RECURSIVE RefTree AS (
                        SELECT id FROM users WHERE id = ?
                        UNION ALL
                        SELECT u.id FROM users u JOIN RefTree rt ON u.referred_by = rt.id
                      )
                      SELECT 
                        COALESCE(SUM(c.amount), 0) as total_contributions
                      FROM contributions c
                      JOIN RefTree rt ON c.user_id = rt.id");
$stmt->execute([$focus_id]);
$contributions_summary = $stmt->fetch();

// Generate referral link
$referral_link = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http")
                  . "://$_SERVER[HTTP_HOST]/register.php?ref=" . urlencode($user['referral_code']);
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Referral Tree - My Account</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://d3js.org/d3.v7.min.js"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
    /* Vertical tree styles */
    .tree-container {
        overflow-x: auto;
    }

    .vertical-tree ul {
        list-style: none;
        padding-left: 1rem;
        border-left: 2px dashed #4f46e5;
    }

    .vertical-tree li {
        margin: 0.5rem 0;
        position: relative;
        padding-left: 1rem;
    }

    .vertical-tree li:before {
        content: '';
        position: absolute;
        left: -1.1rem;
        top: 0.5rem;
        width: 0.8rem;
        height: 0.1rem;
        background: #4f46e5;
    }

    /* Sidebar styling */
    .sidebar {
        transition: transform 0.3s ease;
    }

    .sidebar a {
        display: block;
        padding: 0.75rem 1rem;
        color: #fff;
    }

    .sidebar a:hover {
        background-color: #3730a3;
    }

    /* Mobile sidebar */
    @media (max-width: 768px) {
        .sidebar {
            position: fixed;
            top: 0;
            left: 0;
            bottom: 0;
            z-index: 40;
            transform: translateX(-100%);
        }

        .sidebar.active {
            transform: translateX(0);
        }

        .overlay {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0, 0, 0, 0.5);
            z-index: 30;
        }

        .overlay.active {
            display: block;
        }
    }

    /* Share buttons */
    .share-btn {
        background: #4f46e5;
        color: #fff;
        padding: 0.5rem 1rem;
        border-radius: 0.375rem;
        display: inline-block;
        margin-right: 0.5rem;
        margin-bottom: 0.5rem;
    }

    .share-btn:hover {
        background: #3730a3;
    }

    /* Responsive tooltip */
    .tooltip {
        max-width: 90vw;
    }

    /* Mobile share buttons wrapper */
    @media (max-width: 640px) {
        .share-buttons-wrapper {
            display: flex;
            flex-wrap: wrap;
        }
    }

    /* Responsive table */
    @media (max-width: 640px) {
        .responsive-table {
            display: block;
            width: 100%;
            overflow-x: auto;
        }
    }
    </style>
</head>

<body class="bg-gray-100">
    <!-- Header -->
    <header class="bg-indigo-700 text-white p-4 flex justify-between items-center shadow fixed w-full z-10">
        <div class="flex items-center">
            <button id="sidebar-toggle" class="mr-3 text-white md:hidden">
                <i class="fas fa-bars text-xl"></i>
            </button>
            <h1 class="text-xl font-semibold">Church Contribution</h1>
        </div>
        <div class="flex items-center space-x-4">
            <span class="hidden md:inline">Welcome, <?php echo htmlspecialchars($user['name']); ?></span>
            <a href="logout.php"
                class="bg-white text-indigo-700 px-4 py-2 rounded hover:bg-gray-100 transition">Logout</a>
        </div>
    </header>

    <!-- Mobile Menu Overlay -->
    <div id="overlay" class="overlay"></div>

    <!-- Page Wrapper -->
    <div class="flex min-h-screen pt-16">
        <!-- Added top padding to account for fixed header -->
        <!-- Sidebar -->
        <aside id="sidebar" class="bg-indigo-600 sidebar w-64">
            <div class="p-4 text-white text-xl font-semibold flex justify-between items-center">

                <button id="closeMenu" class="md:hidden text-white">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <nav>
                <a href="user_dashboard.php"><i class="fas fa-tachometer-alt mr-2"></i> Dashboard</a>
                <a href="referral_tree.php" class="bg-indigo-700"><i class="fas fa-sitemap mr-2"></i> Referral Tree</a>
                <a href="contributions.php"><i class="fas fa-chart-line mr-2"></i> Contributions</a>
                <a href="settings.php"><i class="fas fa-cog mr-2"></i> Settings</a>
                <a href="logout.php"><i class="fas fa-sign-out-alt mr-2"></i> Logout</a>
            </nav>
        </aside>

        <!-- Main Content -->
        <main class="flex-1 p-3 md:p-6">
            <!-- Breadcrumb Navigation -->
            <?php if (!empty($breadcrumb)): ?>
            <nav class="mb-4 text-sm overflow-x-auto whitespace-nowrap">
                <?php foreach ($breadcrumb as $crumb): ?>
                <a href="referral_tree.php?focus=<?php echo $crumb['id']; ?>" class="text-indigo-600 hover:underline">
                    <?php echo htmlspecialchars($crumb['name']); ?>
                </a>
                <?php if ($crumb !== end($breadcrumb)): ?>
                &raquo;
                <?php endif; ?>
                <?php endforeach; ?>
            </nav>
            <?php endif; ?>

            <!-- Referral Link Section -->
            <section class="mb-6 bg-white p-4 md:p-6 rounded-lg shadow">
                <h2 class="text-xl font-semibold mb-4">Your Invite Link</h2>
                <div class="flex flex-col md:flex-row md:items-center space-y-2 md:space-y-0 md:space-x-4">
                    <input id="referralLink" type="text" class="w-full p-2 border rounded"
                        value="<?php echo $referral_link; ?>" readonly>
                    <button onclick="copyReferralLink()"
                        class="bg-indigo-600 text-white px-4 py-2 rounded hover:bg-indigo-700">
                        <i class="fas fa-copy mr-2"></i> Copy Link
                    </button>
                </div>

                <div class="mt-4 share-buttons-wrapper">
                    <span class="mr-2 block md:inline-block mb-2 md:mb-0">Share on:</span>
                    <a href="https://wa.me/?text=<?php echo urlencode($referral_link); ?>" target="_blank"
                        class="share-btn"><i class="fab fa-whatsapp"></i> WhatsApp</a>
                    <a href="https://www.facebook.com/sharer/sharer.php?u=<?php echo urlencode($referral_link); ?>"
                        target="_blank" class="share-btn"><i class="fab fa-facebook-f"></i> Facebook</a>
                    <a href="mailto:?subject=Join me on our platform&body=<?php echo urlencode($referral_link); ?>"
                        class="share-btn"><i class="fas fa-envelope"></i> Email</a>
                </div>
            </section>

            <!-- Contributions Summary -->
            <section class="mb-6 bg-white p-4 md:p-6 rounded-lg shadow">
                <h2 class="text-xl font-semibold mb-4">Total Contributions</h2>
                <p class="text-3xl font-bold text-indigo-600">
                    $<?php echo number_format($contributions_summary['total_contributions'], 2); ?>
                </p>
                <p class="text-gray-600">This includes your contributions and those of everyone in your referral
                    network.</p>
            </section>

            <!-- Referral Tree Visualization -->
            <section class="bg-white p-4 md:p-6 rounded-lg shadow mb-6">
                <h2 class="text-xl font-semibold mb-4">My Network Tree</h2>
                <div id="treeChart" class="w-full h-64 md:h-96 bg-gray-50 rounded border overflow-auto"></div>
            </section>


            <!-- JavaScript for sidebar toggle, copy referral link, and D3.js visualization -->
            <script>
            // Sidebar toggle functionality
            const sidebarToggle = document.getElementById('sidebar-toggle');
            const closeMenu = document.getElementById('closeMenu');
            const sidebar = document.getElementById('sidebar');
            const overlay = document.getElementById('overlay');

            function toggleSidebar() {
                sidebar.classList.toggle('active');
                overlay.classList.toggle('active');
            }

            sidebarToggle.addEventListener('click', toggleSidebar);
            closeMenu.addEventListener('click', toggleSidebar);
            overlay.addEventListener('click', toggleSidebar);

            // Close sidebar when window is resized to larger than mobile breakpoint
            window.addEventListener('resize', function() {
                if (window.innerWidth > 768 && sidebar.classList.contains('active')) {
                    sidebar.classList.remove('active');
                    overlay.classList.remove('active');
                }
            });

            // Copy referral link functionality
            function copyReferralLink() {
                const linkInput = document.getElementById("referralLink");
                linkInput.select();
                linkInput.setSelectionRange(0, 99999);
                document.execCommand("copy");

                // Create a temporary element for feedback
                const feedback = document.createElement('div');
                feedback.textContent = 'Link copied!';
                feedback.style.position = 'fixed';
                feedback.style.bottom = '20px';
                feedback.style.left = '50%';
                feedback.style.transform = 'translateX(-50%)';
                feedback.style.backgroundColor = '#4F46E5';
                feedback.style.color = 'white';
                feedback.style.padding = '10px 20px';
                feedback.style.borderRadius = '4px';
                feedback.style.zIndex = '9999';

                document.body.appendChild(feedback);

                // Remove feedback after 2 seconds
                setTimeout(() => {
                    document.body.removeChild(feedback);
                }, 2000);
            }

            // D3.js Tree Visualization with responsiveness
            const treeData = <?php echo $tree_json; ?>;

            // Function to build hierarchy for D3.js
            function buildHierarchy(data, rootId) {
                const map = {};
                data.forEach(item => {
                    map[item.id] = {
                        id: item.id,
                        name: item.name,
                        contribution: item.contribution_amount,
                        treeContribution: item.tree_contribution,
                        childrenCount: item.children_count,
                        level: item.level,
                        children: []
                    };
                });

                const rootNode = map[rootId];
                data.forEach(item => {
                    if (item.id !== rootId && map[item.referred_by]) {
                        map[item.referred_by].children.push(map[item.id]);
                    }
                });

                return rootNode;
            }

            // Create responsive SVG
            function createVisualization() {
                // Clear previous visualization
                document.getElementById('treeChart').innerHTML = '';

                // Get dimensions
                const container = document.getElementById('treeChart');
                const width = container.clientWidth;
                const height = container.clientHeight;

                // Create SVG
                const svg = d3.select("#treeChart")
                    .append("svg")
                    .attr("width", width)
                    .attr("height", height)
                    .attr("viewBox", `0 0 ${width} ${height}`)
                    .attr("preserveAspectRatio", "xMidYMid meet");

                // Create group with initial transform
                const g = svg.append("g")
                    .attr("transform", "translate(50, 20)");

                // Build hierarchy
                const rootNode = buildHierarchy(treeData, <?php echo $focus_id; ?>);

                // Create D3 hierarchy
                const root = d3.hierarchy(rootNode);

                // Adjust tree layout based on screen size
                const treeLayout = d3.tree()
                    .size([width - 100, height - 60]);

                // Calculate positions
                treeLayout(root);

                // Draw links
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

                // Create nodes
                const node = g.selectAll(".node")
                    .data(root.descendants())
                    .join("g")
                    .attr("class", "node")
                    .attr("transform", d => `translate(${d.x},${d.y})`)
                    .attr("cursor", "pointer")
                    .on("click", function(event, d) {
                        if (d.data.id != <?php echo $focus_id; ?>) {
                            window.location.href = "referral_tree.php?focus=" + d.data.id;
                        }
                    })
                    .on("mouseover", function(event, d) {
                        d3.select(this).select("circle").attr("r", 8);

                        // Show tooltip with responsive positioning
                        const tooltipContent = `
            <strong>${d.data.name}</strong><br>
            Direct Referrals: ${d.data.childrenCount}<br>
            Contribution: $${d.data.contribution.toFixed(2)}<br>
            Team Contribution: $${d.data.treeContribution.toFixed(2)}
          `;

                        tooltip
                            .style("opacity", 1)
                            .html(tooltipContent);

                        // Position tooltip responsively
                        const tooltipWidth = tooltip.node().offsetWidth;
                        const windowWidth = window.innerWidth;

                        let leftPos = event.pageX + 10;
                        // Adjust if tooltip would go off-screen
                        if (leftPos + tooltipWidth > windowWidth - 20) {
                            leftPos = event.pageX - tooltipWidth - 10;
                        }

                        tooltip
                            .style("left", leftPos + "px")
                            .style("top", (event.pageY - 28) + "px");
                    })
                    .on("mouseout", function() {
                        d3.select(this).select("circle").attr("r", 6);
                        tooltip.style("opacity", 0);
                    });

                // Add circles
                node.append("circle")
                    .attr("r", 6)
                    .attr("fill", d => {
                        if (d.data.id == <?php echo $focus_id; ?>) return "#4F46E5";
                        if (d.data.treeContribution > 1000) return "#10B981";
                        return "#6366F1";
                    })
                    .attr("stroke", "#fff")
                    .attr("stroke-width", 1.5);

                // Add labels with responsive font size
                node.append("text")
                    .attr("dy", ".31em")
                    .attr("x", d => d.children ? -8 : 8)
                    .attr("text-anchor", d => d.children ? "end" : "start")
                    .text(d => {
                        // Truncate names on small screens
                        if (window.innerWidth < 640 && d.data.name.length > 8) {
                            return d.data.name.substring(0, 6) + '...';
                        }
                        return d.data.name;
                    })
                    .style("font-size", window.innerWidth < 640 ? "10px" : "12px")
                    .style("fill", "#4B5563");

                // Add zoom behavior
                const zoom = d3.zoom()
                    .scaleExtent([0.5, 3])
                    .on("zoom", (event) => {
                        g.attr("transform", event.transform);
                    });

                svg.call(zoom);

                // Initial zoom to fit everything
                if (root.height > 2) {
                    svg.call(zoom.translateBy, width / 4, 0);
                }
            }

            // Create tooltip
            const tooltip = d3.select("body").append("div")
                .attr("class", "tooltip")
                .style("opacity", 0)
                .style("position", "absolute")
                .style("padding", "10px")
                .style("background", "white")
                .style("border", "1px solid #ddd")
                .style("border-radius", "4px")
                .style("pointer-events", "none")
                .style("font-size", "12px")
                .style("box-shadow", "0 2px 5px rgba(0,0,0,0.1)");

            // Initial visualization
            createVisualization();

            // Redraw on window resize
            window.addEventListener('resize', function() {
                // Debounce resize event
                clearTimeout(window.resizeTimer);
                window.resizeTimer = setTimeout(function() {
                    createVisualization();
                }, 250);
            });
            </script>
</body>

</html>
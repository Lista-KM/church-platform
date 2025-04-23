<?php
include 'includes/db.php';
include 'includes/functions.php';

// Pre-fill referral from URL if available
$referralCode = $_GET['ref'] ?? null;
$referralId = null;
$message = '';
$showEmailForm = true;
$showUserForm = false;
$showContributionForm = false;
$referrerInfo = null;

// Check if email form was submitted
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['check_email'])) {
    $email = sanitize($_POST['email']);
    
    // Check if user exists
    $stmt = $pdo->prepare("SELECT id, name FROM users WHERE email = ?");
    $stmt->execute([$email]);
    $user = $stmt->fetch();
    
    if ($user) {
        // User exists - show simplified contribution form
        $showEmailForm = false;
        $showContributionForm = true;
        $userId = $user['id'];
        $userName = $user['name'];
    } else {
        // New user - show cascading form
        $showEmailForm = false;
        $showUserForm = true;
    }
}

// Handle contribution form submission for existing users
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['contribute_existing'])) {
    $email = sanitize($_POST['email']);
    $amount = sanitize($_POST['amount']);
    $projectId = sanitize($_POST['project_id']);
    
    // Get user ID
    $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ?");
    $stmt->execute([$email]);
    $userId = $stmt->fetchColumn();
    
    if ($userId) {
        // Record contribution
        $stmt = $pdo->prepare("INSERT INTO contributions (user_id, amount, project_id, contributed_at) VALUES (?, ?, ?, NOW())");
        $stmt->execute([$userId, $amount, $projectId]);
        
        $message = "Thank you for your contribution!";
        $showEmailForm = true;
        $showContributionForm = false;
    }
}

// Handle new user contribution without registration
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['contribute_new'])) {
    $name = sanitize($_POST['name']);
    $email = sanitize($_POST['email']);
    $amount = sanitize($_POST['amount']);
    $projectId = sanitize($_POST['project_id']);
    $referralCode = sanitize($_POST['referral'] ?? null);
    
    // Lookup referrer by referral code
    if ($referralCode) {
        $refCheck = $pdo->prepare("SELECT id FROM users WHERE referral_code = ?");
        $refCheck->execute([$referralCode]);
        $referralId = $refCheck->fetchColumn(); // Will be null if not found
    }
    
    // Create temporary user record
    $stmt = $pdo->prepare("INSERT INTO users (name, email, referred_by) VALUES (?, ?, ?)");
    $stmt->execute([$name, $email, $referralId]);
    $newUserId = $pdo->lastInsertId();
    
    // Generate referral code for this new user
    $generatedCode = strtoupper(substr(md5($name . $email . $newUserId), 0, 8));
    $update = $pdo->prepare("UPDATE users SET referral_code = ? WHERE id = ?");
    $update->execute([$generatedCode, $newUserId]);
    
    // Record contribution
    $stmt = $pdo->prepare("INSERT INTO contributions (user_id, amount, project_id, contributed_at) VALUES (?, ?, ?, NOW())");
    $stmt->execute([$newUserId, $amount, $projectId]);
    
    $message = "Thank you for your contribution! If you'd like to create a full account, you can register using the same email.";
    $showEmailForm = true;
    $showUserForm = false;
    $showContributionForm = false;
}

// Get projects for dropdown
$stmt = $pdo->prepare("SELECT id, name FROM projects ORDER BY name");
$stmt->execute();
$projects = $stmt->fetchAll();

// If there's a referral code, get referrer info to display tree preview
if ($referralCode) {
    $stmt = $pdo->prepare("
        SELECT u.name, u.id, u.referred_by
        FROM users u
        WHERE u.referral_code = ?
    ");
    $stmt->execute([$referralCode]);
    $referrerInfo = $stmt->fetch();
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Contribute</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>

<body class="bg-gray-100 min-h-screen flex flex-col items-center pt-10">
    <div class="w-full max-w-md bg-white p-8 rounded-lg shadow-md">
        <h2 class="text-3xl font-semibold text-center mb-8 text-gray-800">Make a Contribution</h2>

        <?php if ($message): ?>
        <div class="bg-green-100 text-green-700 border-l-4 border-green-500 p-4 mb-4">
            <p><?php echo $message; ?></p>
        </div>
        <?php endif; ?>

        <?php if ($showEmailForm): ?>
        <form method="POST" action="">
            <div class="mb-4">
                <label for="email" class="block text-sm font-medium text-gray-600">Email Address</label>
                <input type="email" name="email" id="email" placeholder="Enter your email" required
                    class="mt-1 block w-full px-4 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500">
            </div>

            <input type="hidden" name="referral" value="<?php echo htmlspecialchars($referralCode ?? ''); ?>">
            <button type="submit" name="check_email"
                class="w-full py-2 px-4 bg-indigo-600 text-white font-semibold rounded-lg hover:bg-indigo-700 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-opacity-50">
                Continue
            </button>

            <div class="mt-4 text-center text-sm text-gray-600">
                <a href="register.php" class="text-indigo-600 hover:text-indigo-700">Register for an account</a> |
                <a href="login.php" class="text-indigo-600 hover:text-indigo-700">Login to existing account</a>
            </div>
        </form>
        <?php endif; ?>

        <?php if ($showUserForm): ?>
        <form method="POST" action="">
            <div class="mb-4">
                <label for="name" class="block text-sm font-medium text-gray-600">Full Name</label>
                <input type="text" name="name" id="name" placeholder="Your full name" required
                    class="mt-1 block w-full px-4 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500">
            </div>

            <div class="mb-4">
                <label for="project_id" class="block text-sm font-medium text-gray-600">Select Project</label>
                <select name="project_id" id="project_id" required
                    class="mt-1 block w-full px-4 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500">
                    <option value="">-- Select Project --</option>
                    <?php foreach ($projects as $project): ?>
                    <option value="<?php echo $project['id']; ?>"><?php echo htmlspecialchars($project['name']); ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="mb-4">
                <label for="amount" class="block text-sm font-medium text-gray-600">Contribution Amount</label>
                <input type="number" name="amount" id="amount" placeholder="Amount" required step="0.01" min="0.01"
                    class="mt-1 block w-full px-4 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500">
            </div>

            <input type="hidden" name="email" value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>">
            <input type="hidden" name="referral" value="<?php echo htmlspecialchars($referralCode ?? ''); ?>">

            <button type="submit" name="contribute_new"
                class="w-full py-2 px-4 bg-indigo-600 text-white font-semibold rounded-lg hover:bg-indigo-700 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-opacity-50">
                Make Contribution
            </button>

            <p class="mt-4 text-center text-sm text-gray-600">
                Want to create an account? <a
                    href="register.php<?php echo $referralCode ? '?ref=' . urlencode($referralCode) : ''; ?>"
                    class="text-indigo-600 hover:text-indigo-700">Register here</a>
            </p>
        </form>
        <?php endif; ?>

        <?php if ($showContributionForm): ?>
        <form method="POST" action="">
            <div class="mb-4 bg-gray-50 p-3 rounded-lg">
                <p class="text-gray-700">Contributing as: <strong><?php echo htmlspecialchars($userName); ?></strong>
                </p>
            </div>

            <div class="mb-4">
                <label for="project_id" class="block text-sm font-medium text-gray-600">Select Project</label>
                <select name="project_id" id="project_id" required
                    class="mt-1 block w-full px-4 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500">
                    <option value="">-- Select Project --</option>
                    <?php foreach ($projects as $project): ?>
                    <option value="<?php echo $project['id']; ?>"><?php echo htmlspecialchars($project['name']); ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="mb-4">
                <label for="amount" class="block text-sm font-medium text-gray-600">Contribution Amount</label>
                <input type="number" name="amount" id="amount" placeholder="Amount" required step="0.01" min="0.01"
                    class="mt-1 block w-full px-4 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500">
            </div>

            <input type="hidden" name="email" value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>">

            <button type="submit" name="contribute_existing"
                class="w-full py-2 px-4 bg-indigo-600 text-white font-semibold rounded-lg hover:bg-indigo-700 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-opacity-50">
                Make Contribution
            </button>
        </form>
        <?php endif; ?>
    </div>

    <!-- Referral Tree Preview -->
    <?php if ($referrerInfo): ?>
    <div class="w-full max-w-md bg-white p-8 rounded-lg shadow-md mt-6">
        <h3 class="text-xl font-semibold mb-4 text-gray-800">Referral Network Preview</h3>
        <?php echo displayReferralTreePreview($pdo, $referrerInfo['id']); ?>
        <p class="mt-4 text-center text-sm text-gray-600">
            <a href="register.php<?php echo $referralCode ? '?ref=' . urlencode($referralCode) : ''; ?>"
                class="text-indigo-600 hover:text-indigo-700">Register for a full account</a> to see detailed referral
            information.
        </p>
    </div>
    <?php endif; ?>
</body>

</html>

<?php
// Function to build the tree data structure for D3.js
function buildReferralTreeData($pdo, $referrerId, $maxDepth = 2, $currentDepth = 0) {
    // Get referrer info
    $stmt = $pdo->prepare("SELECT id, name FROM users WHERE id = ?");
    $stmt->execute([$referrerId]);
    $referrer = $stmt->fetch();
    
    if (!$referrer) return null;
    
    // Get direct referrals
    $stmt = $pdo->prepare("SELECT id, name FROM users WHERE referred_by = ?");
    $stmt->execute([$referrerId]);
    $referrals = $stmt->fetchAll();
    $referralCount = count($referrals);
    
    // Create node data
    $nodeData = [
        'name' => $referrer['name'],
        'id' => $referrer['id'],
        'referralCount' => $referralCount,
        'children' => []
    ];
    
    // If we haven't reached max depth and there are children, process them
    if ($currentDepth < $maxDepth && $referralCount > 0) {
        foreach ($referrals as $referral) {
            $childNode = buildReferralTreeData($pdo, $referral['id'], $maxDepth, $currentDepth + 1);
            if ($childNode) {
                $nodeData['children'][] = $childNode;
            }
        }
    }
    
    return $nodeData;
}

// Function to display the D3.js referral tree
function displayReferralTreeD3($pdo, $referrerId, $maxDepth = 2) {
    // Build the tree data
    $treeData = buildReferralTreeData($pdo, $referrerId, $maxDepth);
    
    if (!$treeData) return '';
    
    // JSON encode the tree data for JavaScript
    $treeDataJson = json_encode($treeData);
    
    // Include the D3.js visualization with the data
    $output = '<div class="referral-tree-container">
        <svg id="referral-tree"></svg>
        <div class="controls">
            <div class="control-btn" id="zoom-in">+</div>
            <div class="control-btn" id="zoom-out">-</div>
            <div class="control-btn" id="zoom-reset">⟲</div>
        </div>
        <div class="tooltip" id="tooltip"></div>
    </div>';
    
    // Add the necessary styles and scripts
    $output .= '
    <style>
        .referral-tree-container {
            width: 100%;
            height: 600px;
            position: relative;
            overflow: hidden;
            font-family: "Inter", -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
        }
        
        .node {
            cursor: pointer;
        }
        
        .node-rect {
            fill: white;
            stroke: #e5e7eb;
            stroke-width: 1px;
            rx: 8px;
            ry: 8px;
            filter: drop-shadow(0 1px 2px rgba(0, 0, 0, 0.05));
            transition: all 0.3s;
        }
        
        .node-rect.root {
            fill: #eef2ff;
            stroke: #c7d2fe;
        }
        
        .node-rect:hover {
            stroke: #6366f1;
            stroke-width: 2px;
        }
        
        .avatar {
            fill: #6366f1;
            transition: all 0.3s;
        }
        
        .avatar.root {
            fill: #4f46e5;
        }
        
        .avatar-text {
            fill: white;
            font-weight: 500;
            text-anchor: middle;
            dominant-baseline: central;
            user-select: none;
        }
        
        .name-text {
            fill: #1f2937;
            font-weight: 500;
            dominant-baseline: central;
        }
        
        .count-text {
            fill: #6b7280;
            font-size: 0.85em;
            dominant-baseline: central;
        }
        
        .register-text {
            fill: #4f46e5;
            font-size: 0.75em;
            text-anchor: middle;
            cursor: pointer;
        }
        
        .link {
            fill: none;
            stroke: #d1d5db;
            stroke-width: 1.5px;
        }
        
        .link.highlighted {
            stroke: #6366f1;
            stroke-width: 2px;
        }
        
        .controls {
            position: absolute;
            bottom: 20px;
            right: 20px;
            display: flex;
            gap: 8px;
        }
        
        .control-btn {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background: white;
            border: 1px solid #e5e7eb;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            font-size: 18px;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
        }
        
        .control-btn:hover {
            background: #f9fafb;
        }
        
        .tooltip {
            position: absolute;
            padding: 8px 12px;
            background: #fff;
            border: 1px solid #e5e7eb;
            border-radius: 6px;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
            pointer-events: none;
            opacity: 0;
            transition: opacity 0.2s;
            font-size: 14px;
        }
    </style>
    
    <script src="https://cdnjs.cloudflare.com/ajax/libs/d3/7.8.5/d3.min.js"></script>
    <script>
        // Initialize the tree with data from PHP
        document.addEventListener("DOMContentLoaded", function() {
            const treeData = ' . $treeDataJson . ';
            initReferralTree(treeData);
        });
        
        function initReferralTree(treeData) {
            const container = document.querySelector(".referral-tree-container");
            const width = container.offsetWidth;
            const height = container.offsetHeight;
            
            // Create the SVG
            const svg = d3.select("#referral-tree")
                .attr("width", width)
                .attr("height", height);
                
            // Create a group that we\'ll apply transforms to
            const g = svg.append("g");
            
            // Create zoom behavior
            const zoom = d3.zoom()
                .scaleExtent([0.1, 3])
                .on("zoom", (event) => {
                    g.attr("transform", event.transform);
                });
            
            // Apply zoom behavior to SVG
            svg.call(zoom);
            
            // Center the tree initially
            const initialTransform = d3.zoomIdentity
                .translate(width / 2, 80)
                .scale(1);
            
            svg.call(zoom.transform, initialTransform);
            
            // Create tree layout
            const treeLayout = d3.tree()
                .size([width - 200, height - 160]);
            
            // Create hierarchy from data
            const root = d3.hierarchy(treeData);
            
            // Assign x,y positions to nodes
            treeLayout(root);
            
            // Create links
            const links = g.selectAll(".link")
                .data(root.links())
                .enter()
                .append("path")
                .attr("class", "link")
                .attr("d", d => {
                    return `M${d.source.x},${d.source.y} 
                            C${d.source.x},${(d.source.y + d.target.y) / 2} 
                            ${d.target.x},${(d.source.y + d.target.y) / 2} 
                            ${d.target.x},${d.target.y}`;
                });
            
            // Create nodes
            const nodes = g.selectAll(".node")
                .data(root.descendants())
                .enter()
                .append("g")
                .attr("class", "node")
                .attr("transform", d => `translate(${d.x},${d.y})`)
                .on("mouseover", function(event, d) {
                    // Highlight node and connections
                    d3.select(this).select(".node-rect")
                        .style("stroke", "#6366f1")
                        .style("stroke-width", "2px");
                    
                    // Show tooltip
                    const tooltip = d3.select("#tooltip");
                    tooltip.style("opacity", 1)
                        .html(`<strong>${d.data.name}</strong><br>${d.data.referralCount} referrals`)
                        .style("left", (event.pageX + 10) + "px")
                        .style("top", (event.pageY - 20) + "px");
                    
                    // Highlight connected links
                    links.classed("highlighted", l => 
                        l.source === d || l.target === d);
                })
                .on("mouseout", function() {
                    // Remove highlight
                    d3.select(this).select(".node-rect")
                        .style("stroke", d => d.depth === 0 ? "#c7d2fe" : "#e5e7eb")
                        .style("stroke-width", "1px");
                    
                    // Hide tooltip
                    d3.select("#tooltip").style("opacity", 0);
                    
                    // Remove link highlight
                    links.classed("highlighted", false);
                });
            
            // Add node backgrounds
            nodes.append("rect")
                .attr("class", d => `node-rect ${d.depth === 0 ? "root" : ""}`)
                .attr("x", -70)
                .attr("y", -20)
                .attr("width", 140)
                .attr("height", 45);
            
            // Add avatars
            nodes.append("circle")
                .attr("class", d => `avatar ${d.depth === 0 ? "root" : ""}`)
                .attr("cx", -50)
                .attr("cy", 0)
                .attr("r", 15);
            
            // Add avatar text (first letter of name)
            nodes.append("text")
                .attr("class", "avatar-text")
                .attr("x", -50)
                .attr("y", 0)
                .text(d => d.data.name.substring(0, 1));
            
            // Add names
            nodes.append("text")
                .attr("class", "name-text")
                .attr("x", -25)
                .attr("y", 0)
                .text(d => d.data.name);
            
            // Add referral counts
            nodes.append("text")
                .attr("class", "count-text")
                .attr("x", 25)
                .attr("y", 0)
                .text(d => `(${d.data.referralCount} referrals)`);
            
            // Add register text for nodes with children
            nodes.filter(d => d.depth > 0 && d.data.referralCount > 0)
                .append("text")
                .attr("class", "register-text")
                .attr("x", 0)
                .attr("y", 35)
                .text("Register to see complete tree");
            
            // Setup control buttons
            document.getElementById("zoom-in").addEventListener("click", () => {
                svg.transition()
                    .duration(500)
                    .call(zoom.scaleBy, 1.2);
            });
            
            document.getElementById("zoom-out").addEventListener("click", () => {
                svg.transition()
                    .duration(500)
                    .call(zoom.scaleBy, 0.8);
            });
            
            document.getElementById("zoom-reset").addEventListener("click", () => {
                svg.transition()
                    .duration(500)
                    .call(zoom.transform, initialTransform);
            });
            
            // Handle window resize
            window.addEventListener("resize", () => {
                const newWidth = container.offsetWidth;
                const newHeight = container.offsetHeight;
                
                svg.attr("width", newWidth)
                    .attr("height", newHeight);
                    
                treeLayout.size([newWidth - 200, newHeight - 160]);
                treeLayout(root);
                
                // Update links
                links.attr("d", d => {
                    return `M${d.source.x},${d.source.y} 
                            C${d.source.x},${(d.source.y + d.target.y) / 2} 
                            ${d.target.x},${(d.source.y + d.target.y) / 2} 
                            ${d.target.x},${d.target.y}`;
                });
                
                // Update nodes
                nodes.attr("transform", d => `translate(${d.x},${d.y})`);
            });
        }
    </script>';
    
    return $output;
}

// Function to display a limited preview for non-registered users (simplified version)
function previewReferralNodeD3($pdo, $userId) {
    // Build tree data but limit depth to 1 for preview
    $treeData = buildReferralTreeData($pdo, $userId, 1);
    
    if (!$treeData) return '';
    
    // Return a simplified version of the tree display
    return displayReferralTreeD3($pdo, $userId, 1);
}
?>
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
            <div class="mt-4 text-center text-sm text-gray-600">
                <a href="register.php" class="text-indigo-600 hover:text-indigo-700">Register for an account</a> |
                <a href="login.php" class="text-indigo-600 hover:text-indigo-700">Login to existing account</a>
            </div>

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

// Function to display referral tree preview with D3.js
function displayReferralTreePreview($pdo, $referrerId) {
    // Build the tree data
    $treeData = buildReferralTreeData($pdo, $referrerId, 1);
    
    if (!$treeData) return '';
    
    // JSON encode the tree data for JavaScript
    $treeDataJson = json_encode($treeData);
    
    // Build the tree visualization
    $output = '<div class="tree-container" style="width: 100%; height: 400px; position: relative; overflow: hidden;">';
    $output .= '<svg id="referralTree" width="100%" height="100%" xmlns="http://www.w3.org/2000/svg"></svg>';
    $output .= '<div class="tree-controls" style="position: absolute; bottom: 10px; right: 10px; display: flex; gap: 5px;">';
    $output .= '<button id="zoom-in" class="tree-control-btn" style="width: 30px; height: 30px; border-radius: 50%; background: white; border: 1px solid #e5e7eb; display: flex; align-items: center; justify-content: center; cursor: pointer; font-size: 16px; box-shadow: 0 1px 2px rgba(0,0,0,0.1);">+</button>';
    $output .= '<button id="zoom-out" class="tree-control-btn" style="width: 30px; height: 30px; border-radius: 50%; background: white; border: 1px solid #e5e7eb; display: flex; align-items: center; justify-content: center; cursor: pointer; font-size: 16px; box-shadow: 0 1px 2px rgba(0,0,0,0.1);">-</button>';
    $output .= '<button id="zoom-reset" class="tree-control-btn" style="width: 30px; height: 30px; border-radius: 50%; background: white; border: 1px solid #e5e7eb; display: flex; align-items: center; justify-content: center; cursor: pointer; font-size: 16px; box-shadow: 0 1px 2px rgba(0,0,0,0.1);">↺</button>';
    $output .= '</div>';
    $output .= '<div id="tree-tooltip" style="position: absolute; padding: 8px 12px; background: white; border: 1px solid #e5e7eb; border-radius: 6px; box-shadow: 0 2px 8px rgba(0,0,0,0.1); pointer-events: none; opacity: 0; transition: opacity 0.2s;"></div>';
    $output .= '</div>';
    
    // Add the D3.js script and visualization code
    $output .= '<script src="https://cdnjs.cloudflare.com/ajax/libs/d3/7.8.5/d3.min.js"></script>';
    $output .= '<script>
        document.addEventListener("DOMContentLoaded", function() {
            const treeData = ' . $treeDataJson . ';
            initReferralTree(treeData);
        });
        
        function initReferralTree(treeData) {
            const container = document.querySelector(".tree-container");
            const width = container.offsetWidth;
            const height = container.offsetHeight;
            
            // Clear any existing SVG content
            d3.select("#referralTree").html("");
            
            // Create the SVG
            const svg = d3.select("#referralTree")
                .attr("width", width)
                .attr("height", height);
                
            // Create a group that we\'ll apply transforms to
            const g = svg.append("g");
            
            // Define styles inline
            const defs = svg.append("defs");
            defs.html(`
                <style>
                    .tree-line {
                        stroke: #d1d5db;
                        stroke-width: 1.5;
                        fill: none;
                        transition: stroke 0.3s, stroke-width 0.3s;
                    }
                    .tree-line.highlighted {
                        stroke: #6366f1;
                        stroke-width: 2;
                    }
                    .node-bg {
                        fill: white;
                        stroke: #e5e7eb;
                        stroke-width: 1;
                        rx: 8;
                        filter: drop-shadow(0 1px 2px rgba(0, 0, 0, 0.05));
                        transition: stroke 0.3s;
                    }
                    .node-bg-root {
                        fill: #eef2ff;
                        stroke: #c7d2fe;
                    }
                    .node-bg:hover {
                        stroke: #6366f1;
                        stroke-width: 2;
                    }
                    .avatar-bg-root {
                        fill: #4f46e5;
                    }
                    .avatar-bg {
                        fill: #6366f1;
                    }
                    .avatar-text {
                        fill: white;
                        font-family: sans-serif;
                        font-weight: 500;
                        font-size: 14px;
                        text-anchor: middle;
                        dominant-baseline: middle;
                        user-select: none;
                    }
                    .name-text {
                        fill: #1f2937;
                        font-family: sans-serif;
                        font-weight: 500;
                        font-size: 14px;
                        dominant-baseline: middle;
                    }
                    .count-text {
                        fill: #6b7280;
                        font-family: sans-serif;
                        font-size: 12px;
                        dominant-baseline: middle;
                    }
                    .register-text {
                        fill: #4f46e5;
                        font-family: sans-serif;
                        font-size: 10px;
                        text-anchor: middle;
                        cursor: pointer;
                    }
                </style>
            `);
            
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
                .translate(width / 2, 50)
                .scale(1);
            
            svg.call(zoom.transform, initialTransform);
            
            // Create tree layout
            const treeLayout = d3.tree()
                .size([width - 100, height - 100]);
            
            // Create hierarchy from data
            const root = d3.hierarchy(treeData);
            
            // Assign x,y positions to nodes
            treeLayout(root);
            
            // Create links
            const links = g.selectAll(".tree-line")
                .data(root.links())
                .enter()
                .append("path")
                .attr("class", "tree-line")
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
                    // Highlight node
                    d3.select(this).select("rect")
                        .style("stroke", "#6366f1")
                        .style("stroke-width", "2");
                    
                    // Highlight connected links
                    links.classed("highlighted", l => 
                        l.source === d || l.target === d);
                    
                    // Show tooltip
                    const tooltip = d3.select("#tree-tooltip");
                    tooltip.style("opacity", 1)
                        .html(`<strong>${d.data.name}</strong><br>${d.data.referralCount} referrals`)
                        .style("left", (event.pageX - container.getBoundingClientRect().left + 10) + "px")
                        .style("top", (event.pageY - container.getBoundingClientRect().top - 30) + "px");
                })
                .on("mouseout", function() {
                    // Remove node highlight
                    d3.select(this).select("rect")
                        .style("stroke", d => d.depth === 0 ? "#c7d2fe" : "#e5e7eb")
                        .style("stroke-width", "1");
                    
                    // Remove link highlight
                    links.classed("highlighted", false);
                    
                    // Hide tooltip
                    d3.select("#tree-tooltip").style("opacity", 0);
                });
            
            // Add node backgrounds
            nodes.append("rect")
                .attr("class", d => d.depth === 0 ? "node-bg-root" : "node-bg")
                .attr("x", -70)
                .attr("y", -20)
                .attr("width", 140)
                .attr("height", 45);
            
            // Add avatars
            nodes.append("circle")
                .attr("class", d => d.depth === 0 ? "avatar-bg-root" : "avatar-bg")
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
                .text("");
            
            // Setup control buttons
            document.getElementById("zoom-in").addEventListener("click", () => {
                svg.transition()
                    .duration(300)
                    .call(zoom.scaleBy, 1.2);
            });
            
            document.getElementById("zoom-out").addEventListener("click", () => {
                svg.transition()
                    .duration(300)
                    .call(zoom.scaleBy, 0.8);
            });
            
            document.getElementById("zoom-reset").addEventListener("click", () => {
                svg.transition()
                    .duration(300)
                    .call(zoom.transform, initialTransform);
            });
            
            // Handle window resize
            window.addEventListener("resize", () => {
                const newWidth = container.offsetWidth;
                const newHeight = container.offsetHeight;
                
                svg.attr("width", newWidth)
                   .attr("height", newHeight);
                   
                treeLayout.size([newWidth - 100, newHeight - 100]);
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

// Function to display a limited preview of the tree for non-registered users
function previewReferralNode($pdo, $userId) {
    // For non-registered users, just use the same function but limit to 1 level deep
    return displayReferralTreePreview($pdo, $userId);
}
?>
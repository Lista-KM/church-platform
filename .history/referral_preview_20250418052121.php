<?php
include 'includes/db.php';
include 'includes/functions.php';

// Session check - make sure user is logged in
session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

$userId = $_SESSION['user_id'];

// Get user info
$stmt = $pdo->prepare("SELECT name, referral_code FROM users WHERE id = ?");
$stmt->execute([$userId]);
$user = $stmt->fetch();

// Get direct referrals
$stmt = $pdo->prepare("
    SELECT u.id, u.name, u.referral_code, 
           (SELECT COUNT(*) FROM users WHERE referred_by = u.id) as referral_count,
           (SELECT COUNT(*) FROM contributions WHERE user_id = u.id) as contribution_count
    FROM users u
    WHERE u.referred_by = ?
    ORDER BY u.created_at DESC
");
$stmt->execute([$userId]);
$referrals = $stmt->fetchAll();

// Get my referrer
$stmt = $pdo->prepare("
    SELECT u.id, u.name, u.referral_code
    FROM users u
    JOIN users me ON me.referred_by = u.id
    WHERE me.id = ?
");
$stmt->execute([$userId]);
$myReferrer = $stmt->fetch();
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Referral Network</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
    /* Custom CSS for the tree */
    .tree-container {
        display: flex;
        flex-direction: column;
        align-items: center;
        width: 100%;
        overflow-x: auto;
    }

    .tree {
        display: flex;
        flex-direction: column;
        align-items: center;
    }

    .tree-node {
        position: relative;
        display: flex;
        flex-direction: column;
        align-items: center;
        margin-bottom: 20px;
    }

    .tree-node::before {
        content: '';
        position: absolute;
        top: -20px;
        width: 2px;
        height: 20px;
        background-color: #cbd5e0;
    }

    .tree-node:first-child::before {
        display: none;
    }

    .tree-content {
        display: flex;
        align-items: center;
        justify-content: center;
        padding: 10px 15px;
        background-color: white;
        border: 1px solid #e2e8f0;
        border-radius: 8px;
        box-shadow: 0 2px 4px rgba(0, 0, 0, 0.05);
        min-width: 250px;
        z-index: 10;
    }

    .tree-children {
        display: flex;
        flex-direction: column;
        align-items: center;
        position: relative;
        width: 100%;
    }

    .tree-children::before {
        content: '';
        position: absolute;
        top: 0;
        width: 2px;
        height: 20px;
        background-color: #cbd5e0;
    }

    /* Responsive adjustments */
    @media (max-width: 640px) {
        .tree-content {
            min-width: 200px;
        }
    }
    </style>
</head>

<body class="bg-gray-100 min-h-screen">
    <?php include 'includes/header.php'; ?>

    <div class="container mx-auto px-4 py-8">
        <div class="bg-white rounded-lg shadow-md p-6">
            <h1 class="text-2xl font-semibold mb-6">My Referral Network</h1>

            <div class="mb-8">
                <h2 class="text-xl font-medium mb-4">My Referral Code</h2>
                <div class="flex items-center flex-wrap">
                    <span
                        class="bg-indigo-100 text-indigo-800 font-mono text-lg px-4 py-2 rounded mb-2 sm:mb-0"><?php echo htmlspecialchars($user['referral_code']); ?></span>
                    <button id="copy-btn" class="ml-3 text-indigo-600 hover:text-indigo-800 mb-2 sm:mb-0"
                        onclick="copyReferralCode()">Copy</button>
                </div>
                <p class="mt-2 text-gray-600">Share your referral link: <br class="md:hidden"><span
                        class="text-gray-800 break-all"><?php echo htmlspecialchars($_SERVER['HTTP_HOST']); ?>/landing.php?ref=<?php echo htmlspecialchars($user['referral_code']); ?></span>
                </p>

                <button id="copy-link-btn" class="mt-2 px-3 py-1 bg-gray-200 text-gray-800 rounded hover:bg-gray-300"
                    onclick="copyReferralLink()">Copy Link</button>
            </div>

            <?php if ($myReferrer): ?>
            <div class="mb-8 p-4 bg-gray-50 rounded-lg">
                <h3 class="text-lg font-medium mb-2">Referred By</h3>
                <div class="flex items-center">
                    <div class="w-8 h-8 bg-indigo-500 rounded-full flex items-center justify-center text-white mr-3">
                        <?php echo substr($myReferrer['name'], 0, 1); ?>
                    </div>
                    <div>
                        <p class="font-medium"><?php echo htmlspecialchars($myReferrer['name']); ?></p>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <div>
                <h3 class="text-lg font-medium mb-4">My Referrals (<?php echo count($referrals); ?>)</h3>

                <?php if (empty($referrals)): ?>
                <p class="text-gray-600">You haven't referred anyone yet. Share your referral code to get started!</p>
                <?php else: ?>
                <div class="overflow-auto">
                    <table class="min-w-full bg-white">
                        <thead>
                            <tr>
                                <th class="py-2 px-4 border-b text-left">Name</th>
                                <th class="py-2 px-4 border-b text-left">Referrals</th>
                                <th class="py-2 px-4 border-b text-left">Contributions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($referrals as $referral): ?>
                            <tr>
                                <td class="py-2 px-4 border-b">
                                    <div class="flex items-center">
                                        <div
                                            class="w-8 h-8 bg-indigo-500 rounded-full flex items-center justify-center text-white mr-3">
                                            <?php echo substr($referral['name'], 0, 1); ?>
                                        </div>
                                        <?php echo htmlspecialchars($referral['name']); ?>
                                    </div>
                                </td>
                                <td class="py-2 px-4 border-b"><?php echo $referral['referral_count']; ?></td>
                                <td class="py-2 px-4 border-b"><?php echo $referral['contribution_count']; ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php endif; ?>
            </div>

            <div class="mt-8">
                <h3 class="text-lg font-medium mb-4">Complete Referral Tree</h3>
                <div class="tree-container p-4 bg-gray-50 rounded-lg overflow-x-auto">
                    <?php echo buildReferralTree($pdo, $userId); ?>
                </div>
            </div>
        </div>
    </div>

    <script>
    function copyReferralCode() {
        const code = "<?php echo htmlspecialchars($user['referral_code']); ?>";
        navigator.clipboard.writeText(code).then(function() {
            const btn = document.getElementById('copy-btn');
            btn.textContent = "Copied!";
            setTimeout(() => {
                btn.textContent = "Copy";
            }, 2000);
        });
    }

    function copyReferralLink() {
        const link =
            "<?php echo htmlspecialchars($_SERVER['HTTP_HOST']); ?>/landing.php?ref=<?php echo htmlspecialchars($user['referral_code']); ?>";
        navigator.clipboard.writeText(link).then(function() {
            const btn = document.getElementById('copy-link-btn');
            btn.textContent = "Copied!";
            setTimeout(() => {
                btn.textContent = "Copy Link";
            }, 2000);
        });
    }
    </script>
</body>

</html>

<?php
function buildReferralTree($pdo, $userId) {
    // Get user info
    $stmt = $pdo->prepare("SELECT name FROM users WHERE id = ?");
    $stmt->execute([$userId]);
    $user = $stmt->fetch();
    
    if (!$user) return '';
    
    // Get direct referrals
    $stmt = $pdo->prepare("SELECT id FROM users WHERE referred_by = ?");
    $stmt->execute([$userId]);
    $referrals = $stmt->fetchAll();
    $referralCount = count($referrals);
    
    $output = '<div class="tree">';
    $output .= '<div class="tree-node">';
    $output .= '<div class="tree-content bg-indigo-50 border border-indigo-200">';
    $output .= '<div class="w-8 h-8 bg-indigo-600 rounded-full flex items-center justify-center text-white mr-3">' . substr($user['name'], 0, 1) . '</div>';
    $output .= '<div>';
    $output .= '<span class="font-medium">' . htmlspecialchars($user['name']) . '</span>';
    $output .= '<span class="ml-2 text-gray-500 text-sm">(' . $referralCount . ' referrals)</span>';
    $output .= '</div>';
    $output .= '</div>';
    
    if ($referralCount > 0) {
        $output .= '<div class="tree-children">';
        foreach ($referrals as $referral) {
            $output .= buildReferralSubtree($pdo, $referral['id']);
        }
        $output .= '</div>';
    }
    
    $output .= '</div>'; // Close tree-node
    $output .= '</div>'; // Close tree
    
    return $output;
}

function buildReferralSubtree($pdo, $userId) {
    // Get user info
    $stmt = $pdo->prepare("SELECT name FROM users WHERE id = ?");
    $stmt->execute([$userId]);
    $user = $stmt->fetch();
    
    if (!$user) return '';
    
    // Get direct referrals
    $stmt = $pdo->prepare("SELECT id FROM users WHERE referred_by = ?");
    $stmt->execute([$userId]);
    $referrals = $stmt->fetchAll();
    $referralCount = count($referrals);
    
    $output = '<div class="tree-node">';
    $output .= '<div class="tree-content">';
    $output .= '<div class="w-8 h-8 bg-indigo-500 rounded-full flex items-center justify-center text-white mr-3">' . substr($user['name'], 0, 1) . '</div>';
    $output .= '<div>';
    $output .= '<span class="font-medium">' . htmlspecialchars($user['name']) . '</span>';
    $output .= '<span class="ml-2 text-gray-500 text-sm">(' . $referralCount . ' referrals)</span>';
    $output .= '</div>';
    $output .= '</div>';
    
    if ($referralCount > 0) {
        $output .= '<div class="tree-children">';
        foreach ($referrals as $referral) {
            $output .= buildReferralSubtree($pdo, $referral['id']);
        }
        $output .= '</div>';
    }
    
    $output .= '</div>'; // Close tree-node
    
    return $output;
}
?>
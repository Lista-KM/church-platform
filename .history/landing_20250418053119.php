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
// Function to display referral tree preview
function displayReferralTreePreview($pdo, $userId, $level = 0) {
    $output = '';
    $indent = str_repeat('    ', $level);
    
    // Get user info
    $stmt = $pdo->prepare("SELECT name FROM users WHERE id = ?");
    $stmt->execute([$userId]);
    $user = $stmt->fetch();
    
    if (!$user) return $output;
    
    // Get direct referrals
    $stmt = $pdo->prepare("SELECT id FROM users WHERE referred_by = ?");
    $stmt->execute([$userId]);
    $referrals = $stmt->fetchAll();
    $referralCount = count($referrals);
    
    // Create tree node
    $output .= '<div class="ml-' . ($level * 4) . ' mb-2">';
    $output .= '<div class="flex items-center">';
    $output .= '<div class="mr-2 w-6 h-6 bg-indigo-500 rounded-full flex items-center justify-center text-white">' . substr($user['name'], 0, 1) . '</div>';
    $output .= '<span class="font-medium">' . htmlspecialchars($user['name']) . '</span>';
    $output .= '<span class="ml-2 text-gray-500 text-sm">(' . $referralCount . ' referrals)</span>';
    $output .= '</div>';
    
    // Add children (up to 5 levels deep to avoid performance issues)
    if ($level < 4 && $referralCount > 0) {
        $output .= '<div class="ml-8 pl-4 border-l border-gray-300">';
        foreach ($referrals as $referral) {
            $output .= displayReferralTreePreview($pdo, $referral['id'], $level + 1);
        }
        $output .= '</div>';
    }
    
    $output .= '</div>';
    
    return $output;
}
?>
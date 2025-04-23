<?php 
include 'includes/db.php';
include 'includes/functions.php';

// Pre-fill referral from URL if available
$referralCode = $_GET['ref'] ?? ($_POST['referral'] ?? null);
$referralId = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = sanitize($_POST['name']);
    $email = sanitize($_POST['email']);
    $password = password_hash($_POST['password'], PASSWORD_DEFAULT);
    $referralCode = sanitize($_POST['referral'] ?? null);

    // Lookup referrer by referral code
    if ($referralCode) {
        $refCheck = $pdo->prepare("SELECT id FROM users WHERE referral_code = ?");
        $refCheck->execute([$referralCode]);
        $referralId = $refCheck->fetchColumn(); // Will be null if not found
    }

    // Register new user
    $stmt = $pdo->prepare("INSERT INTO users (name, email, password, referred_by) VALUES (?, ?, ?, ?)");
    $stmt->execute([$name, $email, $password, $referralId]);

    // Generate referral code for this new user
    $lastInsertId = $pdo->lastInsertId();
    $generatedCode = strtoupper(substr(md5($name . $email . $lastInsertId), 0, 8));
    $update = $pdo->prepare("UPDATE users SET referral_code = ? WHERE id = ?");
    $update->execute([$generatedCode, $lastInsertId]);

    header("Location: login.php?registered=true");
    exit();
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Register</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>

<body class="bg-gray-100 min-h-screen flex justify-center items-center">
    <div class="w-full max-w-md bg-white p-8 rounded-lg shadow-md">
        <h2 class="text-3xl font-semibold text-center mb-8 text-gray-800">Create Your Account</h2>

        <form method="POST">
            <div class="mb-4">
                <label for="name" class="block text-sm font-medium text-gray-600">Full Name</label>
                <input type="text" name="name" id="name" placeholder="Your full name" required
                    class="mt-1 block w-full px-4 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500">
            </div>

            <div class="mb-4">
                <label for="email" class="block text-sm font-medium text-gray-600">Email</label>
                <input type="email" name="email" id="email" placeholder="Your email address" required
                    class="mt-1 block w-full px-4 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500">
            </div>

            <div class="mb-4">
                <label for="password" class="block text-sm font-medium text-gray-600">Password</label>
                <input type="password" name="password" id="password" placeholder="Create a password" required
                    class="mt-1 block w-full px-4 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500">
            </div>

            <div class="mb-6">
                <label for="referral" class="block text-sm font-medium text-gray-600">Referral Code (Optional)</label>
                <input type="text" name="referral" id="referral" placeholder="Referral Code"
                    class="mt-1 block w-full px-4 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500"
                    value="<?php echo htmlspecialchars($referralCode ?? ''); ?>">
                <!-- Optional: Add 'readonly' if you don't want users to change it -->
                <!-- readonly -->
            </div>

            <button type="submit"
                class="w-full py-2 px-4 bg-indigo-600 text-white font-semibold rounded-lg hover:bg-indigo-700 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-opacity-50">
                Register
            </button>

            <p class="mt-4 text-center text-sm text-gray-600">
                Already have an account? <a href="login.php" class="text-indigo-600 hover:text-indigo-700">Login
                    here</a>
            </p>
        </form>
    </div>
</body>

</html>
<?php
include 'includes/db.php';
include 'includes/functions.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = sanitize($_POST['email']);
    $password = $_POST['password'];

    $stmt = $pdo->prepare("SELECT * FROM users WHERE email = ?");
    $stmt->execute([$email]);
    $user = $stmt->fetch();

    if ($user && password_verify($password, $user['password'])) {
        session_start();
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['is_admin'] = $user['is_admin']; // Store user role
        // Redirect based on role
        if ($user['is_admin'] == 1) {
            header("Location: admin/dashboard.php"); // Admin dashboard
        } else {
            header("Location: user_dashboard.php"); // Regular user dashboard
        }
        exit();
    } else {
        $error = "Invalid credentials.";
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Login</title>
  <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gray-100 min-h-screen flex justify-center items-center">
  <div class="w-full max-w-md bg-white p-8 rounded-lg shadow-md">
    <h2 class="text-3xl font-semibold text-center mb-8 text-gray-800">Login to Your Account</h2>

    <?php if (isset($error)): ?>
        <div class="bg-red-100 text-red-700 border-l-4 border-red-500 p-4 mb-4">
            <p><strong>Error:</strong> <?php echo $error; ?></p>
        </div>
    <?php endif; ?>

    <form method="POST">
      <div class="mb-4">
        <label for="email" class="block text-sm font-medium text-gray-600">Email</label>
        <input type="email" name="email" id="email" placeholder="Your email address" required class="mt-1 block w-full px-4 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500">
      </div>

      <div class="mb-6">
        <label for="password" class="block text-sm font-medium text-gray-600">Password</label>
        <input type="password" name="password" id="password" placeholder="Your password" required class="mt-1 block w-full px-4 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500">
      </div>

      <button type="submit" class="w-full py-2 px-4 bg-indigo-600 text-white font-semibold rounded-lg hover:bg-indigo-700 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-opacity-50">
        Login
      </button>

      <p class="mt-4 text-center text-sm text-gray-600">
        Don't have an account? <a href="register.php" class="text-indigo-600 hover:text-indigo-700">Register here</a>
      </p>
    </form>
  </div>
</body>
</html>

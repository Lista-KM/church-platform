<?php
include '../includes/auth.php';
if (!$_SESSION['is_admin']) {
    header("Location: ../dashboard.php");
    exit();
}
?>
<!DOCTYPE html>
<html>
<head>
  <title>Admin Panel</title>
  <script src="https://cdn.tailwindcss.com"></script>
  <link rel="stylesheet" href="assets/style.css">

</head>
<body class="p-6">
  <h1 class="text-2xl font-bold mb-4">Admin Dashboard</h1>
  <a href="users.php" class="btn">Manage Users</a>
  <a href="contributions.php" class="btn ml-2">View Contributions</a>
</body>
</html>

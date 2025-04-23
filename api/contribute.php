<?php
include '../includes/auth.php';
include '../includes/db.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $amount = (float) $_POST['amount'];
    $stmt = $pdo->prepare("INSERT INTO contributions (user_id, amount, contributed_at) VALUES (?, ?, NOW())");
    $stmt->execute([$_SESSION['user_id'], $amount]);
    header("Location: ../dashboard.php?success=1");
    exit();
}
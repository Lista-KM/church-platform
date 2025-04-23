<?php
function sanitize($input) {
    return htmlspecialchars(trim($input), ENT_QUOTES, 'UTF-8');
}
/**
 * Generate a unique referral code
 * @param string $name User's name
 * @param string $email User's email
 * @param int $id User's ID
 * @return string Referral code
 */
function generateReferralCode($name, $email, $id) {
    return strtoupper(substr(md5($name . $email . $id), 0, 8));
}

/**
 * Check if email exists in the database
 * @param PDO $pdo Database connection
 * @param string $email Email to check
 * @return bool True if email exists, false otherwise
 */
function emailExists($pdo, $email) {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE email = ?");
    $stmt->execute([$email]);
    return ($stmt->fetchColumn() > 0);
}
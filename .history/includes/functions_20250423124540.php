<?php
function sanitize($input) {
    return htmlspecialchars(trim($input), ENT_QUOTES, 'UTF-8');
}
/**
 * @param string $name 
 * @param string $email 
 * @param int $id 
 * @return string 
 */
function generateReferralCode($name, $email, $id) {
    return strtoupper(substr(md5($name . $email . $id), 0, 8));
}

/**
 * @param PDO $pdo 
 * @param string $email 
 * @return bool 
 */
function emailExists($pdo, $email) {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE email = ?");
    $stmt->execute([$email]);
    return ($stmt->fetchColumn() > 0);
}
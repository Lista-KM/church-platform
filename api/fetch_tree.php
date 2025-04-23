<?php
include '../includes/auth.php';
include '../includes/db.php';

function getTree($pdo, $id) {
    $stmt = $pdo->prepare("SELECT id, name FROM users WHERE referred_by = ?");
    $stmt->execute([$id]);
    $children = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $tree = [];
    foreach ($children as $child) {
        $tree[] = [
            'name' => $child['name'],
            'children' => getTree($pdo, $child['id'])
        ];
    }
    return $tree;
}

$stmt = $pdo->prepare("SELECT name FROM users WHERE id = ?");
$stmt->execute([$_SESSION['user_id']]);
$name = $stmt->fetchColumn();

$tree = [
    'name' => $name,
    'children' => getTree($pdo, $_SESSION['user_id'])
];

echo json_encode($tree);
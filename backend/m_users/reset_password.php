<?php
require_once dirname(__DIR__, 1) . '/common/db_manager.php';

// CORS
header('Access-Control-Allow-Origin: ' . ORIGIN);
header('Access-Control-Allow-Headers: Content-Type');
header('Access-Control-Allow-Methods: POST, OPTIONS');

// ★ プリフライト対応
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

try {

    // JSON取得
    $json = file_get_contents("php://input");
    $_POST = json_decode($json, true);

    if (!isset($_POST['id'])) {
        http_response_code(400);
        echo json_encode(["error" => "id required"]);
        exit;
    }

    $dbh = getDb();

    // insert.php と同じ初期パスワード
    $defaultPasswordHash = password_hash('password', PASSWORD_DEFAULT);

    $sql = "UPDATE m_users
            SET password = :password,
                updated_at = NOW()
            WHERE id = :id";

    $stmt = $dbh->prepare($sql);
    $stmt->bindValue(':password', $defaultPasswordHash);
    $stmt->bindValue(':id', (int)$_POST['id'], PDO::PARAM_INT);

    $stmt->execute();

    echo json_encode(["success" => true]);

} catch (PDOException $e) {

    error_log('[m_users/reset_password] PDOException: ' . $e->getMessage());

} finally {
    $dbh = null;
}
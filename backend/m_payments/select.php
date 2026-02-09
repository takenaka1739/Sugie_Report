<?php
// 共通CORS（credentials対応・プリフライト早期終了）
require_once dirname(__DIR__, 1) . '/common/cors.php';

// DB
require_once dirname(__DIR__, 1) . '/common/db_manager.php';

try {
    $dbh = getDb();

    // 支払方法マスタ取得
    $sql = "SELECT id, name FROM m_payments ORDER BY id ASC;";
    $stmt = $dbh->prepare($sql);
    $stmt->execute();
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // JSONレスポンス
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($rows, JSON_UNESCAPED_UNICODE);
} catch (PDOException $e) {
    // エラー時のJSON
    header('Content-Type: application/json; charset=utf-8');
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Database error',
        'error'   => $e->getMessage(),
    ], JSON_UNESCAPED_UNICODE);
} finally {
    $dbh = null;
}

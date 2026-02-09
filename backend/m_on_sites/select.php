<?php
// 共通CORS（credentials対応・OPTIONS早期終了）
require_once dirname(__DIR__, 1) . '/common/cors.php';

// DB
require_once dirname(__DIR__, 1) . '/common/db_manager.php';

try {
    $dbh = getDb();

    /**
     * 現場一覧取得
     * - id, name に加えて type_id を必ず返す（NULL は 1=自社 に置換）
     * - 返却は数値/文字列を整形して安定化
     */
    $sql = "
        SELECT
            id,
            name,
            COALESCE(type_id, 1) AS type_id
        FROM m_on_sites
        ORDER BY id ASC
    ";
    $stmt = $dbh->prepare($sql);
    $stmt->execute();

    $rows = [];
    while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $rows[] = [
            'id'      => (int)$r['id'],
            'name'    => (string)$r['name'],
            'type_id' => (int)$r['type_id'],
        ];
    }

    // JSONレスポンス
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($rows, JSON_UNESCAPED_UNICODE);

} catch (PDOException $e) {
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

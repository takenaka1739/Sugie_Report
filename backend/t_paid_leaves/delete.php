<?php
/**
 * t_paid_leaves の削除API
 */
require_once dirname(__DIR__, 1) . '/common/db_manager.php';

try {
    // ===== CORS =====
    $reqOrigin = $_SERVER['HTTP_ORIGIN'] ?? '';
    $ALLOWED = [
        'http://localhost:3000',
        'http://127.0.0.1:3000',
        'http://localhost:5173',
        'http://127.0.0.1:5173',
        'http://localhost',
        'http://127.0.0.1',
    ];
    if ($reqOrigin && in_array($reqOrigin, $ALLOWED, true)) {
        header('Access-Control-Allow-Origin: ' . $reqOrigin);
    } else {
        header('Access-Control-Allow-Origin: http://localhost');
    }
    header('Access-Control-Allow-Credentials: true');
    header('Access-Control-Allow-Methods: POST, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, Cache-Control, Pragma');
    header('Access-Control-Max-Age: 600');
    header('Vary: Origin');
    header('Content-Type: application/json; charset=utf-8');

    if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
        http_response_code(204);
        exit;
    }
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['success' => false, 'message' => 'Method Not Allowed (POSTのみ)'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // 入力
    $raw  = file_get_contents('php://input');
    $data = json_decode($raw, true);
    if (!is_array($data)) {
        $data = $_POST; // form-data フォールバック
    }

    $id        = isset($data['id']) ? (int)$data['id'] : 0;
    $userId    = isset($data['user_id']) ? (int)$data['user_id'] : 0;
    $leaveDate = isset($data['leave_date']) ? trim((string)$data['leave_date']) : '';

    $useId = ($id > 0);
    $useUserDate = (!$useId && $userId > 0 && $leaveDate !== '');

    if (!$useId && !$useUserDate) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => 'id もしくは (user_id と leave_date) を指定してください'
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($useUserDate) {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $leaveDate)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'leave_date は YYYY-MM-DD 形式で指定してください'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        $dt = DateTime::createFromFormat('Y-m-d', $leaveDate);
        $e  = DateTime::getLastErrors();
        if (!$dt || $e['warning_count'] > 0 || $e['error_count'] > 0) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'leave_date が不正な日付です'], JSON_UNESCAPED_UNICODE);
            exit;
        }
    }

    $dbh = getDb();

    // --- 削除実行（m_users は更新しない） ---
    if ($useId) {
        $stmt = $dbh->prepare('DELETE FROM t_paid_leaves WHERE id = :id');
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);
    } else {
        $stmt = $dbh->prepare('DELETE FROM t_paid_leaves WHERE user_id = :uid AND leave_date = :ld');
        $stmt->bindValue(':uid', $userId, PDO::PARAM_INT);
        $stmt->bindValue(':ld', $leaveDate, PDO::PARAM_STR);
    }
    $stmt->execute();
    $deleted = $stmt->rowCount();

    if ($deleted < 1) {
        http_response_code(404);
        echo json_encode([
            'success' => false,
            'message' => '削除対象が見つかりません'
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    echo json_encode([
        'success' => true,
        'message' => '削除しました',
        'deleted' => $deleted
    ], JSON_UNESCAPED_UNICODE);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'DBエラー: ' . $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}
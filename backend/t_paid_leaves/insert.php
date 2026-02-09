<?php
/**
 * t_paid_leaves への登録API
 * - 年度上限: 12日
 * - 過去日は申請不可
 * - 登録成功時: m_users.paid_holidays_num を 1 減らす
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
        $data = $_POST; // form-data も許可
    }
    $userId    = isset($data['user_id']) ? (int)$data['user_id'] : 0;
    $leaveDate = isset($data['leave_date']) ? trim((string)$data['leave_date']) : '';

    // バリデーション
    if ($userId <= 0) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'user_id は必須です（数値）'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $leaveDate)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'leave_date は YYYY-MM-DD 形式で指定してください'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $dt = DateTime::createFromFormat('Y-m-d', $leaveDate);
    $dtErrors = DateTime::getLastErrors();
    if (!$dt || $dtErrors['warning_count'] > 0 || $dtErrors['error_count'] > 0) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'leave_date が不正な日付です'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // 過去日は不可
    $today = new DateTime('today');
    if ($dt < $today) {
        http_response_code(409);
        echo json_encode(['success' => false, 'message' => '過去日は申請できません'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $dbh = getDb();

    // 年度判定
    $GRANTED = 12;
    $y = (int)$dt->format('Y');
    $m = (int)$dt->format('n');
    if ($m < 4) {
        $fyStart = sprintf('%04d-04-01', $y - 1);
        $fyEnd   = sprintf('%04d-03-31', $y);
    } else {
        $fyStart = sprintf('%04d-04-01', $y);
        $fyEnd   = sprintf('%04d-03-31', $y + 1);
    }

    // 当年度の使用数チェック
    $cntStmt = $dbh->prepare("
        SELECT COUNT(*) FROM t_paid_leaves
        WHERE user_id = :uid AND leave_date BETWEEN :from AND :to
    ");
    $cntStmt->execute([':uid' => $userId, ':from' => $fyStart, ':to' => $fyEnd]);
    $used = (int)$cntStmt->fetchColumn();

    if ($used >= $GRANTED) {
        http_response_code(409);
        echo json_encode(['success' => false, 'message' => '年度の上限(12日)に達しています'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // INSERT（m_users は更新しない）
    $sql = "INSERT INTO t_paid_leaves (user_id, leave_date, created_at, updated_at)
            VALUES (:user_id, :leave_date, NOW(), NOW())";
    $stmt = $dbh->prepare($sql);
    $stmt->execute([':user_id' => $userId, ':leave_date' => $leaveDate]);
    $id = (int)$dbh->lastInsertId();

    // 返却データ
    $select = $dbh->prepare("
        SELECT id, user_id, leave_date, created_at, updated_at
        FROM t_paid_leaves
        WHERE id = :id
    ");
    $select->execute([':id' => $id]);
    $row = $select->fetch(PDO::FETCH_ASSOC);

    echo json_encode(['success' => true, 'message' => '登録しました', 'data' => $row], JSON_UNESCAPED_UNICODE);

} catch (PDOException $e) {
    // トランザクションは使っていないが、仮に開始していても安全にロールバック
    if (isset($dbh) && $dbh instanceof PDO && $dbh->inTransaction()) {
        $dbh->rollBack();
    }
    // ユニーク制約(user_id, leave_date)違反
    if ($e->getCode() === '23000') {
        http_response_code(409);
        echo json_encode(['success' => false, 'message' => '同一ユーザーの同一日は既に登録されています'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'DBエラー: ' . $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
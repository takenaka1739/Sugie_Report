<?php
/**
 * t_paid_leaves の更新API
 * 期待する入力(JSON / x-www-form-urlencoded):
 *   {
 *     "id": 123,                  // 必須: 更新対象レコードのID
 *     "user_id": 5,               // 任意: 変更後のユーザーID（管理者のみ想定）
 *     "leave_date": "2025-10-02"  // 任意: 変更後の有給取得日 (YYYY-MM-DD)
 *   }
 * ※ "user_id" か "leave_date" のどちらかは最低1つ必須（両方可）
 *
 * エラーレスポンス:
 *   400: 入力不正
 *   404: 対象なし
 *   409: (user_id, leave_date) のユニーク制約違反
 *   500: DBエラー
 */
require_once dirname(__DIR__, 1) . '/common/db_manager.php';

try
{
    // CORS
    header('Access-Control-Allow-Origin: ' . ORIGIN);
    header('Access-Control-Allow-Headers: Content-Type');
    header('Access-Control-Allow-Methods: POST, OPTIONS');
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

    // 入力取得
    $raw = file_get_contents('php://input');
    $data = json_decode($raw, true);
    if (!is_array($data)) {
        $data = $_POST; // form-data対応
    }

    $id        = isset($data['id']) ? (int)$data['id'] : 0;
    $userIdNew = isset($data['user_id']) ? (int)$data['user_id'] : null;
    $dateNew   = isset($data['leave_date']) ? trim($data['leave_date']) : null;

    // バリデーション
    if ($id <= 0) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'id は必須です（数値）'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if (is_null($userIdNew) && is_null($dateNew)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'user_id か leave_date のどちらか1つ以上を指定してください'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if (!is_null($userIdNew) && $userIdNew <= 0) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'user_id が不正です'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if (!is_null($dateNew)) {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateNew)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'leave_date は YYYY-MM-DD 形式で指定してください'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        $dt = DateTime::createFromFormat('Y-m-d', $dateNew);
        $e  = DateTime::getLastErrors();
        if (!$dt || $e['warning_count'] > 0 || $e['error_count'] > 0) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'leave_date が不正な日付です'], JSON_UNESCAPED_UNICODE);
            exit;
        }
    }

    $dbh = getDb();

    // 存在確認
    $check = $dbh->prepare('SELECT id, user_id, leave_date FROM t_paid_leaves WHERE id = :id');
    $check->bindValue(':id', $id, PDO::PARAM_INT);
    $check->execute();
    $current = $check->fetch(PDO::FETCH_ASSOC);
    if (!$current) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => '指定されたIDのレコードが見つかりません'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // 動的UPDATE
    $sets = [];
    $params = [':id' => $id];

    if (!is_null($userIdNew)) {
        $sets[] = 'user_id = :user_id';
        $params[':user_id'] = $userIdNew;
    }
    if (!is_null($dateNew)) {
        $sets[] = 'leave_date = :leave_date';
        $params[':leave_date'] = $dateNew;
    }
    // 何かしらセットがある前提
    $sets[] = 'updated_at = NOW()';
    $setSql = implode(', ', $sets);

    $sql = "UPDATE t_paid_leaves SET {$setSql} WHERE id = :id";
    $stmt = $dbh->prepare($sql);
    foreach ($params as $k => $v) {
        if ($k === ':id' || $k === ':user_id') {
            $type = PDO::PARAM_INT;
        } else {
            $type = PDO::PARAM_STR;
        }
        $stmt->bindValue($k, $v, $type);
    }

    try {
        $stmt->execute();
    } catch (PDOException $e) {
        // ユニーク制約 (user_id, leave_date) 衝突 → 409
        if ((int)$e->getCode() === 23000) {
            http_response_code(409);
            echo json_encode([
                'success' => false,
                'message' => '同一ユーザーの同一日は既に登録されています'
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }
        throw $e;
    }

    // 変更後データを返却
    $sel = $dbh->prepare('SELECT id, user_id, leave_date, created_at, updated_at FROM t_paid_leaves WHERE id = :id');
    $sel->bindValue(':id', $id, PDO::PARAM_INT);
    $sel->execute();
    $row = $sel->fetch(PDO::FETCH_ASSOC);

    echo json_encode([
        'success' => true,
        'message' => '更新しました',
        'data'    => $row
    ], JSON_UNESCAPED_UNICODE);
}
catch (PDOException $e)
{
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'DBエラー: ' . $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}

<?php
// CORS（credentials対応・プリフライト早期終了）
require_once dirname(__DIR__, 1) . '/common/cors.php';
// DB
require_once dirname(__DIR__, 1) . '/common/db_manager.php';

/**
 * POST /backend/t_work_reports/delete.php
 * いずれかの識別子が必須：
 *   - id
 *   - user_id + work_date(YYYY-MM-DD)
 *
 * 例1) JSON:
 * { "id": 123 }
 *
 * 例2) JSON:
 * { "user_id": 2, "work_date": "2025-10-05" }
 *
 * 応答:
 * { "success": true, "affected": 1 }
 */

function get_json_or_post() {
    $ct = $_SERVER['CONTENT_TYPE'] ?? $_SERVER['HTTP_CONTENT_TYPE'] ?? '';
    if (stripos($ct, 'application/json') !== false) {
        $raw = file_get_contents('php://input');
        $j = json_decode($raw, true);
        return is_array($j) ? $j : [];
    }
    return $_POST ?? [];
}
function norm_int_or_null($v) {
    if ($v === '' || $v === null || !isset($v)) return null;
    if (is_numeric($v)) return (int)$v;
    return null;
}
function norm_date($s) {
    if (!isset($s) || $s === '') return null;
    return preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$s) ? (string)$s : null;
}

try {
    $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
    if ($method === 'OPTIONS') { http_response_code(204); exit; }
    if ($method !== 'POST') {
        http_response_code(405);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success'=>false,'message'=>'Method Not Allowed (POSTのみ)'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $dbh = getDb();
    $in = get_json_or_post();

    // 識別子
    $id        = norm_int_or_null($in['id'] ?? null);
    $user_id   = norm_int_or_null($in['user_id'] ?? null);
    $work_date = norm_date($in['work_date'] ?? null);

    if (!$id && (!($user_id && $work_date))) {
        http_response_code(400);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success'=>false,'message'=>'id または (user_id, work_date) の指定が必要です'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // WHERE を決定
    $where = '';
    $params = [];
    if ($id) {
        $where = 'id = :_id';
        $params[':_id'] = (int)$id;
    } else {
        $where = 'user_id = :_uid AND work_date = :_date';
        $params[':_uid']  = (int)$user_id;
        $params[':_date'] = $work_date;
    }

    $sql = "DELETE FROM t_work_reports WHERE {$where} LIMIT 1";
    $stmt = $dbh->prepare($sql);
    foreach ($params as $k => $v) {
        if (is_int($v)) {
            $stmt->bindValue($k, $v, PDO::PARAM_INT);
        } else {
            $stmt->bindValue($k, $v, PDO::PARAM_STR);
        }
    }
    $stmt->execute();
    $affected = $stmt->rowCount();

    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'success'  => true,
        'affected' => $affected, // 0 or 1
    ], JSON_UNESCAPED_UNICODE);

} catch (PDOException $e) {
    header('Content-Type: application/json; charset=utf-8');
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'DBエラー: ' . $e->getMessage(),
    ], JSON_UNESCAPED_UNICODE);
}

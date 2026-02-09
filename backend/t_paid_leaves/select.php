<?php
require_once dirname(__DIR__, 1) . '/common/cors.php';

// DB
require_once dirname(__DIR__, 1) . '/common/db_manager.php';

/**
 * t_paid_leaves の一覧取得API
 * クエリパラメータ（任意）:
 *   - user_id: int … ユーザーIDで絞り込み
 *   - date_from: YYYY-MM-DD … 取得日(leave_date)の下限
 *   - date_to:   YYYY-MM-DD … 取得日(leave_date)の上限
 *   - limit: int (デフォルト100) … 上限件数
 *   - offset: int (デフォルト0) … オフセット
 *   - order: string … 並び順
 *       'leave_date_asc' | 'leave_date_desc' (default) | 'created_asc' | 'created_desc'
 */
try {
    header('Content-Type: application/json; charset=utf-8');

    // 許可メソッド
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    if ($method === 'OPTIONS') {
        // cors.phpで204終了済み（多重防御）
        http_response_code(204);
        exit;
    }
    if ($method !== 'GET') {
        http_response_code(405);
        echo json_encode(['success' => false, 'message' => 'Method Not Allowed (GETのみ)'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $dbh = getDb();

    // ---- 入力の取得/バリデーション ----
    $userId   = isset($_GET['user_id'])   ? (int)$_GET['user_id']   : null;
    $dateFrom = isset($_GET['date_from']) ? trim((string)$_GET['date_from']) : null;
    $dateTo   = isset($_GET['date_to'])   ? trim((string)$_GET['date_to'])   : null;

    $limit  = isset($_GET['limit'])  ? max(1, (int)$_GET['limit'])  : 100;
    $offset = isset($_GET['offset']) ? max(0, (int)$_GET['offset']) : 0;

    $order  = isset($_GET['order']) ? (string)$_GET['order'] : 'leave_date_desc';
    switch ($order) {
        case 'leave_date_asc':
            $orderBy = 'leave_date ASC, id ASC';
            break;
        case 'created_asc':
            $orderBy = 'created_at ASC, id ASC';
            break;
        case 'created_desc':
            $orderBy = 'created_at DESC, id DESC';
            break;
        case 'leave_date_desc':
        default:
            $orderBy = 'leave_date DESC, id DESC';
            break;
    }

    // 簡易日付チェック（YYYY-MM-DD）
    $datePattern = '/^\d{4}-\d{2}-\d{2}$/';
    if ($dateFrom !== null && !preg_match($datePattern, $dateFrom)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'date_fromの形式が不正です (YYYY-MM-DD)'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if ($dateTo !== null && !preg_match($datePattern, $dateTo)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'date_toの形式が不正です (YYYY-MM-DD)'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // ---- 動的WHERE句の組み立て ----
    $where  = [];
    $params = [];

    if (!is_null($userId) && $userId > 0) {
        $where[] = 'user_id = :user_id';
        $params[':user_id'] = $userId;
    }
    if (!is_null($dateFrom)) {
        $where[] = 'leave_date >= :date_from';
        $params[':date_from'] = $dateFrom;
    }
    if (!is_null($dateTo)) {
        $where[] = 'leave_date <= :date_to';
        $params[':date_to'] = $dateTo;
    }

    $whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

    // ---- 件数取得 ----
    $countSql = "SELECT COUNT(*) AS cnt FROM t_paid_leaves {$whereSql}";
    $stmt = $dbh->prepare($countSql);
    foreach ($params as $k => $v) {
        $stmt->bindValue($k, $v);
    }
    $stmt->execute();
    $total = (int)$stmt->fetchColumn();

    // ---- データ取得 ----
    $sql = "
        SELECT
            id,
            user_id,
            leave_date,
            created_at,
            updated_at
        FROM t_paid_leaves
        {$whereSql}
        ORDER BY {$orderBy}
        LIMIT :limit OFFSET :offset
    ";
    $stmt = $dbh->prepare($sql);
    foreach ($params as $k => $v) {
        $stmt->bindValue($k, $v);
    }
    $stmt->bindValue(':limit',  $limit,  PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();

    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'success' => true,
        'total'   => $total,
        'count'   => count($rows),
        'data'    => $rows,
    ], JSON_UNESCAPED_UNICODE);

} catch (PDOException $e) {
    header('Content-Type: application/json; charset=utf-8');
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'DBエラー: ' . $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}

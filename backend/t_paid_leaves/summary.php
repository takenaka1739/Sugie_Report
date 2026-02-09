<?php
/**
 * t_paid_leaves 年度サマリーAPI（4/1〜翌3/31）
 * 機能:
 *  - 指定ユーザーの該当年度(4/1〜翌3/31)の使用数/残数を返す
 *  - 繰越・買取なし、付与は固定 12 日
 *
 * クエリ:
 *  GET /backend/t_paid_leaves/summary.php?user_id=123&base_date=2025-09-26
 *    - user_id     : 必須
 *    - base_date   : 任意（省略時=今日）。この日が属する「4/1〜翌3/31」を年度として集計
 *
 * レスポンス:
 *  {
 *    "success": true,
 *    "user_id": 123,
 *    "granted": 12,
 *    "used": 3,
 *    "remaining": 9,
 *    "fy_start": "2025-04-01",
 *    "fy_end":   "2026-03-31"
 *  }
 */
require_once dirname(__DIR__, 1) . '/common/db_manager.php';

try
{
    // ===== CORS: 動的Origin許可 =====
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
    header('Access-Control-Allow-Methods: GET, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, Cache-Control, Pragma');
    header('Access-Control-Max-Age: 600');
    header('Vary: Origin');
    header('Content-Type: application/json; charset=utf-8');

    if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
        http_response_code(204);
        exit;
    }
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        http_response_code(405);
        echo json_encode(['success' => false, 'message' => 'Method Not Allowed (GETのみ)'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $userId = isset($_GET['user_id']) ? (int)$_GET['user_id'] : 0;
    $base   = isset($_GET['base_date']) ? trim((string)$_GET['base_date']) : date('Y-m-d');

    if ($userId <= 0) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'user_id は必須です（数値）'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    // 日付バリデーション
    $re = '/^\d{4}-\d{2}-\d{2}$/';
    if (!preg_match($re, $base)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'base_date は YYYY-MM-DD 形式で指定してください'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $baseDt = DateTime::createFromFormat('Y-m-d', $base);
    $err = DateTime::getLastErrors();
    if (!$baseDt || $err['warning_count'] > 0 || $err['error_count'] > 0) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'base_date が不正な日付です'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // === 年度（4/1〜翌3/31）を算出 ===
    $y = (int)$baseDt->format('Y');
    $m = (int)$baseDt->format('n');
    if ($m < 4) {
        // 1〜3月は前年度の4/1開始
        $fyStart = new DateTime(($y - 1) . '-04-01');
        $fyEnd   = new DateTime($y . '-03-31');
    } else {
        // 4〜12月は当年の4/1開始
        $fyStart = new DateTime($y . '-04-01');
        $fyEnd   = new DateTime(($y + 1) . '-03-31');
    }
    $fyStartStr = $fyStart->format('Y-m-d');
    $fyEndStr   = $fyEnd->format('Y-m-d');

    // === 集計 ===
    $dbh = getDb();
    $stmt = $dbh->prepare("
        SELECT COUNT(*) AS cnt
        FROM t_paid_leaves
        WHERE user_id = :uid
          AND leave_date BETWEEN :from AND :to
    ");
    $stmt->bindValue(':uid',  $userId,   PDO::PARAM_INT);
    $stmt->bindValue(':from', $fyStartStr, PDO::PARAM_STR);
    $stmt->bindValue(':to',   $fyEndStr,   PDO::PARAM_STR);
    $stmt->execute();
    $used = (int)$stmt->fetchColumn();

    $GRANTED = 12; // 毎年度の固定付与
    $remaining = max(0, $GRANTED - $used);

    echo json_encode([
        'success'   => true,
        'user_id'   => $userId,
        'granted'   => $GRANTED,
        'used'      => $used,
        'remaining' => $remaining,
        'fy_start'  => $fyStartStr,
        'fy_end'    => $fyEndStr,
    ], JSON_UNESCAPED_UNICODE);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'DBエラー: ' . $e->getMessage()], JSON_UNESCAPED_UNICODE);
}

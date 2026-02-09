<?php
/**
 * m_users 一覧API（共通CORS適用・レガシー互換）
 * - 既存互換: 配列そのまま返却（JSONラッパーなし）
 * - GET/POST/OPTIONS 対応
 * - CORS: backend/common/cors.php に統一（withCredentials:true 前提）
 * - mode=basic のときは最小カラム（ただし shift_id を必ず含める）
 * - id 指定（?id=###）に対応：単一ユーザー行を配列で返却（0件なら []）
 */

require_once dirname(__DIR__, 1) . '/common/cors.php'; 
require_once dirname(__DIR__, 1) . '/common/db_manager.php';

try {
    header('Content-Type: application/json; charset=utf-8');

    // 許可メソッド
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    if ($method === 'OPTIONS') {
        // cors.php 内で204を返してexit済みだが、多重呼び出しに備えて念のため
        http_response_code(204);
        exit;
    }
    if ($method !== 'GET' && $method !== 'POST') {
        http_response_code(405);
        echo json_encode(['error' => 'Method Not Allowed (GET/POSTのみ)'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // DB接続
    $dbh = getDb();

    // --- パラメータ（GET/POST両対応） ---
    $mode = '';
    if (isset($_GET['mode'])) {
        $mode = strtolower(trim((string)$_GET['mode']));
    } elseif (isset($_POST['mode'])) {
        $mode = strtolower(trim((string)$_POST['mode']));
    }

    $idParam = 0;
    if (isset($_GET['id'])) {
        $idParam = (int)$_GET['id'];
    } elseif (isset($_POST['id'])) {
        $idParam = (int)$_POST['id'];
    }

    // ===== id 指定: 単一ユーザーを返す（配列で1件 or []） =====
    if ($idParam > 0) {
        $sql = "SELECT 
                    id, name, shift_id, is_authorized, nationality_id, blood_type_id, health_checked_on, 
                    paid_holidays_num, retiremented_on,
                    work_cloth_1_id, work_cloth_2_id, work_cloth_3_id, work_cloth_4_id, work_cloth_5_id, work_cloth_6_id, work_cloth_7_id
                FROM m_users
                WHERE id = :id
                LIMIT 1";
        $stmt = $dbh->prepare($sql);
        $stmt->bindValue(':id', $idParam, PDO::PARAM_INT);
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        echo json_encode($row ? [$row] : [], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // ===== mode=basic: 最小カラム（shift_id を必ず含める） =====
    if ($mode === 'basic') {
        $sql = "SELECT id, name, shift_id, is_authorized, paid_holidays_num
                FROM m_users
                ORDER BY id ASC";
        $stmt = $dbh->prepare($sql);
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode($rows, JSON_UNESCAPED_UNICODE);
        exit;
    }

    // ===== 既定: フル一覧 =====
    $sql = "SELECT 
                id, name, is_authorized, nationality_id, shift_id, blood_type_id, health_checked_on, paid_holidays_num, retiremented_on,
                work_cloth_1_id, work_cloth_2_id, work_cloth_3_id, work_cloth_4_id, work_cloth_5_id, work_cloth_6_id, work_cloth_7_id
            FROM m_users
            ORDER BY id ASC";
    $stmt = $dbh->prepare($sql);
    $stmt->execute();
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode($rows, JSON_UNESCAPED_UNICODE);

} catch (PDOException $e) {
    header('Content-Type: application/json; charset=utf-8');
    http_response_code(500);
    echo json_encode(['error' => 'DBエラー: ' . $e->getMessage()], JSON_UNESCAPED_UNICODE);
} finally {
    $dbh = null;
}

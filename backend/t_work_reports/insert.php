<?php
// backend\t_work_reports\insert.php

// CORS（credentials対応・プリフライト早期終了）
require_once dirname(__DIR__, 1) . '/common/cors.php';
// DB
require_once dirname(__DIR__, 1) . '/common/db_manager.php';

function norm_int_or_null($v) {
    if ($v === '' || $v === null || !isset($v)) return null;
    if (is_numeric($v)) return (int)$v;
    return null;
}
function norm_bool01($v) {
    // true/1/'1'/'true' を 1、それ以外は 0
    if ($v === true) return 1;
    if ($v === 1 || $v === '1') return 1;
    if (is_string($v) && strtolower($v) === 'true') return 1;
    return 0;
}
function norm_time_or_null($s) {
    if ($s === '' || $s === null || !isset($s)) return null;
    // 受け取りは "HH:MM" か "HH:MM:SS" を想定
    if (preg_match('/^\d{2}:\d{2}(:\d{2})?$/', $s)) {
        // 秒が無ければ補完
        return strlen($s) === 5 ? ($s . ':00') : $s;
    }
    return null;
}
function norm_date_required($s) {
    if (!is_string($s) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $s)) {
        http_response_code(400);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => false, 'message' => 'work_date は YYYY-MM-DD で必須です'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    return $s;
}
// work 列用：文字列をtrimして最大255文字に収める。未指定やnullは空文字へ（NOT NULL対策）
function norm_work_string($v) {
    if ($v === null || !isset($v)) return '';
    $s = is_string($v) ? trim($v) : (string)$v;
    if ($s === '') return '';
    if (function_exists('mb_substr')) return mb_substr($s, 0, 255, 'UTF-8');
    return substr($s, 0, 255);
}

function get_json_or_post() {
    $ct = $_SERVER['CONTENT_TYPE'] ?? $_SERVER['HTTP_CONTENT_TYPE'] ?? '';
    if (stripos($ct, 'application/json') !== false) {
        $raw = file_get_contents('php://input');
        $j = json_decode($raw, true);
        return is_array($j) ? $j : [];
    }
    return $_POST ?? [];
}

try {
    if (strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        http_response_code(405);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => false, 'message' => 'Method Not Allowed (POSTのみ)'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $dbh = getDb();
    $in = get_json_or_post();

    // 必須
    $user_id   = norm_int_or_null($in['user_id'] ?? null);
    $work_date = norm_date_required($in['work_date'] ?? null);
    if (!$user_id) {
        http_response_code(400);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => false, 'message' => 'user_id は必須です'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // 任意
    $start_time        = norm_time_or_null($in['start_time'] ?? null);
    $finish_time       = norm_time_or_null($in['finish_time'] ?? null);
    $is_canceled       = norm_bool01($in['is_canceled'] ?? 0);
    $alcohol_checked   = norm_bool01($in['alcohol_checked'] ?? 0);
    $condition_checked = norm_bool01($in['condition_checked'] ?? 0);
    $is_night_shift    = norm_bool01($in['is_night_shift'] ?? 0);
    $vehicle_id        = norm_int_or_null($in['vehicle_id'] ?? null);
    $on_site_id        = norm_int_or_null($in['on_site_id'] ?? null);
    $on_site_id2       = norm_int_or_null($in['on_site_id2'] ?? null);
    $work              = norm_work_string($in['work'] ?? '');

    $payment1_id = norm_int_or_null($in['payment1_id'] ?? null);
    $amount1     = norm_int_or_null($in['amount1'] ?? null);
    $payment2_id = norm_int_or_null($in['payment2_id'] ?? null);
    $amount2     = norm_int_or_null($in['amount2'] ?? null);
    $payment3_id = norm_int_or_null($in['payment3_id'] ?? null);
    $amount3     = norm_int_or_null($in['amount3'] ?? null);
    $payment4_id = norm_int_or_null($in['payment4_id'] ?? null);
    $amount4     = norm_int_or_null($in['amount4'] ?? null);
    $payment5_id = norm_int_or_null($in['payment5_id'] ?? null);
    $amount5     = norm_int_or_null($in['amount5'] ?? null);

    // Upsert（user_id + work_date が一意）
    $sql = "
        INSERT INTO t_work_reports (
            user_id, work_date,
            start_time, finish_time, is_canceled,
            alcohol_checked, condition_checked, is_night_shift,
            on_site_id, on_site_id2, work,
            vehicle_id,
            payment1_id, amount1,
            payment2_id, amount2,
            payment3_id, amount3,
            payment4_id, amount4,
            payment5_id, amount5,
            created_at, updated_at
        ) VALUES (
            :user_id, :work_date,
            :start_time, :finish_time, :is_canceled,
            :alcohol_checked, :condition_checked, :is_night_shift,
            :on_site_id, :on_site_id2, :work,
            :vehicle_id,
            :payment1_id, :amount1,
            :payment2_id, :amount2,
            :payment3_id, :amount3,
            :payment4_id, :amount4,
            :payment5_id, :amount5,
            NOW(), NOW()
        )
        ON DUPLICATE KEY UPDATE
            start_time = VALUES(start_time),
            finish_time = VALUES(finish_time),
            is_canceled = VALUES(is_canceled),
            alcohol_checked = VALUES(alcohol_checked),
            condition_checked = VALUES(condition_checked),
            is_night_shift = VALUES(is_night_shift),
            on_site_id = VALUES(on_site_id),
            on_site_id2 = VALUES(on_site_id2),
            work = VALUES(work),
            vehicle_id = VALUES(vehicle_id),
            payment1_id = VALUES(payment1_id),
            amount1 = VALUES(amount1),
            payment2_id = VALUES(payment2_id),
            amount2 = VALUES(amount2),
            payment3_id = VALUES(payment3_id),
            amount3 = VALUES(amount3),
            payment4_id = VALUES(payment4_id),
            amount4 = VALUES(amount4),
            payment5_id = VALUES(payment5_id),
            amount5 = VALUES(amount5),
            updated_at = NOW()
    ";

    $stmt = $dbh->prepare($sql);
    $stmt->bindValue(':user_id', $user_id, PDO::PARAM_INT);
    $stmt->bindValue(':work_date', $work_date, PDO::PARAM_STR);

    if ($start_time === null) { $stmt->bindValue(':start_time', null, PDO::PARAM_NULL); }
    else { $stmt->bindValue(':start_time', $start_time, PDO::PARAM_STR); }

    if ($finish_time === null) { $stmt->bindValue(':finish_time', null, PDO::PARAM_NULL); }
    else { $stmt->bindValue(':finish_time', $finish_time, PDO::PARAM_STR); }

    $stmt->bindValue(':is_canceled', $is_canceled, PDO::PARAM_INT);
    $stmt->bindValue(':alcohol_checked', $alcohol_checked, PDO::PARAM_INT);
    $stmt->bindValue(':condition_checked', $condition_checked, PDO::PARAM_INT);
    $stmt->bindValue(':is_night_shift', $is_night_shift, PDO::PARAM_INT);

    if ($on_site_id === null) { $stmt->bindValue(':on_site_id', null, PDO::PARAM_NULL); }
    else { $stmt->bindValue(':on_site_id', $on_site_id, PDO::PARAM_INT); }

    if ($on_site_id2 === null) { $stmt->bindValue(':on_site_id2', null, PDO::PARAM_NULL); }
    else { $stmt->bindValue(':on_site_id2', $on_site_id2, PDO::PARAM_INT); }

    $stmt->bindValue(':work', $work, PDO::PARAM_STR);

    if ($vehicle_id === null) { $stmt->bindValue(':vehicle_id', null, PDO::PARAM_NULL); }
    else { $stmt->bindValue(':vehicle_id', $vehicle_id, PDO::PARAM_INT); }

    // payments & amounts
    $stmt->bindValue(':payment1_id', $payment1_id, $payment1_id === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
    $stmt->bindValue(':amount1', $amount1, $amount1 === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
    $stmt->bindValue(':payment2_id', $payment2_id, $payment2_id === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
    $stmt->bindValue(':amount2', $amount2, $amount2 === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
    $stmt->bindValue(':payment3_id', $payment3_id, $payment3_id === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
    $stmt->bindValue(':amount3', $amount3, $amount3 === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
    $stmt->bindValue(':payment4_id', $payment4_id, $payment4_id === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
    $stmt->bindValue(':amount4', $amount4, $amount4 === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
    $stmt->bindValue(':payment5_id', $payment5_id, $payment5_id === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
    $stmt->bindValue(':amount5', $amount5, $amount5 === null ? PDO::PARAM_NULL : PDO::PARAM_INT);

    $stmt->execute();

    $affected = $stmt->rowCount(); // 1=insert, 2=update（MySQL仕様）
    $insertId = $dbh->lastInsertId();
    if (!$insertId) {
      $stmt2 = $dbh->prepare("SELECT id FROM t_work_reports WHERE user_id = :uid AND work_date = :d LIMIT 1");
      $stmt2->bindValue(':uid', $user_id, PDO::PARAM_INT);
      $stmt2->bindValue(':d', $work_date, PDO::PARAM_STR);
      $stmt2->execute();
      $row = $stmt2->fetch(PDO::FETCH_ASSOC);
      $insertId = $row ? (int)$row['id'] : 0;
    }

    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'success'  => true,
        'affected' => $affected,
        'id'       => (int)$insertId,
    ], JSON_UNESCAPED_UNICODE);

} catch (PDOException $e) {
    header('Content-Type: application/json; charset=utf-8');
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'DBエラー: ' . $e->getMessage(),
    ], JSON_UNESCAPED_UNICODE);
}

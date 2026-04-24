<?php
// backend\t_work_reports\update.php

require_once dirname(__DIR__, 1) . '/common/cors.php';
require_once dirname(__DIR__, 1) . '/common/db_manager.php';
require_once dirname(__DIR__, 1) . '/common/mail_notifications.php';

/**
 * POST /backend/t_work_reports/update.php
 * 識別子（必須のどちらか）:
 *   - id
 *   - user_id + work_date
 *
 * 更新可能フィールド（任意で部分更新）:
 *   start_time, finish_time,
 *   start_time2, finish_time2, work2,
 *   is_canceled, alcohol_checked, condition_checked,
 *   on_site_id, on_site_id2, work,
 *   is_night_shift,
 *   vehicle_id,
 *   payment1_id, amount1, payment2_id, amount2, payment3_id, amount3,
 *   payment4_id, amount4, payment5_id, amount5
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

function norm_bool01_or_null($v) {
    if ($v === '' || $v === null || !isset($v)) return null;

    if ($v === true || $v === 1 || $v === '1') return 1;
    if (is_string($v) && strtolower($v) === 'true') return 1;

    if ($v === false || $v === 0 || $v === '0') return 0;
    if (is_string($v) && strtolower($v) === 'false') return 0;

    return null;
}

function norm_time_or_null($s) {
    if ($s === '' || $s === null || !isset($s)) return null;
    if (preg_match('/^\d{2}:\d{2}(:\d{2})?$/', (string)$s)) {
        return strlen($s) === 5 ? ($s . ':00') : (string)$s;
    }
    return null;
}

function norm_date($s) {
    if (!isset($s) || $s === '') return null;
    return preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$s) ? (string)$s : null;
}

/**
 * work/work2用：trimして最大255文字に収める。
 * - 値が null のときは NULL を返す（明示的にNULLクリア可能）
 * - ''（空文字）は '' のまま（空文字クリア）
 */
function norm_varchar255_or_null($v) {
    if (!array_key_exists('_dummy', ['_dummy' => 1])) { /* noop */ }

    if ($v === null) return null; // ★NULLクリアを許可
    if (!isset($v)) return null;

    $s = is_string($v) ? trim($v) : (string)$v;
    if ($s === '') return ''; // 空文字にしたい場合は空文字を送る

    if (function_exists('mb_substr')) return mb_substr($s, 0, 255, 'UTF-8');
    return substr($s, 0, 255);
}

// 列存在チェック（失敗しても落とさない）
function has_column(PDO $dbh, string $table, string $column): bool {
    try {
        $sql = "SHOW COLUMNS FROM `{$table}` LIKE :col";
        $st = $dbh->prepare($sql);
        $st->bindValue(':col', $column, PDO::PARAM_STR);
        $st->execute();
        $row = $st->fetch(PDO::FETCH_ASSOC);
        return !!$row;
    } catch (Throwable $e) {
        return false;
    }
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
    $in  = get_json_or_post();

    // DBにある列だけ更新対象にする
    $hasOnSite2 = has_column($dbh, 't_work_reports', 'on_site_id2');
    $hasNight   = has_column($dbh, 't_work_reports', 'is_night_shift');

    // ★区間2（列ごとに判定）
    $hasStart2  = has_column($dbh, 't_work_reports', 'start_time2');
    $hasFinish2 = has_column($dbh, 't_work_reports', 'finish_time2');
    $hasWork2   = has_column($dbh, 't_work_reports', 'work2');

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

    // 更新候補（送られてきたかどうかで判定）
    $fields = [
        'start_time'        => ['type' => 'time'],
        'finish_time'       => ['type' => 'time'],

        'is_canceled'       => ['type' => 'bool01'],
        'alcohol_checked'   => ['type' => 'bool01'],
        'condition_checked' => ['type' => 'bool01'],

        'on_site_id'        => ['type' => 'int'],
        'work'              => ['type' => 'v255'],

        'vehicle_id'        => ['type' => 'int'],

        'payment1_id' => ['type' => 'int'], 'amount1' => ['type' => 'int'],
        'payment2_id' => ['type' => 'int'], 'amount2' => ['type' => 'int'],
        'payment3_id' => ['type' => 'int'], 'amount3' => ['type' => 'int'],
        'payment4_id' => ['type' => 'int'], 'amount4' => ['type' => 'int'],
        'payment5_id' => ['type' => 'int'], 'amount5' => ['type' => 'int'],
    ];

    if ($hasOnSite2) $fields['on_site_id2'] = ['type' => 'int'];
    if ($hasNight)   $fields['is_night_shift'] = ['type' => 'bool01'];

    // ★区間2（存在する列だけ）
    if ($hasStart2)  $fields['start_time2']  = ['type' => 'time'];
    if ($hasFinish2) $fields['finish_time2'] = ['type' => 'time'];
    if ($hasWork2)   $fields['work2']        = ['type' => 'v255'];

    $setParts = [];
    $params   = [];

    foreach ($fields as $key => $meta) {
        if (!array_key_exists($key, $in)) continue; // 未送信 → 更新しない
        $raw = $in[$key];

        switch ($meta['type']) {
            case 'time':   $val = norm_time_or_null($raw); break;
            case 'bool01': $val = norm_bool01_or_null($raw); break;
            case 'int':    $val = norm_int_or_null($raw); break;
            case 'v255':   $val = norm_varchar255_or_null($raw); break;
            default:       $val = null;
        }

        $ph = ':' . $key;
        $setParts[]  = "{$key} = {$ph}";
        $params[$ph] = $val; // null もOK（NULLクリア可能）
    }

    if (empty($setParts)) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success'=>true, 'affected'=>0, 'message'=>'更新対象フィールドがありません'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // WHERE
    if ($id) {
        $where = 'id = :_id';
        $params[':_id'] = (int)$id;
        $whereParams = [':_id' => (int)$id];
    } else {
        $where = 'user_id = :_uid AND work_date = :_date';
        $params[':_uid']  = (int)$user_id;
        $params[':_date'] = $work_date;
        $whereParams = [
            ':_uid' => (int)$user_id,
            ':_date' => $work_date,
        ];
    }

    $beforeSql = "SELECT * FROM t_work_reports WHERE {$where} LIMIT 1";
    $beforeStmt = $dbh->prepare($beforeSql);
    foreach ($whereParams as $k => $v) {
        if ($v === null) {
            $beforeStmt->bindValue($k, null, PDO::PARAM_NULL);
        } elseif (is_int($v)) {
            $beforeStmt->bindValue($k, $v, PDO::PARAM_INT);
        } else {
            $beforeStmt->bindValue($k, (string)$v, PDO::PARAM_STR);
        }
    }
    $beforeStmt->execute();
    $beforeReport = $beforeStmt->fetch(PDO::FETCH_ASSOC) ?: [];

    $sql = "
        UPDATE t_work_reports
        SET " . implode(', ', $setParts) . ",
            updated_at = NOW()
        WHERE {$where}
        LIMIT 1
    ";

    $stmt = $dbh->prepare($sql);

    foreach ($params as $k => $v) {
        if ($v === null) {
            $stmt->bindValue($k, null, PDO::PARAM_NULL);
        } elseif (is_int($v)) {
            $stmt->bindValue($k, $v, PDO::PARAM_INT);
        } else {
            $stmt->bindValue($k, (string)$v, PDO::PARAM_STR);
        }
    }

    $stmt->execute();
    $affected = $stmt->rowCount();

    if ($affected > 0 && !empty($beforeReport)) {
        try {
            $notifyUserId = (int)$beforeReport['user_id'];
            $notifyWorkDate = (string)$beforeReport['work_date'];
            $afterReport = fetchWorkReportNotificationRow($dbh, $notifyUserId, $notifyWorkDate);
            notifyWorkReportReimburseChange($dbh, $notifyUserId, $notifyWorkDate, $beforeReport, $afterReport, false);
        } catch (Throwable $mailError) {
            error_log('[t_work_reports/update notify] ' . $mailError->getMessage());
        }
    }

    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'success'  => true,
        'affected' => $affected,
        'has_on_site_id2'    => $hasOnSite2 ? 1 : 0,
        'has_is_night_shift' => $hasNight ? 1 : 0,
        'has_start_time2'    => $hasStart2 ? 1 : 0,
        'has_finish_time2'   => $hasFinish2 ? 1 : 0,
        'has_work2'          => $hasWork2 ? 1 : 0,
    ], JSON_UNESCAPED_UNICODE);

} catch (PDOException $e) {
    header('Content-Type: application/json; charset=utf-8');
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'DBエラー: ' . $e->getMessage(),
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    header('Content-Type: application/json; charset=utf-8');
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'サーバーエラー: ' . $e->getMessage(),
    ], JSON_UNESCAPED_UNICODE);
}

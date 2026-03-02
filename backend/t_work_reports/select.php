<?php
// backend\t_work_reports\select.php

require_once dirname(__DIR__, 1) . '/common/cors.php';
require_once dirname(__DIR__, 1) . '/common/db_manager.php';

/**
 * GET /backend/t_work_reports/select.php
 * クエリ:
 *   - user_id   (必須) : int
 *   - month     (任意) : YYYY-MM
 *   - date_from (任意) : YYYY-MM-DD
 *   - date_to   (任意) : YYYY-MM-DD
 *   - limit     (任意) : 1..1000  default 100
 *   - offset    (任意) : 0..      default 0
 *   - order     (任意) : 'asc' | 'desc'  default 'asc'
 *
 * レスポンス:
 *   { success:true, total:int, count:int, data:[ {...}, ... ],
 *     has_on_site_id2:0|1, has_is_night_shift:0|1,
 *     has_segment2:0|1 }
 *
 * ★追加（区間2）:
 *   - start_time2, finish_time2, work2
 */

function norm_int($v, $def = 0) {
    return (isset($v) && $v !== '' && is_numeric($v)) ? (int)$v : $def;
}
function norm_date($s) {
    if (!isset($s) || $s === '') return null;
    return preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$s) ? (string)$s : null;
}
function norm_month($s) {
    if (!isset($s) || $s === '') return null;
    return preg_match('/^\d{4}-\d{2}$/', (string)$s) ? (string)$s : null;
}

try {
    $m = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
    if ($m === 'OPTIONS') { http_response_code(204); exit; }
    if ($m !== 'GET') {
        http_response_code(405);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success'=>false,'message'=>'Method Not Allowed (GETのみ)'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $dbh = getDb();

    // 入力
    $userId   = norm_int($_GET['user_id'] ?? null, 0);
    $month    = norm_month($_GET['month'] ?? null);
    $dateFrom = norm_date($_GET['date_from'] ?? null);
    $dateTo   = norm_date($_GET['date_to'] ?? null);
    $limit    = max(1, min(1000, norm_int($_GET['limit'] ?? null, 100)));
    $offset   = max(0, norm_int($_GET['offset'] ?? null, 0));
    $orderDir = strtolower($_GET['order'] ?? 'asc') === 'desc' ? 'DESC' : 'ASC';

    if ($userId <= 0) {
        http_response_code(400);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success'=>false,'message'=>'user_id は必須です'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // month 指定があれば from/to を算出
    if ($month && (!$dateFrom && !$dateTo)) {
        $from = $month . '-01';
        $to   = date('Y-m-t', strtotime($from));
        $dateFrom = $from;
        $dateTo   = $to;
    }

    // どれも無ければ直近31日
    if ($dateFrom === null && $dateTo === null) {
        $dateTo   = date('Y-m-d');
        $dateFrom = date('Y-m-d', strtotime('-30 days'));
    }

    // WHERE
    $where = ['user_id = :user_id'];
    $params = [':user_id' => $userId];
    if ($dateFrom !== null) { $where[] = 'work_date >= :date_from'; $params[':date_from'] = $dateFrom; }
    if ($dateTo   !== null) { $where[] = 'work_date <= :date_to';   $params[':date_to']   = $dateTo;   }
    $whereSql = 'WHERE ' . implode(' AND ', $where);

    // 件数
    $sqlCnt = "SELECT COUNT(*) FROM t_work_reports {$whereSql}";
    $stCnt = $dbh->prepare($sqlCnt);
    foreach ($params as $k => $v) { $stCnt->bindValue($k, $v); }
    $stCnt->execute();
    $total = (int)$stCnt->fetchColumn();

    // ============================================================
    // SELECT（on_site_id2 / is_night_shift / 区間2カラム を「ある前提で試す」→無ければ落とす）
    // ============================================================
    $hasOnSite2  = 1;
    $hasNight    = 1;
    $hasSegment2 = 1; // start_time2/finish_time2/work2 の有無

    // ★full（全部あり）
    $selectColsFull = [
        'id','user_id','work_date','start_time','finish_time',
        'start_time2','finish_time2',
        'on_site_id','on_site_id2',
        'work','work2',
        'is_canceled','alcohol_checked','condition_checked',
        'is_night_shift',
        'vehicle_id',
        'payment1_id','amount1',
        'payment2_id','amount2',
        'payment3_id','amount3',
        'payment4_id','amount4',
        'payment5_id','amount5',
        'created_at','updated_at',
    ];

    // ===== フォールバック群 =====
    // 1) on_site_id2 無し / is_night_shift あり / 区間2あり
    $selectColsNoSite2 = [
        'id','user_id','work_date','start_time','finish_time',
        'start_time2','finish_time2',
        'on_site_id',
        'work','work2',
        'is_canceled','alcohol_checked','condition_checked',
        'is_night_shift',
        'vehicle_id',
        'payment1_id','amount1',
        'payment2_id','amount2',
        'payment3_id','amount3',
        'payment4_id','amount4',
        'payment5_id','amount5',
        'created_at','updated_at',
    ];

    // 2) on_site_id2 あり / is_night_shift 無し / 区間2あり
    $selectColsNoNight = [
        'id','user_id','work_date','start_time','finish_time',
        'start_time2','finish_time2',
        'on_site_id','on_site_id2',
        'work','work2',
        'is_canceled','alcohol_checked','condition_checked',
        'vehicle_id',
        'payment1_id','amount1',
        'payment2_id','amount2',
        'payment3_id','amount3',
        'payment4_id','amount4',
        'payment5_id','amount5',
        'created_at','updated_at',
    ];

    // 3) 両方無し / 区間2あり
    $selectColsBase = [
        'id','user_id','work_date','start_time','finish_time',
        'start_time2','finish_time2',
        'on_site_id',
        'work','work2',
        'is_canceled','alcohol_checked','condition_checked',
        'vehicle_id',
        'payment1_id','amount1',
        'payment2_id','amount2',
        'payment3_id','amount3',
        'payment4_id','amount4',
        'payment5_id','amount5',
        'created_at','updated_at',
    ];

    // 4) 区間2無し / on_site_id2 あり / night あり
    $selectColsNoSeg2_Full = [
        'id','user_id','work_date','start_time','finish_time',
        'on_site_id','on_site_id2',
        'work',
        'is_canceled','alcohol_checked','condition_checked',
        'is_night_shift',
        'vehicle_id',
        'payment1_id','amount1',
        'payment2_id','amount2',
        'payment3_id','amount3',
        'payment4_id','amount4',
        'payment5_id','amount5',
        'created_at','updated_at',
    ];

    // 5) 区間2無し / on_site_id2 無し / night あり
    $selectColsNoSeg2_NoSite2 = [
        'id','user_id','work_date','start_time','finish_time',
        'on_site_id',
        'work',
        'is_canceled','alcohol_checked','condition_checked',
        'is_night_shift',
        'vehicle_id',
        'payment1_id','amount1',
        'payment2_id','amount2',
        'payment3_id','amount3',
        'payment4_id','amount4',
        'payment5_id','amount5',
        'created_at','updated_at',
    ];

    // 6) 区間2無し / on_site_id2 あり / night 無し
    $selectColsNoSeg2_NoNight = [
        'id','user_id','work_date','start_time','finish_time',
        'on_site_id','on_site_id2',
        'work',
        'is_canceled','alcohol_checked','condition_checked',
        'vehicle_id',
        'payment1_id','amount1',
        'payment2_id','amount2',
        'payment3_id','amount3',
        'payment4_id','amount4',
        'payment5_id','amount5',
        'created_at','updated_at',
    ];

    // 7) 区間2無し / 両方無し（最小）
    $selectColsNoSeg2_Base = [
        'id','user_id','work_date','start_time','finish_time',
        'on_site_id',
        'work',
        'is_canceled','alcohol_checked','condition_checked',
        'vehicle_id',
        'payment1_id','amount1',
        'payment2_id','amount2',
        'payment3_id','amount3',
        'payment4_id','amount4',
        'payment5_id','amount5',
        'created_at','updated_at',
    ];

    $buildSql = function(array $cols) use ($whereSql, $orderDir) {
        return "
            SELECT
                " . implode(",\n                ", $cols) . "
            FROM t_work_reports
            {$whereSql}
            ORDER BY work_date {$orderDir}, id {$orderDir}
            LIMIT :limit OFFSET :offset
        ";
    };

    // 実行ヘルパ（tryチェーンを少し簡潔に）
    $execSelect = function(array $cols) use ($dbh, $buildSql, $params, $limit, $offset) {
        $sql = $buildSql($cols);
        $stmt = $dbh->prepare($sql);
        foreach ($params as $k => $v) { $stmt->bindValue($k, $v); }
        $stmt->bindValue(':limit',  $limit,  PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    };

    // 1) full 試行（全部あり）
    try {
        $rows = $execSelect($selectColsFull);
    } catch (PDOException $e1) {
        // ここで落ちる要因：on_site_id2 / is_night_shift / 区間2カラム のいずれか不足
        // 2) on_site_id2 無し / night あり / 区間2あり
        try {
            $hasOnSite2 = 0;
            $rows = $execSelect($selectColsNoSite2);
        } catch (PDOException $e2) {
            // 3) on_site_id2 あり / night 無し / 区間2あり
            try {
                $hasOnSite2 = 1;
                $hasNight   = 0;
                $rows = $execSelect($selectColsNoNight);
            } catch (PDOException $e3) {
                // 4) 両方無し / 区間2あり
                try {
                    $hasOnSite2 = 0;
                    $hasNight   = 0;
                    $rows = $execSelect($selectColsBase);
                } catch (PDOException $e4) {
                    // ここまで落ちるなら「区間2カラムが無い」可能性が高いので、区間2無しで再試行
                    $hasSegment2 = 0;

                    // 5) 区間2無し / on_site_id2 あり / night あり
                    try {
                        $hasOnSite2 = 1;
                        $hasNight   = 1;
                        $rows = $execSelect($selectColsNoSeg2_Full);
                    } catch (PDOException $e5) {
                        // 6) 区間2無し / on_site_id2 無し / night あり
                        try {
                            $hasOnSite2 = 0;
                            $hasNight   = 1;
                            $rows = $execSelect($selectColsNoSeg2_NoSite2);
                        } catch (PDOException $e6) {
                            // 7) 区間2無し / on_site_id2 あり / night 無し
                            try {
                                $hasOnSite2 = 1;
                                $hasNight   = 0;
                                $rows = $execSelect($selectColsNoSeg2_NoNight);
                            } catch (PDOException $e7) {
                                // 8) 区間2無し / 両方無し
                                $hasOnSite2 = 0;
                                $hasNight   = 0;
                                $rows = $execSelect($selectColsNoSeg2_Base);
                            }
                        }
                    }
                }
            }
        }
    }

    // フロント安定のため：無い場合もキーは必ず返す
    foreach ($rows as &$r) {
        if (!array_key_exists('on_site_id2', $r)) $r['on_site_id2'] = null;
        if (!array_key_exists('is_night_shift', $r)) $r['is_night_shift'] = 0;

        // ★区間2（無い場合は必ず空扱いにする）
        if (!array_key_exists('start_time2', $r)) $r['start_time2'] = null;
        if (!array_key_exists('finish_time2', $r)) $r['finish_time2'] = null;
        if (!array_key_exists('work2', $r)) $r['work2'] = '';
    }
    unset($r);

    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'success' => true,
        'total'   => $total,
        'count'   => count($rows),
        'data'    => $rows,
        'has_on_site_id2'       => (int)$hasOnSite2,
        'has_is_night_shift'    => (int)$hasNight,
        'has_segment2'          => (int)$hasSegment2,
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
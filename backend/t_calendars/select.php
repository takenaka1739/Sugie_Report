<?php
require_once dirname(__DIR__, 1) . '/common/cors.php';

// DB
require_once dirname(__DIR__, 1) . '/common/db_manager.php';

/**
 * 互換性維持のための PDO リゾルバ（既存コードから流用）
 */
function resolvePdo(): PDO {
    if (function_exists('getPdo')) { return getPdo(); }
    if (function_exists('get_pdo')) { return get_pdo(); }
    if (function_exists('db')) { $pdo = db(); if ($pdo instanceof PDO) return $pdo; }
    if (function_exists('getDb')) { $pdo = getDb(); if ($pdo instanceof PDO) return $pdo; }
    if (class_exists('DBManager') && method_exists('DBManager', 'getPdo')) {
        return DBManager::getPdo();
    }
    if (defined('DB_DSN') && defined('DB_USER')) {
        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ];
        return new PDO(DB_DSN, DB_USER, defined('DB_PASSWORD') ? DB_PASSWORD : null, $options);
    }
    throw new RuntimeException('PDO resolver not found. Please expose getPdo() or DB constants in db_manager.php');
}

/**
 * 第n土曜 (n=1..5) を YYYY-MM-DD で返す。存在しない場合 null
 */
function getNthSaturday(int $year, int $month, int $n): ?string {
    if ($n < 1) return null;

    $first = new DateTime(sprintf('%04d-%02d-01', $year, $month));
    $daysInMonth = (int)$first->format('t');

    $sats = [];
    for ($d = 1; $d <= $daysInMonth; $d++) {
        $dt = new DateTime(sprintf('%04d-%02d-%02d', $year, $month, $d));
        // PHP: w (0=日 .. 6=土)
        if ((int)$dt->format('w') === 6) {
            $sats[] = $dt->format('Y-m-d');
        }
    }

    return $sats[$n - 1] ?? null;
}

/**
 * 指定年の「日曜日」を YYYY-MM-DD 配列で返す
 */
function listSundays(int $year): array {
    $from = new DateTime(sprintf('%04d-01-01', $year));
    $to   = new DateTime(sprintf('%04d-12-31', $year));

    $days = [];
    $cur = clone $from;
    while ($cur <= $to) {
        if ((int)$cur->format('w') === 0) { // Sunday
            $days[] = $cur->format('Y-m-d');
        }
        $cur->modify('+1 day');
    }
    return $days;
}

/**
 * 日本の祝日（holidays-jp の date.json）から指定年の日付配列を作る
 * - ネットワーク失敗時は空配列でフォールバック
 */
function fetchJapanHolidays(int $year): array {
    $url = 'https://holidays-jp.github.io/api/v1/date.json';

    $ctx = stream_context_create([
        'http' => [
            'timeout' => 2.0,
            'header'  => "User-Agent: ReportCalendar/1.0\r\n",
        ],
        'ssl' => [
            'verify_peer'      => true,
            'verify_peer_name' => true,
        ],
    ]);

    $raw = @file_get_contents($url, false, $ctx);
    if ($raw === false || $raw === '') return [];

    $json = json_decode($raw, true);
    if (!is_array($json)) return [];

    $prefix = sprintf('%04d-', $year);
    $dates = [];
    foreach ($json as $dateStr => $name) {
        if (is_string($dateStr) && str_starts_with($dateStr, $prefix)) {
            $dates[] = $dateStr;
        }
    }
    return $dates;
}

/**
 * 初期値 items を生成
 * - 日曜: 法定休日(2)
 * - 第2/第4土曜: 社内休日(1)
 * - 祝日: 社内休日(1)
 *
 * ※ 優先度:
 *   日曜(2) を優先。祝日が日曜に被っても 2 のままにする。
 */
function buildDefaultItems(int $year): array {
    $defaults = [];

    // 日曜（法定休日=2）を先に入れて優先させる
    foreach (listSundays($year) as $d) {
        $defaults[$d] = 2;
    }

    // 第2・第4土曜（社内休日=1）※日曜(2)には上書きしない
    for ($m = 1; $m <= 12; $m++) {
        $d2 = getNthSaturday($year, $m, 2);
        $d4 = getNthSaturday($year, $m, 4);
        if ($d2 && !isset($defaults[$d2])) $defaults[$d2] = 1;
        if ($d4 && !isset($defaults[$d4])) $defaults[$d4] = 1;
    }

    // 祝日（社内休日=1）※日曜(2)には上書きしない
    $holidays = fetchJapanHolidays($year);
    foreach ($holidays as $d) {
        if (!isset($defaults[$d])) $defaults[$d] = 1;
    }

    return $defaults;
}

try {
    header('Content-Type: application/json; charset=utf-8');

    // 許可メソッド（GETのみ）※ OPTIONS は cors.php で204終了済み
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    if ($method !== 'GET') {
        http_response_code(405);
        echo json_encode(['ok' => false, 'message' => 'Method Not Allowed (GET only)'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // ===== 入力パラメータ =====
    // 新モードの判定（date_from / date_to があれば期間モード）
    $dateFrom  = isset($_GET['date_from']) ? trim((string)$_GET['date_from']) : '';
    $dateTo    = isset($_GET['date_to'])   ? trim((string)$_GET['date_to'])   : '';
    $rangeMode = ($dateFrom !== '' && $dateTo !== '');

    // ===== 期間指定モード（新）=====
    if ($rangeMode) {
        $locale_type = isset($_GET['locale_type']) ? (int)$_GET['locale_type'] : 0; // 0=全体, 1/2は既存区分
        $holidayOnly = isset($_GET['holiday_only']) ? (int)$_GET['holiday_only'] : 0;

        // バリデーション
        $re = '/^\d{4}-\d{2}-\d{2}$/';
        if (!preg_match($re, $dateFrom) || !preg_match($re, $dateTo)) {
            http_response_code(400);
            echo json_encode(['error' => 'date_from/date_to は YYYY-MM-DD で指定してください'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        $fromDt = DateTime::createFromFormat('Y-m-d', $dateFrom);
        $toDt   = DateTime::createFromFormat('Y-m-d', $dateTo);
        if (!$fromDt || !$toDt) {
            http_response_code(400);
            echo json_encode(['error' => '不正な日付です'], JSON_UNESCAPED_UNICODE);
            exit;
        }

        $pdo = resolvePdo();

        // 動的 WHERE
        $where   = ['the_date BETWEEN :from AND :to'];
        $params  = [':from' => $dateFrom, ':to' => $dateTo];

        if (in_array($locale_type, [1, 2], true)) {
            $where[]                = 'locale_type = :locale_type';
            $params[':locale_type'] = $locale_type;
        }
        if ($holidayOnly === 1) {
            // 1=社内休日, 2=法定休日
            $where[] = 'status IN (1,2)';
        }

        $whereSql = implode(' AND ', $where);

        $sql = "
            SELECT the_date, locale_type, status, note
            FROM t_calendars
            WHERE {$whereSql}
            ORDER BY the_date ASC, locale_type ASC
        ";
        $stmt = $pdo->prepare($sql);
        foreach ($params as $k => $v) {
            $stmt->bindValue($k, $v);
        }
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // 新モードは配列をそのまま返却
        echo json_encode($rows, JSON_UNESCAPED_UNICODE);
        exit;
    }

    // ===== レガシーモード（既存互換：年単位）=====
    $year        = isset($_GET['year']) ? (int)$_GET['year'] : 0;
    $locale_type = isset($_GET['locale_type']) ? (int)$_GET['locale_type'] : 0;

    if ($year < 1970 || $year > 2100) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'message' => 'year is invalid'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if (!in_array($locale_type, [1, 2], true)) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'message' => 'locale_type must be 1 or 2'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $from = sprintf('%04d-01-01', $year);
    $to   = sprintf('%04d-12-31', $year);

    $pdo = resolvePdo();

    // ▼ 初期値（日曜=法定休日、2/4土曜=社内休日、祝日=社内休日）を「不足分だけ」DBに投入（上書きしない）
    $defaults = buildDefaultItems($year);

    if (!empty($defaults)) {
        $pdo->beginTransaction();

        // 既存日付（この年・locale）を取得してSet化
        $sqlExist = "SELECT the_date FROM t_calendars WHERE locale_type = :lt AND the_date BETWEEN :from AND :to";
        $stExist = $pdo->prepare($sqlExist);
        $stExist->bindValue(':lt', $locale_type, PDO::PARAM_INT);
        $stExist->bindValue(':from', $from, PDO::PARAM_STR);
        $stExist->bindValue(':to',   $to,   PDO::PARAM_STR);
        $stExist->execute();

        $exists = [];
        while ($r = $stExist->fetch(PDO::FETCH_ASSOC)) {
            $exists[(string)$r['the_date']] = true;
        }

        // 未登録だけINSERT
        $toInsert = [];
        foreach ($defaults as $d => $st) {
            if (!isset($exists[$d])) {
                $toInsert[] = [$d, $locale_type, (int)$st];
            }
        }

        if (!empty($toInsert)) {
            $placeholders = [];
            $params = [];
            foreach ($toInsert as $row) {
                $placeholders[] = "(?, ?, ?, NULL, NOW())";
                $params[] = $row[0]; // the_date
                $params[] = $row[1]; // locale_type
                $params[] = $row[2]; // status
            }

            // 既存があれば何もしない（noop）
            $sqlIns = "
                INSERT INTO t_calendars (the_date, locale_type, status, updated_by_user_id, updated_at)
                VALUES " . implode(',', $placeholders) . "
                ON DUPLICATE KEY UPDATE status = status
            ";
            $stIns = $pdo->prepare($sqlIns);
            $stIns->execute($params);
        }

        $pdo->commit();
    }

    // 返却用：DBから取得（これが正）
    $sql = <<<SQL
        SELECT the_date, status
        FROM t_calendars
        WHERE locale_type = :locale_type
          AND the_date BETWEEN :from AND :to
        ORDER BY the_date ASC
    SQL;
    $stmt = $pdo->prepare($sql);
    $stmt->bindValue(':locale_type', $locale_type, PDO::PARAM_INT);
    $stmt->bindValue(':from', $from, PDO::PARAM_STR);
    $stmt->bindValue(':to',   $to,   PDO::PARAM_STR);
    $stmt->execute();

    // 既存の期待形式: { "YYYY-MM-DD": 0|1|2 }
    $items = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $d = $row['the_date'];
        $s = (int)$row['status']; // 0=出勤,1=社内休日,2=法定休日
        $items[$d] = $s;
    }

    echo json_encode([
        'ok'          => true,
        'items'       => $items,
        'year'        => $year,
        'locale_type' => $locale_type,
    ], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    header('Content-Type: application/json; charset=utf-8');
    http_response_code(500);
    echo json_encode([
        'ok' => false,
        'message' => $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}

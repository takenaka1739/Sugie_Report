<?php
/**
 * POST /t_calendars/update.php
 * Body(JSON): { "the_date": "YYYY-MM-DD", "locale_type": 1|2, "status"?: 0|1|2 }
 * 返却: { ok: true, status: number }  // 0:出勤, 1:社内休日, 2:法定休日
 *
 * 仕様:
 *  - status が来た場合：その status を保存（0も含めて保存する）
 *  - status が無い場合：DB上の現在ステータスを 0→1→2→0 でトグル（従来互換）
 *  - 更新時、updated_by_user_id に “現在ログイン中ユーザー” の m_users.id を保存
 *  - 未ログインなら 401 を返す
 *  - CORS/セッション/エラー処理は common/cors.php
 *
 * 重要:
 *  - 「社内休日/法定休日は全ユーザー共通」運用のため、更新は locale_type=1/2 両方へ反映する
 */

require_once dirname(__DIR__, 1) . '/common/cors.php';     // ← CORS + セッション + ログの共通化
require_once dirname(__DIR__, 1) . '/common/db_manager.php';

header('Content-Type: application/json; charset=utf-8');

function json_out(int $code, array $payload) {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/** ==== セッション確認（共通化後、キーの揺れに対応） ==== */
$auth = $_SESSION['auth'] ?? $_SESSION['user'] ?? null;
$userId = null;
if (is_array($auth)) {
    $userId = $auth['id'] ?? $auth['user_id'] ?? null;
}
if (!$userId) {
    if (function_exists('__api_log')) {
        __api_log(['phase' => 'CAL_UPDATE_AUTH', 'msg' => '401 no session', 'session_keys' => array_keys($_SESSION ?? [])]);
    }
    json_out(401, ['ok' => false, 'message' => 'ログインが必要です']);
}

/** ==== 入力の読み取り & バリデーション ==== */
$raw = file_get_contents('php://input') ?: '';
$in  = json_decode($raw, true) ?? [];

$theDate    = isset($in['the_date']) ? (string)$in['the_date'] : '';
$localeType = isset($in['locale_type']) ? (int)$in['locale_type'] : 0;

// status（任意）
$hasStatus = array_key_exists('status', $in);
$statusIn  = $hasStatus ? (int)$in['status'] : null;

if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $theDate)) {
    json_out(400, ['ok' => false, 'message' => 'the_date の形式が不正です(YYYY-MM-DD)']);
}
$dt = DateTime::createFromFormat('Y-m-d', $theDate);
if (!$dt || $dt->format('Y-m-d') !== $theDate) {
    json_out(400, ['ok' => false, 'message' => 'the_date の日付が不正です']);
}
if (!in_array($localeType, [1, 2], true)) {
    json_out(400, ['ok' => false, 'message' => 'locale_type は 1(日本人) または 2(外国人) を指定してください']);
}
if ($hasStatus && !in_array($statusIn, [0, 1, 2], true)) {
    json_out(400, ['ok' => false, 'message' => 'status は 0,1,2 のいずれかを指定してください']);
}

try {
    // 既存の DB アクセス関数を解決
    if (function_exists('getPdo')) {
        $pdo = getPdo();
    } elseif (function_exists('get_pdo')) {
        $pdo = get_pdo();
    } elseif (function_exists('getDb')) {
        $pdo = getDb();
    } else {
        if (!defined('DB_DSN') || !defined('DB_USER')) {
            throw new RuntimeException('PDO resolver not found.');
        }
        $pdo = new PDO(DB_DSN, DB_USER, defined('DB_PASSWORD') ? DB_PASSWORD : null, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
    }

    // 更新は locale_type=1/2 両方に反映（社内休日は共通運用）
    $targetLocales = [1, 2];

    $pdo->beginTransaction();

    // トグル時の「現在値」は、呼び出し元 locale_type のDB値を基準にする（互換）
    $stmt = $pdo->prepare('SELECT status FROM t_calendars WHERE the_date = :d AND locale_type = :lt LIMIT 1');
    $stmt->bindValue(':d',  $theDate,    PDO::PARAM_STR);
    $stmt->bindValue(':lt', $localeType, PDO::PARAM_INT);
    $stmt->execute();
    $row = $stmt->fetch();
    $before = isset($row['status']) ? (int)$row['status'] : 0;

    // 保存する値：statusが来たらそれを優先、無ければ従来通りトグル
    $next = $hasStatus ? $statusIn : (($before + 1) % 3);

    // UPSERT（複合ユニーク (the_date, locale_type) を想定）
    $sql = 'INSERT INTO t_calendars (the_date, locale_type, status, updated_by_user_id, updated_at)
            VALUES (:d, :lt, :st, :uid, NOW())
            ON DUPLICATE KEY UPDATE
              status = VALUES(status),
              updated_by_user_id = VALUES(updated_by_user_id),
              updated_at = VALUES(updated_at)';

    $stUp = $pdo->prepare($sql);

    foreach ($targetLocales as $lt) {
        $stUp->bindValue(':d',   $theDate,     PDO::PARAM_STR);
        $stUp->bindValue(':lt',  (int)$lt,     PDO::PARAM_INT);
        $stUp->bindValue(':st',  (int)$next,   PDO::PARAM_INT);
        $stUp->bindValue(':uid', (int)$userId, PDO::PARAM_INT);
        $stUp->execute();
    }

    if (function_exists('__api_log')) {
        __api_log([
            'phase' => 'CAL_UPDATE_OK',
            'mode'  => $hasStatus ? 'set' : 'toggle',
            'the_date' => $theDate,
            'request_locale_type' => $localeType,
            'updated_locales' => $targetLocales,
            'before' => $before,
            'status' => $next,
            'updated_by_user_id' => (int)$userId
        ]);
    }

    $pdo->commit();
    json_out(200, ['ok' => true, 'status' => $next]);

} catch (Throwable $e) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    if (function_exists('__api_log')) {
        __api_log(['phase' => 'CAL_UPDATE_EXCEPTION', 'error' => $e->getMessage()]);
    }
    json_out(500, ['ok' => false, 'message' => 'サーバーエラーが発生しました', 'error' => $e->getMessage()]);
}

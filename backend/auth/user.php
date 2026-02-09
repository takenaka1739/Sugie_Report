<?php
/**
 * GET /auth/user.php
 * 現在ログインしているユーザー情報を返す（未ログインなら 401）
 * ※ 有給の自動付与・リセット処理は行わない
 */

require_once dirname(__DIR__, 1) . '/common/cors.php';
require_once dirname(__DIR__, 1) . '/common/db_manager.php';

const DEBUG_AUTH    = true;
const AUTH_LOG_DIR  = __DIR__ . '/../logs';
const AUTH_LOG_FILE = AUTH_LOG_DIR . '/auth.log';
const SESSION_NAME  = 'REPORTSESSID';

function auth_log($msg, array $ctx = []) {
    if (!DEBUG_AUTH) return;
    try {
        if (!is_dir(AUTH_LOG_DIR)) @mkdir(AUTH_LOG_DIR, 0777, true);
        if (isset($ctx['password'])) unset($ctx['password']);
        @file_put_contents(
            AUTH_LOG_FILE,
            '['.date('Y-m-d H:i:s').'] '.$msg.(empty($ctx)?'':' '.json_encode($ctx,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)).PHP_EOL,
            FILE_APPEND
        );
    } catch (\Throwable $e) {}
}
function json_out(int $code, array $payload) {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
    exit;
}

/** ===== CORS ===== */
$reqOrigin = $_SERVER['HTTP_ORIGIN'] ?? '';
$ALLOWED = [
    // 本番（www あり/なし）
    'http://www.sugie-k.com',
    'http://sugie-k.com',
    // ローカル
    'http://localhost:3000',
    'http://127.0.0.1:3000',
    'http://localhost',
    'http://127.0.0.1',
];
if ($reqOrigin && in_array($reqOrigin, $ALLOWED, true)) {
    header('Access-Control-Allow-Origin: '.$reqOrigin);
} else {
    // ★ デフォルトは本番ドメインに変更（localhost 固定だと不一致になる）
    header('Access-Control-Allow-Origin: http://www.sugie-k.com');
}
header('Access-Control-Allow-Credentials: true');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Cache-Control, Pragma');
header('Access-Control-Max-Age: 600');
header('Vary: Origin');

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
    auth_log('user.php preflight OPTIONS', ['origin'=>$reqOrigin, 'headers'=>$_SERVER['HTTP_ACCESS_CONTROL_REQUEST_HEADERS'] ?? '']);
    http_response_code(204);
    exit;
}

/** ===== セッション ===== */
if (session_status() === PHP_SESSION_NONE) {
    session_name(SESSION_NAME);
    $cookieParams = session_get_cookie_params();
    $newCookieParams = [
        'lifetime' => 0,
        'path'     => '/report', // ★ 小文字に統一（本番配下に合わせる）
        'domain'   => $cookieParams['domain'] ?? '',
        'secure'   => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on'),
        'httponly' => true,
        'samesite' => 'Lax',
    ];
    if (PHP_VERSION_ID >= 70300) {
        session_set_cookie_params($newCookieParams);
    } else {
        session_set_cookie_params(
            $newCookieParams['lifetime'],
            $newCookieParams['path'],
            $newCookieParams['domain'],
            $newCookieParams['secure'],
            $newCookieParams['httponly']
        );
    }
    session_start();
}

auth_log('user.php request', [
    'origin'       => $reqOrigin,
    'cookie'       => $_SERVER['HTTP_COOKIE'] ?? '(none)',
    'session_name' => session_name(),
    'session_id'   => session_id(),
    'has_auth'     => isset($_SESSION['auth']),
]);

$auth = $_SESSION['auth'] ?? null;
if (!$auth || empty($auth['id'])) {
    auth_log('user.php 401 not logged in');
    json_out(401, ['ok'=>false, 'message'=>'未ログインです']);
}

$userId = (int)$auth['id'];
$sessionRole = (string)($auth['role'] ?? '');

$isAuthorized = false;
$paidHolidays = 0;
$name = (string)($auth['name'] ?? '');

try {
    $dbh = getDb();
    $stmt = $dbh->prepare(
        'SELECT id, name, is_authorized, paid_holidays_num, nationality_id
           FROM m_users
          WHERE id = :id
          LIMIT 1'
    );
    $stmt->bindValue(':id', $userId, PDO::PARAM_INT);
    $stmt->execute();
    if ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $name         = (string)$row['name'];
        $isAuthorized = !empty($row['is_authorized']);
        $paidHolidays = (int)($row['paid_holidays_num'] ?? 0);
        $nationality  = (int)($row['nationality_id'] ?? 1);
    } else {
        $isAuthorized = (strtolower($sessionRole) === 'admin');
        $nationality  = 1;
    }
} catch (PDOException $e) {
    $isAuthorized = (strtolower($sessionRole) === 'admin');
    $nationality  = 1;
}

auth_log('user.php 200 ok', [
    'user_id'        => $userId,
    'role'           => $sessionRole,
    'is_authorized'  => $isAuthorized,
    'paid_holidays'  => $paidHolidays,
]);

json_out(200, [
    'ok'   => true,
    'user' => [
        'id'                 => $userId,
        'name'               => $name,
        'role'               => $sessionRole,
        'is_authorized'      => $isAuthorized,
        'paid_holidays_num'  => $paidHolidays,
        'nationality_id'     => $nationality,
    ],
]);

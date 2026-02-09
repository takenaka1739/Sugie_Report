<?php
/**
 * POST /auth/login.php
 * Body(JSON): { "username": "admin", "password": "****" }
 * 200: { ok:true, user:{ id, name, role } } / 401: { ok:false, message }
 *
 * ログ: backend/logs/auth.log, backend/logs/php_error.log
 */
require_once dirname(__DIR__, 1) . '/common/cors.php';
require_once dirname(__DIR__, 1) . '/common/db_manager.php';

const DEBUG_AUTH    = true;
const AUTH_LOG_DIR  = __DIR__ . '/../logs';
const AUTH_LOG_FILE = AUTH_LOG_DIR . '/auth.log';
const SESSION_NAME  = 'REPORTSESSID';

/* ========== 追加: 500の正体を必ず掴むための堅牢化 ========== */
@ini_set('display_errors', '0');
@ini_set('log_errors', '1');
if (!is_dir(AUTH_LOG_DIR)) { @mkdir(AUTH_LOG_DIR, 0777, true); }
@ini_set('error_log', AUTH_LOG_DIR . '/php_error.log'); // ← PHPエラーはここに出す

// 何があっても最終的に JSON を返す“最後の砦”
function _fatal_json_out(array $info) {
  // 既に何か出ていても、極力クリーンにしてJSONを返す
  while (ob_get_level()) { @ob_end_clean(); }
  if (!headers_sent()) {
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
  }
  echo json_encode(
    ['ok'=>false,'message'=>'サーバーエラーが発生しました','error'=>$info],
    JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES
  );
}

// エラー/致命的エラー捕捉
set_error_handler(function($severity, $message, $file, $line) {
  @file_put_contents(AUTH_LOG_FILE, '['.date('Y-m-d H:i:s')."] PHP Error: {$message} in {$file}:{$line}\n", FILE_APPEND);
  return false; // 既定のハンドラにも渡す
});
register_shutdown_function(function() {
  $e = error_get_last();
  if ($e && in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
    @file_put_contents(AUTH_LOG_FILE, '['.date('Y-m-d H:i:s')."] Fatal: {$e['message']} in {$e['file']}:{$e['line']}\n", FILE_APPEND);
    _fatal_json_out(['type'=>'fatal','msg'=>$e['message'],'file'=>$e['file'],'line'=>$e['line']]);
  }
});

// JSON出力ユーティリティ（出力前にバッファを必ずクリア）
function json_out(int $code, array $payload) {
  while (ob_get_level()) { @ob_end_clean(); }
  http_response_code($code);
  header('Content-Type: application/json; charset=utf-8');
  echo json_encode($payload, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
  // PHP-FPM/Apacheの環境差を吸収して即時返却
  if (function_exists('fastcgi_finish_request')) { @fastcgi_finish_request(); }
  exit;
}

function auth_log($msg, array $ctx = []) {
  if (!DEBUG_AUTH) return;
  try {
    if (!is_dir(AUTH_LOG_DIR)) @mkdir(AUTH_LOG_DIR, 0777, true);
    if (isset($ctx['password'])) unset($ctx['password']);
    @file_put_contents(
      AUTH_LOG_FILE,
      '[' . date('Y-m-d H:i:s') . '] ' . $msg
      . (empty($ctx) ? '' : ' ' . json_encode($ctx, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES))
      . PHP_EOL,
      FILE_APPEND
    );
  } catch (\Throwable $e) {}
}

/** ==== PDO ==== */
function resolve_pdo(): PDO {
  if (function_exists('get_pdo')) { $pdo = get_pdo(); if ($pdo instanceof PDO) return $pdo; }
  foreach (['getPDO','get_db','getDb','db','pdo'] as $fn) { if (function_exists($fn)) { $pdo = $fn(); if ($pdo instanceof PDO) return $pdo; } }
  if (isset($GLOBALS['pdo']) && $GLOBALS['pdo'] instanceof PDO) return $GLOBALS['pdo'];

  $host = defined('DB_HOST') ? DB_HOST : '127.0.0.1';
  $name = defined('DB_NAME') ? DB_NAME : 'Report';
  $user = defined('DB_USER') ? DB_USER : 'root';
  $pass = defined('DB_PASSWORD') ? DB_PASSWORD : '';
  $dsn  = defined('DSN') ? DSN : ("mysql:host={$host};dbname={$name};charset=utf8mb4");

  auth_log('pdo connect try', ['host'=>$host,'db'=>$name,'user'=>$user]);
  return new PDO($dsn, $user, $pass, [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
  ]);
}

/** ==== ハッシュ検証 ==== */
function detect_hash_info(string $stored): array {
  $info = @password_get_info($stored);
  $algoName = $info['algoName'] ?? null;
  $type = $algoName ? ('password_hash:' . $algoName) : 'unknown';
  if ($type === 'unknown') {
    $hex = ctype_xdigit($stored);
    if ($hex && strlen($stored) === 32) $type = 'md5';
    elseif ($hex && strlen($stored) === 40) $type = 'sha1';
    elseif ($stored === '') $type = 'empty';
  }
  return ['type'=>$type,'len'=>strlen($stored),'prefix'=>substr($stored,0,10)];
}
function verify_password_compat(string $plain, string $stored): bool {
  if (@password_verify($plain, $stored)) { auth_log('password_verify', ['algo'=>'password_hash','result'=>'OK']); return true; }
  $hex = ctype_xdigit($stored);
  if ($hex && strlen($stored) === 32) { $ok = (hash('md5', $plain) === strtolower($stored));  auth_log('password_verify', ['algo'=>'md5','result'=>$ok?'OK':'NG']);  return $ok; }
  if ($hex && strlen($stored) === 40) { $ok = (hash('sha1',$plain) === strtolower($stored));  auth_log('password_verify', ['algo'=>'sha1','result'=>$ok?'OK':'NG']); return $ok; }
  auth_log('password_verify', ['algo'=>'unknown','result'=>'NG','sample'=>substr($stored,0,10)]);
  return false;
}

try {
  /* ==== セッション ==== */
  if (session_status() === PHP_SESSION_NONE) {
    session_name(SESSION_NAME);
    $cookieParams = session_get_cookie_params();
    $newCookieParams = [
      'lifetime' => 0,
      'path'     => '/report',   // 小文字に統一
      'domain'   => $cookieParams['domain'] ?? '',
      'secure'   => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on'),
      'httponly' => true,
      'samesite' => 'Lax',
    ];
    if (PHP_VERSION_ID >= 70300) session_set_cookie_params($newCookieParams);
    else session_set_cookie_params($newCookieParams['lifetime'],$newCookieParams['path'],$newCookieParams['domain'],$newCookieParams['secure'],$newCookieParams['httponly']);
    session_start();
  }

  /* ==== 入力 ==== */
  $raw = file_get_contents('php://input') ?: '';
  $data = json_decode($raw, true) ?? [];
  $username = isset($data['username']) ? trim((string)$data['username']) : '';
  $password = isset($data['password']) ? (string)$data['password'] : '';

  auth_log('login request received', [
    'username'     => $username,
    'has_password' => $password !== '',
    'session_id'   => session_id(),
  ]);

  if ($username === '' || $password === '') {
    auth_log('validation error: username/password empty');
    json_out(400, ['ok' => false, 'message' => 'username と password は必須です']);
  }

  /* ==== 検索 ==== */
  $pdo = resolve_pdo();
  $row = null;
  $usedKey = null;

  $stmt = $pdo->prepare('SELECT id, name, password, is_authorized FROM m_users WHERE name = :nm LIMIT 1');
  $stmt->bindValue(':nm', $username, PDO::PARAM_STR);
  $stmt->execute();
  $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
  if ($row) $usedKey = 'name';

  if (!$row && ctype_digit($username)) {
    $stmt = $pdo->prepare('SELECT id, name, password, is_authorized FROM m_users WHERE id = :id LIMIT 1');
    $stmt->bindValue(':id', (int)$username, PDO::PARAM_INT);
    $stmt->execute();
    $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    if ($row) $usedKey = 'id';
  }

  auth_log('db query result', [
    'found'     => (bool)$row,
    'used_key'  => $usedKey,
    'row_id'    => $row['id'] ?? null,
    'row_name'  => $row['name'] ?? null,
    'is_auth'   => isset($row['is_authorized']) ? (int)$row['is_authorized'] : null,
  ]);

  if (!$row) {
    $_SESSION = []; session_write_close();
    json_out(401, ['ok' => false, 'message' => 'ユーザー名またはパスワードが間違っています']);
  }

  /* ==== パスワード検証 ==== */
  $stored = (string)($row['password'] ?? '');
  $hashInfo = detect_hash_info($stored);
  auth_log('password hash info', $hashInfo);

  if ($stored === '' || !verify_password_compat($password, $stored)) {
    $_SESSION = []; session_write_close();
    auth_log('login failed: password mismatch', ['user_id'=>$row['id'] ?? null]);
    json_out(401, ['ok' => false, 'message' => 'ユーザー名またはパスワードが間違っています']);
  }

  $role = ((int)($row['is_authorized'] ?? 0)) ? 'admin' : 'user';

  /* ==== セッション保存 ==== */
  $_SESSION['auth'] = [
    'id'          => (int)($row['id'] ?? 0),
    'name'        => (string)($row['name'] ?? $username),
    'role'        => $role,
    'logged_in_at'=> date('c'),
  ];
  session_write_close();

  auth_log('login success', [
    'user_id'      => (int)($row['id'] ?? 0),
    'role'         => $role,
    'session_name' => SESSION_NAME,
  ]);

  json_out(200, [
    'ok'   => true,
    'user' => [
      'id'   => (int)($row['id'] ?? 0),
      'name' => (string)($row['name'] ?? $username),
      'role' => $role,
    ],
  ]);
} catch (Throwable $e) {
  @file_put_contents(AUTH_LOG_FILE, '['.date('Y-m-d H:i:s')."] exception: ".$e->getMessage()."\n", FILE_APPEND);
  _fatal_json_out(['type'=>'exception','msg'=>$e->getMessage()]);
}

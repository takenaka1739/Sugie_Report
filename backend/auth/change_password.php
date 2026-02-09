<?php
/**
 * POST /auth/change_password.php  （セッション不要版）
 *
 * Body(JSON): {
 *   "target": "user_name_or_id",     // 必須：ユーザー名 または 数値ID
 *   "old_password": "******",        // 一般利用時は必須（本人確認）
 *   "new_password": "******",        // 必須：8文字以上推奨
 *   "force": true|false,             // 任意：管理者のみ使用可（old_password不要）
 *   "api_key": "xxxxx"               // 任意：管理者APIキー（force使用時は必須）
 * }
 *
 * 仕様:
 * - セッション/ログインは不要。
 * - 一般利用（api_key なし）:
 *     - target のユーザーを検索し、old_password が一致した場合のみ new_password へ更新。
 * - 管理者利用（api_key が一致）:
 *     - 任意ユーザーを変更可。force=true の場合は old_password 不要。
 * - セキュリティ:
 *     - 管理者APIキーはサーバ側で安全に保管（.envや別設定ファイル）し、このファイルの定数から参照。
 */

require_once dirname(__DIR__, 1) . '/common/cors.php';
require_once dirname(__DIR__, 1) . '/common/db_manager.php';

const DEBUG_AUTH    = true;
const AUTH_LOG_DIR  = __DIR__ . '/../logs';
const AUTH_LOG_FILE = AUTH_LOG_DIR . '/auth.log';

if (!defined('ADMIN_API_KEY')) {
  define('ADMIN_API_KEY', 'CHANGE_ME_ADMIN_API_KEY');
}

function auth_log($msg, array $ctx = []) {
  if (!DEBUG_AUTH) return;
  try {
    if (!is_dir(AUTH_LOG_DIR)) @mkdir(AUTH_LOG_DIR, 0777, true);
    foreach (['old_password','new_password','api_key'] as $k) { if (isset($ctx[$k])) unset($ctx[$k]); }
    @file_put_contents(
      AUTH_LOG_FILE,
      '[' . date('Y-m-d H:i:s') . '] ' . $msg
      . (empty($ctx) ? '' : ' ' . json_encode($ctx, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES))
      . PHP_EOL,
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

/** ==== PDO ==== */
function resolve_pdo(): PDO {
  if (function_exists('get_pdo')) { $pdo = get_pdo(); if ($pdo instanceof PDO) return $pdo; }
  foreach (['getPDO','get_db','getDb','db','pdo'] as $fn) { if (function_exists($fn)) { $pdo = $fn(); if ($pdo instanceof PDO) return $pdo; } }
  if (isset($GLOBALS['pdo']) && $GLOBALS['pdo'] instanceof PDO) return $GLOBALS['pdo'];

  $host = defined('DB_HOST') ? DB_HOST : '127.0.0.1';
  $name = defined('DB_NAME') ? DB_NAME : 'report';
  $user = defined('DB_USER') ? DB_USER : 'root';
  $pass = defined('DB_PASSWORD') ? DB_PASSWORD : '';
  $dsn  = defined('DB_DSN') ? DB_DSN : ("mysql:host={$host};dbname={$name};charset=utf8mb4");
  return new PDO($dsn, $user, $pass, [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
  ]);
}

/** ==== ハッシュ検証（login.php と互換）==== */
function detect_hash_info(string $stored): array {
  $info = @password_get_info($stored);
  $algoName = $info && isset($info['algoName']) ? $info['algoName'] : null;
  $type = 'unknown';
  if ($algoName) {
    $type = 'password_hash:' . $algoName;
  } else {
    $hex = ctype_xdigit($stored);
    if ($hex && strlen($stored) === 32) $type = 'md5';
    elseif ($hex && strlen($stored) === 40) $type = 'sha1';
    elseif ($stored === '') $type = 'empty';
  }
  return ['type' => $type, 'len' => strlen($stored), 'prefix' => substr($stored, 0, 10)];
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
  /** ===== CORS ===== */
  $reqOrigin = $_SERVER['HTTP_ORIGIN'] ?? '';
  $ALLOWED = [
    'http://localhost:3000',
    'http://127.0.0.1:3000',
    'http://localhost',
    'http://127.0.0.1',
  ];
  if ($reqOrigin && in_array($reqOrigin, $ALLOWED, true)) {
    header('Access-Control-Allow-Origin: ' . $reqOrigin);
  } else {
    header('Access-Control-Allow-Origin: http://localhost');
  }
  header('Access-Control-Allow-Credentials: true');
  header('Access-Control-Allow-Methods: POST, OPTIONS');
  header('Access-Control-Allow-Headers: Content-Type, Cache-Control, Pragma, X-Admin-Api-Key');
  header('Access-Control-Max-Age: 600');
  header('Vary: Origin');

  if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    auth_log('change_password preflight OPTIONS', [
      'origin'  => $reqOrigin,
      'req-hdr' => $_SERVER['HTTP_ACCESS_CONTROL_REQUEST_HEADERS'] ?? '',
    ]);
    http_response_code(204); exit;
  }

  /** ===== 入力 ===== */
  $raw  = file_get_contents('php://input') ?: '';
  $data = json_decode($raw, true) ?? [];
  $target       = isset($data['target']) ? trim((string)$data['target']) : '';
  $oldPassword  = isset($data['old_password']) ? (string)$data['old_password'] : '';
  $newPassword  = isset($data['new_password']) ? (string)$data['new_password'] : '';
  $force        = isset($data['force']) ? (bool)$data['force'] : false;
  $apiKeyBody   = isset($data['api_key']) ? (string)$data['api_key'] : '';
  $apiKeyHeader = isset($_SERVER['HTTP_X_ADMIN_API_KEY']) ? (string)$_SERVER['HTTP_X_ADMIN_API_KEY'] : '';

  // 管理者APIキーの判定（Body または Header）
  $isAdmin = false;
  if (ADMIN_API_KEY && ($apiKeyBody === ADMIN_API_KEY || $apiKeyHeader === ADMIN_API_KEY)) {
    $isAdmin = true;
  }

  auth_log('change_password PUBLIC request', [
    'origin'   => $reqOrigin,
    'target'   => $target,
    'force'    => $force,
    'is_admin' => $isAdmin,
    'has_old'  => $oldPassword !== '',
    'has_new'  => $newPassword !== '',
  ]);

  // バリデーション（共通）
  if ($target === '' || $newPassword === '') {
    json_out(400, ['ok'=>false, 'message'=>'target と new_password は必須です。']);
  }

  $pdo = resolve_pdo();

  // 対象ユーザー検索（name 優先 → 数値IDフォールバック）
  $row = null;
  $usedKey = null;
  $stmt = $pdo->prepare('SELECT id, name, password FROM m_users WHERE name = :nm LIMIT 1');
  $stmt->bindValue(':nm', $target, PDO::PARAM_STR);
  $stmt->execute();
  $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
  if ($row) $usedKey = 'name';

  if (!$row && ctype_digit($target)) {
    $stmt = $pdo->prepare('SELECT id, name, password FROM m_users WHERE id = :id LIMIT 1');
    $stmt->bindValue(':id', (int)$target, PDO::PARAM_INT);
    $stmt->execute();
    $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    if ($row) $usedKey = 'id';
  }

  if (!$row) {
    json_out(404, ['ok'=>false, 'message'=>'対象ユーザーが見つかりません。']);
  }

  $targetId   = (int)$row['id'];
  $targetName = (string)$row['name'];
  $storedHash = (string)$row['password'];

  // 認可ロジック
  if ($isAdmin) {
    // 管理者: force=true なら old_password 省略OK。false の場合は old の一致を確認
    if (!$force) {
      if ($oldPassword === '' || !verify_password_compat($oldPassword, $storedHash)) {
        json_out(401, ['ok'=>false, 'message'=>'旧パスワードが一致しません。']);
      }
    }
  } else {
    // 一般: 必ず old_password が必要（本人確認）
    if ($oldPassword === '' || !verify_password_compat($oldPassword, $storedHash)) {
      json_out(401, ['ok'=>false, 'message'=>'旧パスワードが一致しません。']);
    }
  }

  // 新パスワードハッシュ化（bcrypt）
  $newHash = password_hash($newPassword, PASSWORD_DEFAULT);

  // 更新
  $upd = $pdo->prepare('UPDATE m_users SET password = :pwd, updated_at = NOW() WHERE id = :id');
  $upd->bindValue(':pwd', $newHash, PDO::PARAM_STR);
  $upd->bindValue(':id',  $targetId, PDO::PARAM_INT);
  $upd->execute();

  auth_log('change_password PUBLIC success', [
    'target_id'  => $targetId,
    'target_nm'  => $targetName,
    'used_key'   => $usedKey,
    'by_admin'   => $isAdmin,
    'force_used' => $isAdmin && $force,
  ]);

  json_out(200, [
    'ok' => true,
    'message' => 'パスワードを変更しました。'
  ]);

} catch (Throwable $e) {
  auth_log('change_password PUBLIC exception', ['error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
  json_out(500, ['ok'=>false, 'message'=>'サーバーエラーが発生しました。', 'error'=>$e->getMessage()]);
}

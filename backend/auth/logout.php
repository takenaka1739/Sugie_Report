<?php
/**
 * POST /auth/logout.php
 * セッションを破棄してログアウトします
 * Response(JSON): { "ok": true }
 *
 * 対応:
 *  - セッション名 REPORTSESSID を明示
 *  - cookie を複数 path かつ domain 有無の両方で「全消し」
 *  - CORS credentials 許可
 *  - サーバ側セッションファイルも削除
 *  - no-store ヘッダ付与
 */

require_once dirname(__DIR__, 1) . '/common/db_manager.php';

const SESSION_NAME  = 'REPORTSESSID';
const DEBUG_AUTH    = true;
const AUTH_LOG_DIR  = __DIR__ . '/../logs';
const AUTH_LOG_FILE = AUTH_LOG_DIR . '/auth.log';

function auth_log($msg, array $ctx = []) {
  if (!DEBUG_AUTH) return;
  try {
    if (!is_dir(AUTH_LOG_DIR)) @mkdir(AUTH_LOG_DIR, 0777, true);
    @file_put_contents(
      AUTH_LOG_FILE,
      '[' . date('Y-m-d H:i:s') . '] ' . $msg
      . (empty($ctx) ? '' : ' ' . json_encode($ctx, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES))
      . PHP_EOL,
      FILE_APPEND
    );
  } catch (\Throwable $e) {}
}

/** 指定 path と domain で Cookie を失効させる（domain=null なら Domain 属性を付けない） */
function expire_cookie(string $name, string $path, ?string $domain, bool $secure, string $samesite = 'Lax'): void {
  $opts = [
    'expires'  => time() - 42000,
    'path'     => $path,
    'secure'   => $secure,
    'httponly' => true,
    'samesite' => $samesite,
  ];
  if ($domain !== null && $domain !== '') $opts['domain'] = $domain;
  setcookie($name, '', $opts);

  // 明示ヘッダ（保険）
  $hdr = sprintf(
    'Set-Cookie: %s=deleted; Expires=%s; Max-Age=0; Path=%s; HttpOnly; SameSite=%s',
    $name, gmdate('D, d M Y H:i:s T', time() - 3600), $path, $samesite
  );
  if ($domain !== null && $domain !== '') $hdr .= '; Domain=' . $domain;
  if ($secure) $hdr .= '; Secure';
  header($hdr, false);
}

try {
  // CORS
  header('Access-Control-Allow-Origin: ' . ORIGIN);      // 例: http://localhost:3000
  header('Access-Control-Allow-Headers: Content-Type');
  header('Access-Control-Allow-Methods: POST, OPTIONS');
  header('Access-Control-Allow-Credentials: true');
  header('Content-Type: application/json; charset=utf-8');
  header('Cache-Control: no-store, no-cache, must-revalidate');
  header('Pragma: no-cache');
  header('Expires: Thu, 19 Nov 1981 08:52:00 GMT');
  header('Vary: Origin');

  if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
  }

  // 既存セッションを開く（同名必須）
  session_name(SESSION_NAME);
  if (session_status() === PHP_SESSION_NONE) session_start();

  $sid = session_id();
  auth_log('logout request', [
    'session_name' => session_name(),
    'session_id'   => $sid,
    'cookie'       => $_SERVER['HTTP_COOKIE'] ?? '(none)',
  ]);

  // セッション変数を空に & 破棄
  $_SESSION = [];
  session_destroy();

  // サーバ側のセッションファイルも削除
  $savePath = session_save_path();
  if ($savePath === '' || $savePath === null) $savePath = ini_get('session.save_path');
  if ($savePath) {
    $file = rtrim($savePath, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'sess_' . $sid;
    if (is_file($file)) @unlink($file);
  }

  // あり得る path すべてを失効（過去に '/' で発行されていたケースも潰す）
  $secure = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on';
  $paths  = ['/Report/backend/', '/Report/backend', '/Report/', '/Report', '/'];
  // domain 無し（Host-only cookie）と、localhost 指定の両方で消す
  foreach ($paths as $p) {
    expire_cookie(SESSION_NAME, $p, null,       $secure, 'Lax');
    expire_cookie(SESSION_NAME, $p, 'localhost',$secure, 'Lax');
  }

  auth_log('logout success', ['deleted_paths' => $paths]);

  echo json_encode(['ok' => true]);
} catch (Throwable $e) {
  auth_log('logout exception', ['error' => $e->getMessage()]);
  http_response_code(500);
  echo json_encode(['ok' => false, 'message' => 'サーバーエラーが発生しました', 'error' => $e->getMessage()]);
}

<?php

if (PHP_SAPI !== 'cli') {
    @ini_set('display_errors', '0');              // 画面出力OFF
    @ini_set('html_errors', '0');
    // ログは残しつつ画面には出さない
    @error_reporting(E_ALL & ~E_WARNING & ~E_NOTICE & ~E_DEPRECATED);
}

// ===== CORS ORIGIN を一元ガード付きで定義（重複定義を防止）=====
if (!defined('ORIGIN')) {
    $reqOrigin = $_SERVER['HTTP_ORIGIN'] ?? '';
    $ALLOWED_ORIGINS = [
        'http://localhost:3000',
        'http://127.0.0.1:3000',
        // ローカル(Vite) ※必要なければ削ってOK
        'http://localhost:5173',
        'http://127.0.0.1:5173',
        'http://localhost',
        'http://127.0.0.1',
        // 本番（パスは含めない）
        'http://www.sugie-k.com',
        'https://www.sugie-k.com',
    ];
    if ($reqOrigin !== '' && in_array($reqOrigin, $ALLOWED_ORIGINS, true)) {
        define('ORIGIN', $reqOrigin);
    } else {
        // 同一オリジン配信や未知のOriginの場合のフォールバック
        define('ORIGIN', 'http://localhost');
    }
}

/*
define('DB_HOST', '127.0.0.1');
define('DB_PORT', '3306');
define('DB_NAME', 'sugie_report');
define('DB_CHARSET', 'utf8mb4');
define('DB_USER', 'root');
define('DB_PASSWORD', '');
define('ORIGIN', 'http://localhost:3000');



 // テスト環境 (自社)
 define('DB_HOST', 'mysql57.justem-soft.sakura.ne.jp');      // ホスト名
 define('DB_PORT', '3306');                                  // ポート番号
 define('DB_NAME', 'sugie_report');                          // データベース名
 define('DB_CHARSET', 'UTF8');                               // 文字コード
 define('DB_USER', 'justem-soft');                           // ユーザー名
 define('DB_PASSWORD', 'kX22b_6t3');                         // パスワード
 define('ORIGIN', 'http://localhost:3000');                  // CORSオリジン
*/

/**
 * ===== ここから下は“自動切替”のみ追加（構造は維持） =====
 * 優先順位:
 *   1) 環境変数 REPORT_ENV=prod|local （最優先）
 *   2) HTTP_HOST に sugie-k.com を含むなら prod と判定
 *   3) それ以外は local
 */
$__env = getenv('REPORT_ENV'); // 'prod' | 'local' | null
if (is_string($__env)) { $__env = strtolower(trim($__env)); }
$__forceLocal = ($__env === 'local');
$__forceProd  = ($__env === 'prod');
$__host       = $_SERVER['HTTP_HOST'] ?? '';

$__isProd = $__forceProd || (!$__forceLocal && (stripos($__host, 'sugie-k.com') !== false));

if ($__isProd) {
    // 本番環境
    define('DB_HOST', 'mysql8003.in.shared-server.net');        // ホスト名
    define('DB_PORT', '11101');                                 // ポート番号
    define('DB_NAME', '44Gi_sugie_report');                     // データベース名
    define('DB_CHARSET', 'utf8mb4');                            // 文字コード（統一）
    define('DB_USER', 'NKTFKpFHfG980');                         // ユーザー名
    define('DB_PASSWORD', 'FDM3SNdLbJNh');                      // パスワード
    // define('ORIGIN', 'http://www.sugie-k.com/report');       // ← 上で定義済みのため再定義しない（Originはパス無しが原則）
} else {
    // ローカル環境
    define('DB_HOST', '127.0.0.1');
    define('DB_PORT', '3306');
    define('DB_NAME', 'sugie_report');
    define('DB_CHARSET', 'utf8mb4');
    define('DB_USER', 'root');
    define('DB_PASSWORD', '');
    // define('ORIGIN', 'http://localhost:3000');
}

define(
    'DSN',
    'mysql:host=' . DB_HOST .
    ';port=' . DB_PORT .
    ';dbname=' . DB_NAME .
    ';charset=' . DB_CHARSET
);

function getDb() : PDO
{
    // 文字化け対策 + 推奨オプション
    $options = [
        PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES 'utf8mb4'",
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ];

    // データベース接続
    return new PDO(DSN, DB_USER, DB_PASSWORD, $options);
}

/**
 * 互換用: このファイルを require しただけで $dbh が使えるようにしておく
 * （既存のスクリプトが $dbh を前提にしているため）
 */
if (!isset($dbh) || !($dbh instanceof PDO)) {
    $dbh = getDb();
}

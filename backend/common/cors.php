<?php
/**
 * 共通CORS + グローバルエラーハンドラ + 詳細ログ
 * - 既存のCORS仕様を維持しつつ、500の原因箇所を特定しやすくする
 * - 例外/警告/致命的エラーをキャッチして JSON で500を返し、専用ログに書き出す
 * - セッションの有無、呼び出し元URI/メソッド、リクエスト本文先頭なども記録
 */

declare(strict_types=1);

// ===== ログ出力先 =====
$__LOG_DIR = dirname(__DIR__, 1) . '/_logs';
if (!is_dir($__LOG_DIR)) {
    @mkdir($__LOG_DIR, 0775, true);
}
$__LOG_FILE = $__LOG_DIR . '/api_' . date('Ymd') . '.log';

// PHPエラーログ（Apacheのerror_logとは別に個別保存）
@ini_set('log_errors', '1');
@ini_set('error_log', $__LOG_FILE);

// ====== 便利関数 ======
function __api_log(array $data): void {
    global $__LOG_FILE;
    $line = sprintf(
        "[%s] %s\n",
        date('Y-m-d H:i:s'),
        json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
    );
    @file_put_contents($__LOG_FILE, $line, FILE_APPEND);
}

function __json_error(int $code, string $message, array $context = []): void {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    $payload = [
        'success' => false,
        'error'   => [
            'code'    => $code,
            'message' => $message,
        ],
    ];
    if (!empty($context)) {
        // 具体的なファイル行やスタックはログのみに出し、レスポンスには最小限
        $payload['hint'] = $context['hint'] ?? null;
    }
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
}

// ===== リクエスト基本情報を先に記録 =====
$__REQ_URI    = $_SERVER['REQUEST_URI'] ?? '';
$__REQ_METHOD = $_SERVER['REQUEST_METHOD'] ?? '';
$__ORIGIN     = $_SERVER['HTTP_ORIGIN'] ?? '';
$__RAW        = '';
// 大きなボディでもログが肥大しないよう、先頭512Bだけ記録
if (in_array($__REQ_METHOD, ['POST','PUT','PATCH'], true)) {
    $raw = @file_get_contents('php://input');
    $__RAW = mb_substr((string)$raw, 0, 512);
}
__api_log([
    'phase'   => 'REQUEST_BEGIN',
    'uri'     => $__REQ_URI,
    'method'  => $__REQ_METHOD,
    'origin'  => $__ORIGIN,
    'body512' => $__RAW,
]);

// ===== CORS（既存仕様に合わせる） =====
// 既存でORIGIN定数を使っている場合に備えてフォールバックを用意
$ALLOWED = [
    'http://localhost:3000',
    'http://127.0.0.1:3000',
    'http://localhost:5173',
    'http://127.0.0.1:5173',
    'http://localhost',
    'http://127.0.0.1',
    // 本番オリジンがある場合はここに追記してください
    // 例: 'https://www.sugie-k.com'
];
$allowedOrigin = $__ORIGIN && in_array($__ORIGIN, $ALLOWED, true)
    ? $__ORIGIN
    : ($ALLOWED[0] ?? '*');

header('Access-Control-Allow-Origin: ' . $allowedOrigin);
header('Access-Control-Allow-Credentials: true');
header('Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Cache-Control, X-Requested-With');

// プリフライトはここで終了
if ($__REQ_METHOD === 'OPTIONS') {
    __api_log(['phase' => 'OPTIONS_OK', 'uri' => $__REQ_URI]);
    exit;
}

// ===== セッション（ログイン後の落ち箇所特定に重要） =====
if (session_status() !== PHP_SESSION_ACTIVE) {
    // プロジェクトで固定しているセッション名があれば合わせる
    if (!empty($_COOKIE['REPORTSESSID'])) {
        session_name('REPORTSESSID');
    }
    @session_start();
}
__api_log([
    'phase'          => 'SESSION',
    'session_name'   => session_name(),
    'session_id_len' => strlen(session_id()),
    'session_keys'   => array_keys($_SESSION ?? []),
]);

// ===== エラーハンドラ設定 =====
// PHPのWarning/Noticeも例外化
set_error_handler(function (int $severity, string $message, string $file, int $line): bool {
    // error_reporting() に含まれないものは無視（@抑制など）
    if (!(error_reporting() & $severity)) {
        return false;
    }
    throw new ErrorException($message, 0, $severity, $file, $line);
});

// 未処理例外を一本化
set_exception_handler(function (Throwable $e): void {
    __api_log([
        'phase'   => 'UNCAUGHT_EXCEPTION',
        'type'    => get_class($e),
        'message' => $e->getMessage(),
        'file'    => $e->getFile(),
        'line'    => $e->getLine(),
        // スタックはログのみ（サイズ肥大を避けるため先頭数行）
        'trace'   => explode("\n", $e->getTraceAsString())[0] ?? '',
    ]);
    __json_error(500, 'Internal Server Error', ['hint' => 'See server log api_YYYYMMDD.log']);
    exit;
});

// 致命的エラーも拾う
register_shutdown_function(function (): void {
    $err = error_get_last();
    if ($err && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        __api_log([
            'phase'   => 'FATAL',
            'type'    => $err['type'],
            'message' => $err['message'] ?? '',
            'file'    => $err['file'] ?? '',
            'line'    => $err['line'] ?? 0,
        ]);
        // まだヘッダーが送出されていなければJSONで返す
        if (!headers_sent()) {
            __json_error(500, 'Internal Server Error', ['hint' => 'Fatal error occurred. See api_YYYYMMDD.log']);
        }
    } else {
        // 正常終了のフッターログ（必要なら）
        __api_log(['phase' => 'REQUEST_END_OK']);
    }
});

// ここまで：このファイルを読み込んだ各APIの本処理は try/catch しなくても
// 上記のハンドラで500→ログ化→JSON化される。
// 個別APIで意図的にエラー応答を返したい場合は __json_error() を使ってください。

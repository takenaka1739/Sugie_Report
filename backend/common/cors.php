<?php
/**
 * Shared CORS, error handling, and API error logging.
 * This file should log only error conditions, not successful requests.
 */

declare(strict_types=1);

$__LOG_DIR = dirname(__DIR__, 1) . '/_logs';
if (!is_dir($__LOG_DIR)) {
    @mkdir($__LOG_DIR, 0775, true);
}
$__LOG_FILE = $__LOG_DIR . '/api_' . date('Ymd') . '.log';

@ini_set('log_errors', '1');
@ini_set('error_log', $__LOG_FILE);

function __api_log(array $data): void
{
    global $__LOG_FILE;

    $line = sprintf(
        "[%s] %s\n",
        date('Y-m-d H:i:s'),
        json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
    );

    @file_put_contents($__LOG_FILE, $line, FILE_APPEND);
}

function __json_error(int $code, string $message, array $context = []): void
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');

    $payload = [
        'success' => false,
        'error' => [
            'code' => $code,
            'message' => $message,
        ],
    ];

    if (!empty($context) && array_key_exists('hint', $context)) {
        $payload['hint'] = $context['hint'];
    }

    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
}

$__REQ_URI = $_SERVER['REQUEST_URI'] ?? '';
$__REQ_METHOD = $_SERVER['REQUEST_METHOD'] ?? '';
$__ORIGIN = $_SERVER['HTTP_ORIGIN'] ?? '';

$ALLOWED = [
    'http://localhost:3000',
    'http://127.0.0.1:3000',
    'http://localhost:5173',
    'http://127.0.0.1:5173',
    'http://localhost',
    'http://127.0.0.1',
];

$allowedOrigin = $__ORIGIN && in_array($__ORIGIN, $ALLOWED, true)
    ? $__ORIGIN
    : ($ALLOWED[0] ?? '*');

header('Access-Control-Allow-Origin: ' . $allowedOrigin);
header('Access-Control-Allow-Credentials: true');
header('Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Cache-Control, X-Requested-With');

if ($__REQ_METHOD === 'OPTIONS') {
    exit;
}

if (session_status() !== PHP_SESSION_ACTIVE) {
    if (!empty($_COOKIE['REPORTSESSID'])) {
        session_name('REPORTSESSID');
    }
    @session_start();
}

set_error_handler(function (int $severity, string $message, string $file, int $line): bool {
    if (!(error_reporting() & $severity)) {
        return false;
    }

    throw new ErrorException($message, 0, $severity, $file, $line);
});

set_exception_handler(function (Throwable $e) use ($__REQ_URI, $__REQ_METHOD, $__ORIGIN): void {
    __api_log([
        'phase' => 'UNCAUGHT_EXCEPTION',
        'uri' => $__REQ_URI,
        'method' => $__REQ_METHOD,
        'origin' => $__ORIGIN,
        'type' => get_class($e),
        'message' => $e->getMessage(),
        'file' => $e->getFile(),
        'line' => $e->getLine(),
        'trace' => explode("\n", $e->getTraceAsString())[0] ?? '',
    ]);

    __json_error(500, 'Internal Server Error', ['hint' => 'See server log api_YYYYMMDD.log']);
    exit;
});

register_shutdown_function(function () use ($__REQ_URI, $__REQ_METHOD, $__ORIGIN): void {
    $err = error_get_last();
    if (!$err || !in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        return;
    }

    __api_log([
        'phase' => 'FATAL',
        'uri' => $__REQ_URI,
        'method' => $__REQ_METHOD,
        'origin' => $__ORIGIN,
        'type' => $err['type'],
        'message' => $err['message'] ?? '',
        'file' => $err['file'] ?? '',
        'line' => $err['line'] ?? 0,
    ]);

    if (!headers_sent()) {
        __json_error(500, 'Internal Server Error', ['hint' => 'Fatal error occurred. See api_YYYYMMDD.log']);
    }
});

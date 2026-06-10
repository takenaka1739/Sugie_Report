<?php
/**
 * Shared session settings for the Report app.
 */

declare(strict_types=1);

function report_session_name(): string
{
    return 'REPORTSESSID';
}

function report_session_lifetime(): int
{
    return 86400 * 30;
}

function report_session_is_secure(): bool
{
    return (
        (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ||
        (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https')
    );
}

function report_session_cookie_params(): array
{
    return [
        'lifetime' => report_session_lifetime(),
        'path' => '/',
        'domain' => '',
        'secure' => report_session_is_secure(),
        'httponly' => true,
        'samesite' => 'Lax',
    ];
}

function report_start_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    session_name(report_session_name());
    @ini_set('session.gc_maxlifetime', (string)report_session_lifetime());

    $params = report_session_cookie_params();
    if (PHP_VERSION_ID >= 70300) {
        session_set_cookie_params($params);
    } else {
        session_set_cookie_params(
            $params['lifetime'],
            $params['path'],
            $params['domain'],
            $params['secure'],
            $params['httponly']
        );
    }

    session_start();
}

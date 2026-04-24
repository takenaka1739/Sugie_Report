<?php

function getAppCryptoKey(): string
{
    static $key = null;

    if ($key !== null) {
        return $key;
    }

    $envKey = getenv('REPORT_SECRET');
    if (is_string($envKey) && trim($envKey) !== '') {
        $key = hash('sha256', $envKey, true);
        return $key;
    }

    $fallback = DB_HOST . '|' . DB_NAME . '|' . DB_USER . '|' . DB_PASSWORD;
    $key = hash('sha256', $fallback, true);
    return $key;
}

function encryptAppSecret(string $plainText): string
{
    if ($plainText === '') {
        return '';
    }

    $cipher = 'AES-256-CBC';
    $key = getAppCryptoKey();
    $ivLength = openssl_cipher_iv_length($cipher);
    $iv = random_bytes($ivLength);
    $cipherText = openssl_encrypt($plainText, $cipher, $key, OPENSSL_RAW_DATA, $iv);

    if ($cipherText === false) {
        throw new RuntimeException('encrypt failed');
    }

    $mac = hash_hmac('sha256', $iv . $cipherText, $key, true);
    return 'enc:' . base64_encode($iv . $mac . $cipherText);
}

function decryptAppSecret(?string $storedText): string
{
    if ($storedText === null || $storedText === '') {
        return '';
    }

    if (strncmp($storedText, 'enc:', 4) !== 0) {
        return $storedText;
    }

    $payload = base64_decode(substr($storedText, 4), true);
    if ($payload === false) {
        throw new RuntimeException('invalid encrypted payload');
    }

    $cipher = 'AES-256-CBC';
    $key = getAppCryptoKey();
    $ivLength = openssl_cipher_iv_length($cipher);
    $macLength = 32;

    if (strlen($payload) <= ($ivLength + $macLength)) {
        throw new RuntimeException('invalid encrypted payload length');
    }

    $iv = substr($payload, 0, $ivLength);
    $mac = substr($payload, $ivLength, $macLength);
    $cipherText = substr($payload, $ivLength + $macLength);
    $calcMac = hash_hmac('sha256', $iv . $cipherText, $key, true);

    if (!hash_equals($mac, $calcMac)) {
        throw new RuntimeException('invalid encrypted payload mac');
    }

    $plainText = openssl_decrypt($cipherText, $cipher, $key, OPENSSL_RAW_DATA, $iv);
    if ($plainText === false) {
        throw new RuntimeException('decrypt failed');
    }

    return $plainText;
}

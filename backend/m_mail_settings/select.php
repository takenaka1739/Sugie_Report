<?php
require_once dirname(__DIR__, 1) . '/common/cors.php';
require_once dirname(__DIR__, 1) . '/common/db_manager.php';
require_once dirname(__DIR__, 1) . '/common/crypto.php';

try {
    $dbh = getDb();

    $sql = "SELECT
                id,
                is_enabled,
                recipient_mail,
                cc_mail,
                sender_name,
                sender_mail,
                smtp_host,
                smtp_port,
                smtp_user,
                smtp_password,
                encryption_type,
                subject,
                body_header
            FROM m_mail_settings
            ORDER BY id ASC
            LIMIT 1";

    $stmt = $dbh->prepare($sql);
    $stmt->execute();
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($row) {
        $row['smtp_password'] = decryptAppSecret($row['smtp_password'] ?? '');
    }

    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($row ?: null, JSON_UNESCAPED_UNICODE);
} catch (PDOException $e) {
    header('Content-Type: application/json; charset=utf-8');
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Database error',
        'error' => $e->getMessage(),
    ], JSON_UNESCAPED_UNICODE);
} finally {
    $dbh = null;
}

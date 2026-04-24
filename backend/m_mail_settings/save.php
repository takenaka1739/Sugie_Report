<?php
require_once dirname(__DIR__, 1) . '/common/cors.php';
require_once dirname(__DIR__, 1) . '/common/db_manager.php';
require_once dirname(__DIR__, 1) . '/common/crypto.php';

try
{
    header('Access-Control-Allow-Origin: ' . ORIGIN);
    header('Access-Control-Allow-Credentials: true');
    header('Access-Control-Allow-Headers: Content-Type, Authorization');
    header('Access-Control-Allow-Methods: POST, OPTIONS');
    header('Content-Type: application/json; charset=utf-8');

    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
        http_response_code(204);
        exit;
    }

    $json = file_get_contents("php://input");
    $data = json_decode($json, true);

    if (!is_array($data)) {
        throw new RuntimeException('invalid request');
    }

    $recipient_mail = trim((string)($data['recipient_mail'] ?? ''));
    $sender_name = trim((string)($data['sender_name'] ?? ''));
    $sender_mail = trim((string)($data['sender_mail'] ?? ''));
    $smtp_host = trim((string)($data['smtp_host'] ?? ''));
    $smtp_user = trim((string)($data['smtp_user'] ?? ''));
    $smtp_password = (string)($data['smtp_password'] ?? '');
    $encrypted_smtp_password = encryptAppSecret($smtp_password);
    $subject = trim((string)($data['subject'] ?? ''));
    $smtp_port = (int)($data['smtp_port'] ?? 0);
    $encryption_type = trim((string)($data['encryption_type'] ?? 'tls'));
    $cc_mail = trim((string)($data['cc_mail'] ?? ''));
    $body_header = (string)($data['body_header'] ?? '');
    $is_enabled = !empty($data['is_enabled']) ? 1 : 0;

    if (
        $recipient_mail === '' ||
        $sender_name === '' ||
        $sender_mail === '' ||
        $smtp_host === '' ||
        $smtp_port <= 0 ||
        $smtp_user === '' ||
        $smtp_password === '' ||
        $subject === ''
    ) {
        http_response_code(422);
        echo json_encode([
            'success' => false,
            'message' => '必須項目を入力してください。',
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $dbh = getDb();
    $dbh->beginTransaction();

    $id = isset($data['id']) && $data['id'] !== null ? (int)$data['id'] : 0;

    if ($id > 0) {
        $checkStmt = $dbh->prepare("SELECT id FROM m_mail_settings WHERE id = :id");
        $checkStmt->bindValue(':id', $id, PDO::PARAM_INT);
        $checkStmt->execute();
        $exists = (bool)$checkStmt->fetchColumn();
    } else {
        $exists = false;
    }

    if (!$exists) {
        $idStmt = $dbh->query("SELECT COALESCE(MAX(id), 0) + 1 AS next_id FROM m_mail_settings");
        $id = (int)$idStmt->fetchColumn();

        $sql = "INSERT INTO m_mail_settings (
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
                    body_header,
                    created_at,
                    updated_at
                ) VALUES (
                    :id,
                    :is_enabled,
                    :recipient_mail,
                    :cc_mail,
                    :sender_name,
                    :sender_mail,
                    :smtp_host,
                    :smtp_port,
                    :smtp_user,
                    :smtp_password,
                    :encryption_type,
                    :subject,
                    :body_header,
                    NOW(),
                    NOW()
                )";
    } else {
        $sql = "UPDATE m_mail_settings
                SET is_enabled = :is_enabled,
                    recipient_mail = :recipient_mail,
                    cc_mail = :cc_mail,
                    sender_name = :sender_name,
                    sender_mail = :sender_mail,
                    smtp_host = :smtp_host,
                    smtp_port = :smtp_port,
                    smtp_user = :smtp_user,
                    smtp_password = :smtp_password,
                    encryption_type = :encryption_type,
                    subject = :subject,
                    body_header = :body_header,
                    updated_at = NOW()
                WHERE id = :id";
    }

    $stmt = $dbh->prepare($sql);
    $stmt->bindValue(':id', $id, PDO::PARAM_INT);
    $stmt->bindValue(':is_enabled', $is_enabled, PDO::PARAM_INT);
    $stmt->bindValue(':recipient_mail', $recipient_mail);
    if ($cc_mail !== '') {
        $stmt->bindValue(':cc_mail', $cc_mail, PDO::PARAM_STR);
    } else {
        $stmt->bindValue(':cc_mail', null, PDO::PARAM_NULL);
    }
    $stmt->bindValue(':sender_name', $sender_name);
    $stmt->bindValue(':sender_mail', $sender_mail);
    $stmt->bindValue(':smtp_host', $smtp_host);
    $stmt->bindValue(':smtp_port', $smtp_port, PDO::PARAM_INT);
    $stmt->bindValue(':smtp_user', $smtp_user);
    $stmt->bindValue(':smtp_password', $encrypted_smtp_password);
    $stmt->bindValue(':encryption_type', $encryption_type);
    $stmt->bindValue(':subject', $subject);
    if ($body_header !== '') {
        $stmt->bindValue(':body_header', $body_header, PDO::PARAM_STR);
    } else {
        $stmt->bindValue(':body_header', null, PDO::PARAM_NULL);
    }
    $stmt->execute();

    $dbh->commit();

    echo json_encode([
        'success' => true,
        'id' => $id,
    ], JSON_UNESCAPED_UNICODE);
}
catch (Throwable $e)
{
    if (isset($dbh) && $dbh instanceof PDO && $dbh->inTransaction()) {
        $dbh->rollBack();
    }

    header('Content-Type: application/json; charset=utf-8');
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'メール設定の保存に失敗しました。',
        'error' => $e->getMessage(),
    ], JSON_UNESCAPED_UNICODE);
}
finally
{
    $dbh = null;
}

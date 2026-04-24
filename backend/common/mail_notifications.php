<?php

require_once dirname(__FILE__) . '/crypto.php';

function loadMailSettings(PDO $dbh): ?array
{
    $stmt = $dbh->prepare(
        "SELECT id, is_enabled, recipient_mail, cc_mail, sender_name, sender_mail,
                smtp_host, smtp_port, smtp_user, smtp_password, encryption_type, subject, body_header
           FROM m_mail_settings
          ORDER BY id ASC
          LIMIT 1"
    );
    $stmt->execute();
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row || (int)($row['is_enabled'] ?? 0) !== 1) {
        return null;
    }

    $row['smtp_password'] = decryptAppSecret($row['smtp_password'] ?? '');
    return $row;
}

function fetchWorkReportNotificationRow(PDO $dbh, int $userId, string $workDate): array
{
    $sql = "SELECT
                wr.user_id,
                wr.work_date,
                u.name AS user_name,
                p1.name AS payment1_name, wr.amount1,
                p2.name AS payment2_name, wr.amount2,
                p3.name AS payment3_name, wr.amount3,
                p4.name AS payment4_name, wr.amount4,
                p5.name AS payment5_name, wr.amount5
            FROM t_work_reports wr
            INNER JOIN m_users u ON u.id = wr.user_id
            LEFT JOIN m_payments p1 ON p1.id = wr.payment1_id
            LEFT JOIN m_payments p2 ON p2.id = wr.payment2_id
            LEFT JOIN m_payments p3 ON p3.id = wr.payment3_id
            LEFT JOIN m_payments p4 ON p4.id = wr.payment4_id
            LEFT JOIN m_payments p5 ON p5.id = wr.payment5_id
            WHERE wr.user_id = :user_id AND wr.work_date = :work_date
            LIMIT 1";

    $stmt = $dbh->prepare($sql);
    $stmt->bindValue(':user_id', $userId, PDO::PARAM_INT);
    $stmt->bindValue(':work_date', $workDate, PDO::PARAM_STR);
    $stmt->execute();

    return $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
}

function hasAnyReimburseAmount(array $row): bool
{
    for ($i = 1; $i <= 5; $i++) {
        if (($row['payment' . $i . '_name'] ?? '') !== '' && ($row['amount' . $i] ?? null) !== null) {
            return true;
        }
    }
    return false;
}

function reimburseAmountChanged(array $beforeRow, array $afterRow, int $index): bool
{
    return (string)($beforeRow['amount' . $index] ?? '') !== (string)($afterRow['amount' . $index] ?? '');
}

function buildReimburseChangeLines(array $beforeRow, array $afterRow, bool $isNew): array
{
    $lines = [];

    for ($i = 1; $i <= 5; $i++) {
        $name = trim((string)($afterRow['payment' . $i . '_name'] ?? ''));
        $beforeAmount = $beforeRow['amount' . $i] ?? null;
        $afterAmount = $afterRow['amount' . $i] ?? null;

        if ($isNew) {
            if ($name !== '' && $afterAmount !== null) {
                $lines[] = sprintf('%s: %s円', $name, number_format((int)$afterAmount));
            }
            continue;
        }

        if (!reimburseAmountChanged($beforeRow, $afterRow, $i)) {
            continue;
        }

        $displayName = $name !== '' ? $name : ('立替' . $i);
        $beforeText = $beforeAmount === null ? '未登録' : number_format((int)$beforeAmount) . '円';
        $afterText = $afterAmount === null ? '未登録' : number_format((int)$afterAmount) . '円';
        $lines[] = sprintf('%s: %s -> %s', $displayName, $beforeText, $afterText);
    }

    return $lines;
}

function notifyWorkReportReimburseChange(PDO $dbh, int $userId, string $workDate, array $beforeRow, array $afterRow, bool $isNew): void
{
    $settings = loadMailSettings($dbh);
    if ($settings === null) {
        return;
    }

    if ($isNew) {
        if (!hasAnyReimburseAmount($afterRow)) {
            return;
        }
    } else {
        $changed = false;
        for ($i = 1; $i <= 5; $i++) {
            if (reimburseAmountChanged($beforeRow, $afterRow, $i)) {
                $changed = true;
                break;
            }
        }
        if (!$changed) {
            return;
        }
    }

    $changeLines = buildReimburseChangeLines($beforeRow, $afterRow, $isNew);
    if (!$changeLines) {
        return;
    }

    $bodyLines = [];
    $header = trim((string)($settings['body_header'] ?? ''));
    if ($header !== '') {
        $bodyLines[] = $header;
        $bodyLines[] = '';
    }
    $bodyLines[] = '対象者: ' . (string)($afterRow['user_name'] ?? '');
    $bodyLines[] = '対象日: ' . $workDate;
    $bodyLines[] = '変更種別: ' . ($isNew ? '新規登録' : '金額変更');
    $bodyLines[] = '';
    $bodyLines[] = '立替内容';
    foreach ($changeLines as $line) {
        $bodyLines[] = '- ' . $line;
    }

    sendSmtpMailRaw([
        'host' => (string)$settings['smtp_host'],
        'port' => (int)$settings['smtp_port'],
        'username' => (string)$settings['smtp_user'],
        'password' => (string)$settings['smtp_password'],
        'encryption' => strtolower((string)$settings['encryption_type']),
        'from_mail' => (string)$settings['sender_mail'],
        'from_name' => (string)$settings['sender_name'],
        'to_mail' => (string)$settings['recipient_mail'],
        'cc_mail' => (string)($settings['cc_mail'] ?? ''),
        'subject' => (string)$settings['subject'],
        'body' => implode("\r\n", $bodyLines),
    ]);
}

function sendSmtpMailRaw(array $config): void
{
    $host = (string)$config['host'];
    $port = (int)$config['port'];
    $username = (string)$config['username'];
    $password = (string)$config['password'];
    $encryption = (string)($config['encryption'] ?? 'tls');

    $remote = $encryption === 'ssl' ? 'ssl://' . $host : $host;
    $context = stream_context_create([
        'ssl' => [
            'verify_peer' => false,
            'verify_peer_name' => false,
            'allow_self_signed' => true,
            'SNI_enabled' => true,
            'peer_name' => $host,
            'crypto_method' => getTlsCryptoMethod(),
        ],
    ]);
    $socket = @stream_socket_client($remote . ':' . $port, $errno, $errstr, 15, STREAM_CLIENT_CONNECT, $context);
    if (!$socket) {
        throw new RuntimeException('SMTP connect failed: ' . $errstr);
    }

    stream_set_timeout($socket, 15);
    smtpReadResponse($socket, [220]);
    smtpWriteCommand($socket, 'EHLO localhost', [250]);

    if ($encryption === 'tls' || $encryption === 'starttls') {
        smtpWriteCommand($socket, 'STARTTLS', [220]);
        $enabled = @stream_socket_enable_crypto($socket, true, getTlsCryptoMethod());
        if ($enabled !== true) {
            throw new RuntimeException('SMTP STARTTLS failed');
        }
        smtpWriteCommand($socket, 'EHLO localhost', [250]);
    }

    if ($username !== '') {
        smtpWriteCommand($socket, 'AUTH LOGIN', [334]);
        smtpWriteCommand($socket, base64_encode($username), [334]);
        smtpWriteCommand($socket, base64_encode($password), [235]);
    }

    smtpWriteCommand($socket, 'MAIL FROM:<' . $config['from_mail'] . '>', [250]);
    smtpWriteCommand($socket, 'RCPT TO:<' . $config['to_mail'] . '>', [250, 251]);

    $ccMail = trim((string)($config['cc_mail'] ?? ''));
    if ($ccMail !== '') {
        smtpWriteCommand($socket, 'RCPT TO:<' . $ccMail . '>', [250, 251]);
    }

    smtpWriteCommand($socket, 'DATA', [354]);

    $headers = [
        'From: ' . smtpEncodeHeader((string)$config['from_name']) . ' <' . $config['from_mail'] . '>',
        'To: <' . $config['to_mail'] . '>',
    ];
    if ($ccMail !== '') {
        $headers[] = 'Cc: <' . $ccMail . '>';
    }
    $headers[] = 'Subject: ' . smtpEncodeHeader((string)$config['subject']);
    $headers[] = 'MIME-Version: 1.0';
    $headers[] = 'Content-Type: text/plain; charset=UTF-8';
    $headers[] = 'Content-Transfer-Encoding: base64';

    $message = implode("\r\n", $headers) . "\r\n\r\n" . chunk_split(base64_encode((string)$config['body'])) . "\r\n.";
    fwrite($socket, $message . "\r\n");
    smtpReadResponse($socket, [250]);
    smtpWriteCommand($socket, 'QUIT', [221]);
    fclose($socket);
}

function smtpWriteCommand($socket, string $command, array $expectedCodes): string
{
    fwrite($socket, $command . "\r\n");
    return smtpReadResponse($socket, $expectedCodes);
}

function smtpReadResponse($socket, array $expectedCodes): string
{
    $response = '';
    while (($line = fgets($socket, 515)) !== false) {
        $response .= $line;
        if (isset($line[3]) && $line[3] === ' ') {
            break;
        }
    }

    if ($response === '') {
        throw new RuntimeException('SMTP response empty');
    }

    $code = (int)substr($response, 0, 3);
    if (!in_array($code, $expectedCodes, true)) {
        throw new RuntimeException('SMTP error: ' . trim($response));
    }

    return $response;
}

function smtpEncodeHeader(string $text): string
{
    return $text === '' ? '' : '=?UTF-8?B?' . base64_encode($text) . '?=';
}

function getTlsCryptoMethod(): int
{
    $methods = [];

    foreach ([
        'STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT',
        'STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT',
        'STREAM_CRYPTO_METHOD_TLSv1_1_CLIENT',
        'STREAM_CRYPTO_METHOD_TLSv1_0_CLIENT',
        'STREAM_CRYPTO_METHOD_TLS_CLIENT',
    ] as $constantName) {
        if (defined($constantName)) {
            $methods[] = constant($constantName);
        }
    }

    if (!$methods) {
        throw new RuntimeException('No TLS crypto method available');
    }

    $mode = 0;
    foreach ($methods as $method) {
        $mode |= $method;
    }

    return $mode;
}

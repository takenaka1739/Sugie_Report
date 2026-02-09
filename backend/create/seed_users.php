<?php
/**
 * backend/create/seed_users.php
 * 目的: m_users にテストユーザー（admin / user）を投入します。
 * 実行: ブラウザ or CLI からアクセスしてOK（出力はJSON）
 *
 * 注意:
 * - 既に同名ユーザーが存在する場合はスキップします。
 * - パスワードは password_hash() でハッシュ化して保存します。
 * - デフォルト値（nationality_id, shift_id, blood_type_id など）は仮置きです。必要に応じて整合させてください。
 */

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

// ===== DB接続 =====
$boot = __DIR__ . '/../common/db_manager.php';
if (!file_exists($boot)) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'message' => 'common/db_manager.php が見つかりません。']);
    exit;
}
require_once $boot;

if (!isset($dbh) || !($dbh instanceof PDO)) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'message' => 'DB接続が初期化されていません。']);
    exit;
}

// ===== シードデータ定義 =====
// パスワードは動作確認用の仮パスです。運用前に必ず変更してください。
$now = date('Y-m-d H:i:s');
$seedUsers = [
    [
        'name'              => 'admin',
        'password_plain'    => 'sugie', // ← 運用前に変更推奨
        'is_authorized'     => 1,           // 管理者
        'nationality_id'    => 1,           // 仮: 日本人
        'shift_id'          => 1,           // 仮: 日勤
        'blood_type_id'     => 1,           // 仮: A型
        'health_checked_on' => null,
        'paid_holidays_num' => 10,
        'retiremented_on'   => null,
        'work_cloth_1_id'   => null,
        'work_cloth_2_id'   => null,
        'work_cloth_3_id'   => null,
        'work_cloth_4_id'   => null,
        'work_cloth_5_id'   => null,
        'created_at'        => $now,
        'updated_at'        => $now,
    ],

];

$result = ['ok' => true, 'inserted' => [], 'skipped' => [], 'errors' => []];

try {
    $dbh->beginTransaction();

    // 存在チェック用
    $checkStmt = $dbh->prepare('SELECT id FROM m_users WHERE name = :name LIMIT 1');

    // INSERT 用
    $insertSql = <<<SQL
INSERT INTO m_users (
  name, password, is_authorized, nationality_id, shift_id, blood_type_id,
  health_checked_on, paid_holidays_num, retiremented_on,
  work_cloth_1_id, work_cloth_2_id, work_cloth_3_id, work_cloth_4_id, work_cloth_5_id,
  created_at, updated_at
) VALUES (
  :name, :password, :is_authorized, :nationality_id, :shift_id, :blood_type_id,
  :health_checked_on, :paid_holidays_num, :retiremented_on,
  :work_cloth_1_id, :work_cloth_2_id, :work_cloth_3_id, :work_cloth_4_id, :work_cloth_5_id,
  :created_at, :updated_at
)
SQL;
    $insStmt = $dbh->prepare($insertSql);

    foreach ($seedUsers as $u) {
        // 既存チェック（name 一意で判断）
        $checkStmt->bindValue(':name', $u['name'], PDO::PARAM_STR);
        $checkStmt->execute();
        $exists = $checkStmt->fetch(PDO::FETCH_ASSOC);

        if ($exists) {
            $result['skipped'][] = ['name' => $u['name'], 'reason' => 'already exists'];
            continue;
        }

        // ハッシュ化
        $hash = password_hash($u['password_plain'], PASSWORD_DEFAULT);

        // バインド
        $insStmt->bindValue(':name', $u['name'], PDO::PARAM_STR);
        $insStmt->bindValue(':password', $hash, PDO::PARAM_STR);
        $insStmt->bindValue(':is_authorized', (int)$u['is_authorized'], PDO::PARAM_INT);
        $insStmt->bindValue(':nationality_id', (int)$u['nationality_id'], PDO::PARAM_INT);
        $insStmt->bindValue(':shift_id', (int)$u['shift_id'], PDO::PARAM_INT);
        $insStmt->bindValue(':blood_type_id', (int)$u['blood_type_id'], PDO::PARAM_INT);

        // NULL項目は PARAM_NULL を使う
        $insStmt->bindValue(':health_checked_on', $u['health_checked_on'], $u['health_checked_on'] === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
        $insStmt->bindValue(':paid_holidays_num', (int)$u['paid_holidays_num'], PDO::PARAM_INT);
        $insStmt->bindValue(':retiremented_on', $u['retiremented_on'], $u['retiremented_on'] === null ? PDO::PARAM_NULL : PDO::PARAM_STR);

        $insStmt->bindValue(':work_cloth_1_id', $u['work_cloth_1_id'], $u['work_cloth_1_id'] === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
        $insStmt->bindValue(':work_cloth_2_id', $u['work_cloth_2_id'], $u['work_cloth_2_id'] === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
        $insStmt->bindValue(':work_cloth_3_id', $u['work_cloth_3_id'], $u['work_cloth_3_id'] === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
        $insStmt->bindValue(':work_cloth_4_id', $u['work_cloth_4_id'], $u['work_cloth_4_id'] === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
        $insStmt->bindValue(':work_cloth_5_id', $u['work_cloth_5_id'], $u['work_cloth_5_id'] === null ? PDO::PARAM_NULL : PDO::PARAM_INT);

        $insStmt->bindValue(':created_at', $u['created_at'], PDO::PARAM_STR);
        $insStmt->bindValue(':updated_at', $u['updated_at'], PDO::PARAM_STR);

        $insStmt->execute();

        $result['inserted'][] = [
            'name' => $u['name'],
            'password_hint' => '(仮) ' . $u['password_plain'], // 確認用ヒント。運用前に削除・変更推奨
        ];
    }

    $dbh->commit();
} catch (Throwable $e) {
    if ($dbh->inTransaction()) {
        $dbh->rollBack();
    }
    $result['ok'] = false;
    $result['errors'][] = $e->getMessage();
    http_response_code(500);
}

echo json_encode($result, JSON_UNESCAPED_UNICODE);

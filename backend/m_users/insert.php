<?php
require_once dirname(__DIR__, 1) . '/common/db_manager.php';

function norm_int_or_null($v)
{
    if (!isset($v)) return null;
    if ($v === '' || $v === null) return null;
    if (is_numeric($v)) return (int)$v;
    return null;
}

try {
    // CORS対応
    header('Access-Control-Allow-Origin: ' . ORIGIN);
    header('Access-Control-Allow-Headers: Content-Type');

    // JSON形式でPOSTされたデータの取り出し
    $json = file_get_contents("php://input");
    $_POST = json_decode($json, true);

    // 必須項目（6/7は任意）
    if (
        !empty($_POST['name']) &&
        isset($_POST['nationality_id']) &&
        isset($_POST['shift_id']) &&
        isset($_POST['blood_type_id']) &&
        isset($_POST['work_cloth_1_id']) &&
        isset($_POST['work_cloth_2_id']) &&
        isset($_POST['work_cloth_3_id']) &&
        isset($_POST['work_cloth_4_id']) &&
        isset($_POST['work_cloth_5_id'])
    ) {
        $dbh = getDb();
        $defaultPasswordHash = password_hash('password', PASSWORD_DEFAULT);

        // is_authorized が未指定の場合は 0 を既定値に
        $isAuthorized = isset($_POST['is_authorized']) ? (int)$_POST['is_authorized'] : 0;

        // 6/7 は NULL 許可
        $workCloth6 = norm_int_or_null($_POST['work_cloth_6_id'] ?? null);
        $workCloth7 = norm_int_or_null($_POST['work_cloth_7_id'] ?? null);

        $sql = "INSERT INTO m_users (
                    name,
                    password,
                    is_authorized,
                    nationality_id,
                    shift_id,
                    blood_type_id,
                    health_checked_on,
                    paid_holidays_num,
                    retiremented_on,
                    work_cloth_1_id,
                    work_cloth_2_id,
                    work_cloth_3_id,
                    work_cloth_4_id,
                    work_cloth_5_id,
                    work_cloth_6_id,
                    work_cloth_7_id,
                    created_at,
                    updated_at
                ) VALUES (
                    :name,
                    :password,
                    :is_authorized,
                    :nationality_id,
                    :shift_id,
                    :blood_type_id,
                    :health_checked_on,
                    :paid_holidays_num,
                    :retiremented_on,
                    :work_cloth_1_id,
                    :work_cloth_2_id,
                    :work_cloth_3_id,
                    :work_cloth_4_id,
                    :work_cloth_5_id,
                    :work_cloth_6_id,
                    :work_cloth_7_id,
                    NOW(),
                    NOW()
                );";

        $stmt = $dbh->prepare($sql);

        $stmt->bindValue(':name', $_POST['name']);
        $stmt->bindValue(':password', $defaultPasswordHash);
        $stmt->bindValue(':is_authorized', $isAuthorized, PDO::PARAM_INT);

        $stmt->bindValue(':nationality_id', (int)$_POST['nationality_id'], PDO::PARAM_INT);
        $stmt->bindValue(':shift_id', (int)$_POST['shift_id'], PDO::PARAM_INT);
        $stmt->bindValue(':blood_type_id', (int)$_POST['blood_type_id'], PDO::PARAM_INT);

        // 日付・数値系は現状維持（必要ならここも正規化可能）
        $stmt->bindValue(':health_checked_on', $_POST['health_checked_on'] ?? null);
        $stmt->bindValue(':paid_holidays_num', $_POST['paid_holidays_num'] ?? null);
        $stmt->bindValue(':retiremented_on', $_POST['retiremented_on'] ?? null);

        $stmt->bindValue(':work_cloth_1_id', (int)$_POST['work_cloth_1_id'], PDO::PARAM_INT);
        $stmt->bindValue(':work_cloth_2_id', (int)$_POST['work_cloth_2_id'], PDO::PARAM_INT);
        $stmt->bindValue(':work_cloth_3_id', (int)$_POST['work_cloth_3_id'], PDO::PARAM_INT);
        $stmt->bindValue(':work_cloth_4_id', (int)$_POST['work_cloth_4_id'], PDO::PARAM_INT);
        $stmt->bindValue(':work_cloth_5_id', (int)$_POST['work_cloth_5_id'], PDO::PARAM_INT);

        // 6/7 は NULL の場合は NULL を入れる
        if ($workCloth6 === null) {
            $stmt->bindValue(':work_cloth_6_id', null, PDO::PARAM_NULL);
        } else {
            $stmt->bindValue(':work_cloth_6_id', $workCloth6, PDO::PARAM_INT);
        }

        if ($workCloth7 === null) {
            $stmt->bindValue(':work_cloth_7_id', null, PDO::PARAM_NULL);
        } else {
            $stmt->bindValue(':work_cloth_7_id', $workCloth7, PDO::PARAM_INT);
        }

        $stmt->execute();
    }
} catch (PDOException $e) {
    // いまのままだと原因が見えないので、最低限ログに残すのがおすすめ
     error_log('[m_users/insert] PDOException: ' . $e->getMessage());
} finally {
    $dbh = null;
}
?>

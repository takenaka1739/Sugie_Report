<?php
    require_once dirname(__DIR__, 1) . '/common/db_manager.php';

    try
    {
        // CORS対応 (異なるオリジンからのアクセスを許可)
        header('Access-Control-Allow-Origin: ' .ORIGIN);

        // 使用可能なHTTPヘッダーの設定
        header('Access-Control-Allow-Headers: Content-Type');

        // JSON形式でPOSTされたデータの取り出し
        $json = file_get_contents("php://input");

        // JSON形式の文字列のでコード
        $_POST = json_decode($json, true);

        if (!empty($_POST['regular_start']) && !empty($_POST['regular_finish']) && !empty($_POST['overtime_start']) && !empty($_POST['late_overtime_start']))
        {
          // データベース接続情報の設定
          $dbh = getDb();

          // SQLステートメント
          $sql = "INSERT INTO m_shifts (
                    regular_start,
                    regular_finish,
                    overtime_start,
                    late_overtime_start,
                    created_at,
                    updated_at
                  ) VALUES (
                    :regular_start,
                    :regular_finish,
                    :overtime_start,
                    :late_overtime_start,
                    NOW(),
                    NOW()
                  );";

          $stmt = $dbh->prepare($sql);
          $stmt->bindValue(':regular_start', $_POST['regular_start']);
          $stmt->bindValue(':regular_finish', $_POST['regular_finish']);
          $stmt->bindValue(':overtime_start', $_POST['overtime_start']);
          $stmt->bindValue(':late_overtime_start', $_POST['late_overtime_start']);
          $stmt->execute();
        }
    }
    catch (PDOException $e)
    {
        $connected = false;
    }
    finally
    {
        $dbh = null;
    }
?>
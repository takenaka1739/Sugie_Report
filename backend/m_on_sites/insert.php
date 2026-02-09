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

        if (!empty($_POST['name']) && !empty($_POST['type_id']))
        {
          // データベース接続情報の設定
          $dbh = getDb();

          // SQLステートメント
          $sql = "INSERT INTO m_on_sites (
                    name,
                    type_id,
                    created_at,
                    updated_at
                  ) VALUES (
                    :name,
                    :type_id,
                    NOW(),
                    NOW()
                  );";

          $stmt = $dbh->prepare($sql);
          $stmt->bindValue(':name', $_POST['name']);
          $stmt->bindValue(':type_id', $_POST['type_id']);
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
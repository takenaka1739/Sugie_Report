<?php
// 共通CORS（必要なら）
require_once dirname(__DIR__, 1) . '/common/cors.php';
// DB
require_once dirname(__DIR__, 1) . '/common/db_manager.php';

try {
    $dbh = getDb();

    // データ取得（type_id は区分名算出に使用。表示はしない）
    $sql = "SELECT id, name, COALESCE(type_id, 1) AS type_id FROM m_on_sites ORDER BY id ASC;";
    $stmt = $dbh->prepare($sql);
    $stmt->execute();
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $basename = '営業所マスタ_' . date('Ymd_His') . '.xls';
    // RFC 5987 による UTF-8 日本語ファイル名（ブラウザ互換）
    $disposition = 'attachment; filename="' . $basename . '"; filename*=UTF-8\'\'' . rawurlencode($basename);

    // Excel(互換)としてダウンロードさせる
    header('Content-Type: application/vnd.ms-excel; charset=UTF-8');
    header('Content-Disposition: ' . $disposition);
    header('Cache-Control: max-age=0');

    // UTF-8 BOM（日本語文字化け対策）
    echo "\xEF\xBB\xBF";
    ?>
<!DOCTYPE html>
<html lang="ja">
<head>
<meta charset="UTF-8" />
<title>営業所マスタ エクスポート</title>
<style>
  table { border-collapse: collapse; }
  th, td { border: 1px solid #666; padding: 4px 6px; }
  th { background: #eee; }
</style>
</head>
<body>
<table>
  <thead>
    <tr>
      <th>ID</th>
      <th>現場名</th>
      <!-- 区分ID は表示しない -->
      <th>区分名</th>
    </tr>
  </thead>
  <tbody>
    <?php foreach ($rows as $r):
      $typeId = (int)($r['type_id'] ?? 1);
      // 区分名：1=自社, 2=前田（必要に応じて拡張）
      $typeName = ($typeId === 2) ? '前田' : '自社';
    ?>
      <tr>
        <td><?php echo (int)$r['id']; ?></td>
        <td><?php echo htmlspecialchars((string)($r['name'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
        <td><?php echo htmlspecialchars($typeName, ENT_QUOTES, 'UTF-8'); ?></td>
      </tr>
    <?php endforeach; ?>
  </tbody>
</table>
</body>
</html>
<?php
} catch (PDOException $e) {
    header('Content-Type: application/json; charset=utf-8');
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Database error',
        'error'   => $e->getMessage(),
    ], JSON_UNESCAPED_UNICODE);
} finally {
    $dbh = null;
}

<?php
// 共通CORS（必要なら）
require_once dirname(__DIR__, 1) . '/common/cors.php';
// DB
require_once dirname(__DIR__, 1) . '/common/db_manager.php';

try {
    $dbh = getDb();

    // 車両一覧取得（select.php と同カラム順）
    $sql = "SELECT 
                id, 
                number, 
                model, 
                inspected_on, 
                liability_insuranced_on, 
                voluntary_insuranced_on
            FROM m_vehicles
            ORDER BY id ASC";
    $stmt = $dbh->prepare($sql);
    $stmt->execute();
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $basename = '車両マスタ_' . date('Ymd_His') . '.xls';
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
<title>車両マスタ エクスポート</title>
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
      <th>ナンバー</th>
      <th>車種</th>
      <th>車検日</th>
      <th>自賠責保険</th>
      <th>任意保険</th>
    </tr>
  </thead>
  <tbody>
<?php foreach ($rows as $r):
    // 各日付は YYYY-MM-DD に揃える（NULL/空は空文字）
    $inspected  = !empty($r['inspected_on']) ? substr((string)$r['inspected_on'], 0, 10) : '';
    $liability  = !empty($r['liability_insuranced_on']) ? substr((string)$r['liability_insuranced_on'], 0, 10) : '';
    $voluntary  = !empty($r['voluntary_insuranced_on']) ? substr((string)$r['voluntary_insuranced_on'], 0, 10) : '';
?>
    <tr>
      <td><?php echo (int)$r['id']; ?></td>
      <td><?php echo htmlspecialchars((string)($r['number'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
      <td><?php echo htmlspecialchars((string)($r['model'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
      <td><?php echo htmlspecialchars($inspected, ENT_QUOTES, 'UTF-8'); ?></td>
      <td><?php echo htmlspecialchars($liability, ENT_QUOTES, 'UTF-8'); ?></td>
      <td><?php echo htmlspecialchars($voluntary, ENT_QUOTES, 'UTF-8'); ?></td>
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

<?php
// 共通CORS（必要なら／プリフライト早期終了）
require_once dirname(__DIR__, 1) . '/common/cors.php';
// DB
require_once dirname(__DIR__, 1) . '/common/db_manager.php';

try {
    $dbh = getDb();

    // シフト一覧取得（select.php と同カラム順）
    $sql = "SELECT
                id,
                regular_start,
                regular_finish,
                overtime_start,
                late_overtime_start
            FROM m_shifts
            ORDER BY id ASC";
    $stmt = $dbh->prepare($sql);
    $stmt->execute();
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $basename = 'シフトマスタ_' . date('Ymd_His') . '.xls';
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
<title>シフトマスタ エクスポート</title>
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
      <th>定時（始業）</th>
      <th>定時（終業）</th>
      <th>残業開始</th>
      <th>深夜残業開始</th>
    </tr>
  </thead>
  <tbody>
<?php foreach ($rows as $r): ?>
    <tr>
      <td><?php echo (int)$r['id']; ?></td>
      <td><?php echo htmlspecialchars(substr((string)($r['regular_start'] ?? ''), 0, 5), ENT_QUOTES, 'UTF-8'); ?></td>
      <td><?php echo htmlspecialchars(substr((string)($r['regular_finish'] ?? ''), 0, 5), ENT_QUOTES, 'UTF-8'); ?></td>
      <td><?php echo htmlspecialchars(substr((string)($r['overtime_start'] ?? ''), 0, 5), ENT_QUOTES, 'UTF-8'); ?></td>
      <td><?php echo htmlspecialchars(substr((string)($r['late_overtime_start'] ?? ''), 0, 5), ENT_QUOTES, 'UTF-8'); ?></td>
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

<?php
// 共通CORS（必要なら）
require_once dirname(__DIR__, 1) . '/common/cors.php';
// DB
require_once dirname(__DIR__, 1) . '/common/db_manager.php';

try {
    $dbh = getDb();

    // 一覧取得（select.php と同等の主要カラム）
    $sql = "SELECT 
                id, name, is_authorized, nationality_id, shift_id, blood_type_id,
                health_checked_on, paid_holidays_num, retiremented_on,
                work_cloth_1_id, work_cloth_2_id, work_cloth_3_id, work_cloth_4_id, work_cloth_5_id,work_cloth_6_id,work_cloth_7_id
            FROM m_users
            ORDER BY id ASC";
    $stmt = $dbh->prepare($sql);
    $stmt->execute();
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // ===== 追加：シフトマップ（id => [start, finish]） =====
    $shiftMap = [];
    try {
        $stShift = $dbh->query("SELECT id, regular_start, regular_finish FROM m_shifts");
        if ($stShift) {
            while ($r = $stShift->fetch(PDO::FETCH_ASSOC)) {
                $sid = (int)$r['id'];
                $rs  = isset($r['regular_start'])  ? (string)$r['regular_start']  : '';
                $rf  = isset($r['regular_finish']) ? (string)$r['regular_finish'] : '';
                // "HH:MM" に整形（秒があれば切り落とし）
                $rs = $rs ? substr($rs, 0, 5) : '';
                $rf = $rf ? substr($rf, 0, 5) : '';
                $shiftMap[$sid] = ['start' => $rs, 'finish' => $rf];
            }
        }
    } catch (Throwable $e) {
        // シフトテーブルが無い/カラム違いでも処理継続（空のまま＝空表示）
        $shiftMap = [];
    }

    // マスタ値のラベル化（フロントと同じ定義）
    $nationalityMap = [
        0 => 'ー',
        1 => '日本人',
        2 => '外国人',
    ];
    $bloodTypeMap = [
        0 => 'ー',
        1 => 'A型',
        2 => 'B型',
        3 => 'AB型',
        4 => 'O型',
    ];
    $clothSizeMap = [
        0 => 'ー',
        1 => 'S',
        2 => 'M',
        3 => 'L',
        4 => 'XL',
        5 => '2L',
        6 => '3L',
        7 => '4L',
        8 => '5L',
        9 => '特注',
    ];

    $basename = '社員マスタ_' . date('Ymd_His') . '.xls';
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
<title>社員マスタ エクスポート</title>
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
      <th>氏名</th>
      <th>管理者</th>
      <th>区分</th>
      <th>始業</th>
      <th>終業</th>
      <th>血液型</th>
      <th>健康診断日</th>
      <th>有休日数</th>
      <th>退職日</th>
      <th>(夏)ブルゾン</th>
      <th>(夏)パンツ</th>
      <th>(夏)空調服</th>
      <th>(夏)インナー</th>
      <th>(冬)ブルゾン</th>
      <th>(冬)パンツ</th>
      <th>(冬)防寒着</th>
    </tr>
  </thead>
  <tbody>
<?php foreach ($rows as $r): 
    $isAdmin = (int)($r['is_authorized'] ?? 0) === 1 ? 'はい' : 'いいえ';
    $natId = (int)($r['nationality_id'] ?? 0);
    $nat = $nationalityMap[$natId] ?? 'ー';
    $bloodId = (int)($r['blood_type_id'] ?? 0);
    $blood = $bloodTypeMap[$bloodId] ?? 'ー';

    // ▼ シフト開始/終了（存在しなければ空）
    $shiftId = (int)($r['shift_id'] ?? 0);
    $shiftStart  = $shiftMap[$shiftId]['start']  ?? '';
    $shiftFinish = $shiftMap[$shiftId]['finish'] ?? '';

    // 日付は YYYY-MM-DD に整形（NULL のときは空）
    $health = !empty($r['health_checked_on']) ? substr((string)$r['health_checked_on'], 0, 10) : '';
    $retire = !empty($r['retiremented_on'])   ? substr((string)$r['retiremented_on'], 0, 10)   : '';

    $cloth1 = $clothSizeMap[(int)($r['work_cloth_1_id'] ?? 0)] ?? 'ー';
    $cloth2 = $clothSizeMap[(int)($r['work_cloth_2_id'] ?? 0)] ?? 'ー';
    $cloth3 = $clothSizeMap[(int)($r['work_cloth_3_id'] ?? 0)] ?? 'ー';
    $cloth4 = $clothSizeMap[(int)($r['work_cloth_4_id'] ?? 0)] ?? 'ー';
    $cloth5 = $clothSizeMap[(int)($r['work_cloth_5_id'] ?? 0)] ?? 'ー';
    $cloth6 = $clothSizeMap[(int)($r['work_cloth_6_id'] ?? 0)] ?? 'ー';
    $cloth7 = $clothSizeMap[(int)($r['work_cloth_7_id'] ?? 0)] ?? 'ー';
?>
    <tr>
      <td><?php echo (int)$r['id']; ?></td>
      <td><?php echo htmlspecialchars((string)($r['name'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
      <td><?php echo $isAdmin; ?></td>
      <td><?php echo htmlspecialchars($nat, ENT_QUOTES, 'UTF-8'); ?></td>
      <td><?php echo htmlspecialchars($shiftStart, ENT_QUOTES, 'UTF-8'); ?></td>
      <td><?php echo htmlspecialchars($shiftFinish, ENT_QUOTES, 'UTF-8'); ?></td>
      <td><?php echo htmlspecialchars($blood, ENT_QUOTES, 'UTF-8'); ?></td>
      <td><?php echo htmlspecialchars($health, ENT_QUOTES, 'UTF-8'); ?></td>
      <td><?php echo (string)($r['paid_holidays_num'] ?? ''); ?></td>
      <td><?php echo htmlspecialchars($retire, ENT_QUOTES, 'UTF-8'); ?></td>
      <td><?php echo htmlspecialchars($cloth1, ENT_QUOTES, 'UTF-8'); ?></td>
      <td><?php echo htmlspecialchars($cloth2, ENT_QUOTES, 'UTF-8'); ?></td>
      <td><?php echo htmlspecialchars($cloth3, ENT_QUOTES, 'UTF-8'); ?></td>
      <td><?php echo htmlspecialchars($cloth4, ENT_QUOTES, 'UTF-8'); ?></td>
      <td><?php echo htmlspecialchars($cloth5, ENT_QUOTES, 'UTF-8'); ?></td>
      <td><?php echo htmlspecialchars($cloth6, ENT_QUOTES, 'UTF-8'); ?></td>
      <td><?php echo htmlspecialchars($cloth7, ENT_QUOTES, 'UTF-8'); ?></td>
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

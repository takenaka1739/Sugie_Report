<?php
/**
 * 有給（当月取得者一覧＋年度残有給）を XLSX / HTML(Excel互換) / JSON で出力
 *
 * クエリ:
 *  - ym=YYYY-MM         … 対象「月」。未指定なら当月。
 *  - include_admin=1    … 管理者も含める（既定は除外）
 *  - format=json        … 画面表示用に JSON を返す（既定は xlsx/html ダウンロード）
 */

require_once dirname(__DIR__, 1) . '/common/cors.php';
require_once dirname(__DIR__, 1) . '/common/db_manager.php';

function ymd_first_last_of_ym(string $ym): array {
    $dt = DateTime::createFromFormat('Y-m-d', $ym . '-01');
    if (!$dt) { $dt = new DateTime('first day of this month'); }
    $first = $dt->format('Y-m-01');
    $last  = $dt->format('Y-m-t');
    return [$first, $last];
}

/** 対象月が属する会計年度（4/1〜翌3/31） */
function fiscal_year_range_from_month(string $ym): array {
    [$first,] = ymd_first_last_of_ym($ym);
    $dt = DateTime::createFromFormat('Y-m-d', $first) ?: new DateTime();
    $y = (int)$dt->format('Y');
    $m = (int)$dt->format('n');
    $start = ($m <= 3) ? new DateTime(($y - 1) . '-04-01') : new DateTime($y . '-04-01');
    $end = (clone $start)->modify('+1 year')->modify('-1 day'); // 翌年3/31
    return [$start->format('Y-m-d'), $end->format('Y-m-d')];
}

try {
    // ===== パラメータ =====
    $ym = isset($_GET['ym']) ? trim((string)$_GET['ym']) : date('Y-m');
    if (!preg_match('/^\d{4}-\d{2}$/', $ym)) $ym = date('Y-m');
    $includeAdmin = isset($_GET['include_admin']) && (int)$_GET['include_admin'] === 1;
    $format = isset($_GET['format']) ? strtolower((string)$_GET['format']) : 'xlsx'; // json / xlsx

    [$monthFrom, $monthTo] = ymd_first_last_of_ym($ym);
    [$fyFrom, $fyTo] = fiscal_year_range_from_month($ym);

    $dbh = getDb();

    // ===== 当月の取得一覧 =====
    $sqlMonthly = "
        SELECT
            l.id,
            l.leave_date,
            u.id AS user_id,
            u.name
        FROM t_paid_leaves l
        INNER JOIN m_users u ON u.id = l.user_id
        WHERE l.leave_date BETWEEN :m_from AND :m_to
        " . ($includeAdmin ? "" : "AND (u.is_authorized IS NULL OR u.is_authorized = 0)") . "
        ORDER BY l.leave_date ASC, u.name ASC
    ";
    $stmt = $dbh->prepare($sqlMonthly);
    $stmt->bindValue(':m_from', $monthFrom);
    $stmt->bindValue(':m_to', $monthTo);
    $stmt->execute();
    $monthlyRows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    // ===== 年度: 使用数（ユーザー別） =====
    $sqlUsed = "
        SELECT l.user_id, COUNT(*) AS used_cnt
        FROM t_paid_leaves l
        INNER JOIN m_users u ON u.id = l.user_id
        WHERE l.leave_date BETWEEN :f_from AND :f_to
        " . ($includeAdmin ? "" : "AND (u.is_authorized IS NULL OR u.is_authorized = 0)") . "
        GROUP BY l.user_id
    ";
    $stmt = $dbh->prepare($sqlUsed);
    $stmt->bindValue(':f_from', $fyFrom);
    $stmt->bindValue(':f_to', $fyTo);
    $stmt->execute();
    $usedMap = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $usedMap[(int)$r['user_id']] = (int)$r['used_cnt'];
    }

    // ===== 社員一覧（付与日数） =====
    $sqlUsers = "
        SELECT id, name, COALESCE(paid_holidays_num, 0) AS granted, COALESCE(is_authorized, 0) AS is_admin
        FROM m_users
        " . ($includeAdmin ? "" : "WHERE (is_authorized IS NULL OR is_authorized = 0)") . "
        ORDER BY id ASC
    ";
    $users = $dbh->query($sqlUsers)->fetchAll(PDO::FETCH_ASSOC) ?: [];

    // ===== 出力行（残有給） =====
    $remainRows = [];
    foreach ($users as $u) {
        $uid = (int)$u['id'];
        $granted = (int)$u['granted'];
        $used = $usedMap[$uid] ?? 0;
        $remain = max(0, $granted - $used);
        $remainRows[] = [
            'id'      => $uid,
            'name'    => (string)$u['name'],
            'granted' => $granted,
            'used'    => $used,
            'remain'  => $remain,
        ];
    }

    // ===== JSON（画面表示用） =====
    if ($format === 'json') {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'success'       => true,
            'ym'            => $ym,
            'fiscal_year'   => ['from' => $fyFrom, 'to' => $fyTo],
            'include_admin' => (bool)$includeAdmin,
            'month'         => array_map(function($r){
                return [
                    'leave_date' => (string)$r['leave_date'],
                    'user_id'    => (int)$r['user_id'],
                    'name'       => (string)$r['name'],
                ];
            }, $monthlyRows),
            'remain'        => $remainRows,
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // ===== ファイル名（「有給申請_〇月_日付」）を準備 =====
    $monthNum = (int)substr($ym, 5, 2); // 1〜12（先頭ゼロを除去）
    $stamp    = date('Ymd_His');

    // ===== PhpSpreadsheet があれば XLSX =====
    $hasSpreadsheet = false;
    try {
        $paths = [
            dirname(__DIR__, 1) . '/vendor/autoload.php',
            dirname(__DIR__, 2) . '/vendor/autoload.php',
        ];
        foreach ($paths as $p) {
            if (is_file($p)) { require_once $p; $hasSpreadsheet = true; break; }
        }
    } catch (Throwable $e) {
        $hasSpreadsheet = false;
    }

    if ($hasSpreadsheet && class_exists(\PhpOffice\PhpSpreadsheet\Spreadsheet::class)) {
        $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
        $spreadsheet->getProperties()->setCreator('Report System')->setTitle('PaidLeave Export');

        // --- シート1: 当月取得一覧 ---
        $sheet1 = $spreadsheet->getActiveSheet();
        $sheet1->setTitle('当月取得一覧');
        $sheet1->fromArray([['取得日','氏名','ユーザーID']], null, 'A1', true);
        $rowIdx = 2;
        foreach ($monthlyRows as $r) {
            $sheet1->setCellValue("A{$rowIdx}", (string)$r['leave_date']);
            $sheet1->setCellValue("B{$rowIdx}", (string)$r['name']);
            $sheet1->setCellValue("C{$rowIdx}", (int)$r['user_id']);
            $rowIdx++;
        }
        foreach (['A'=>14, 'B'=>24, 'C'=>10] as $col => $w) {
            $sheet1->getColumnDimension($col)->setWidth($w);
        }

        // --- シート2: 残有給（年度） ---
        $sheet2 = $spreadsheet->createSheet(1);
        $sheet2->setTitle('残有給（年度）');
        $sheet2->fromArray([[ "対象月: {$ym}", "会計年度: {$fyFrom} 〜 {$fyTo}", "管理者含む: ".($includeAdmin?'はい':'いいえ') ]], null, 'A1', true);
        $sheet2->fromArray([['ID','氏名','付与','使用済','残（日）']], null, 'A3', true);
        $rowIdx = 4;
        foreach ($remainRows as $r) {
            $sheet2->setCellValue("A{$rowIdx}", $r['id']);
            $sheet2->setCellValue("B{$rowIdx}", $r['name']);
            $sheet2->setCellValue("C{$rowIdx}", $r['granted']);
            $sheet2->setCellValue("D{$rowIdx}", $r['used']);
            $sheet2->setCellValue("E{$rowIdx}", $r['remain']);
            $rowIdx++;
        }
        foreach (['A'=>8,'B'=>22,'C'=>10,'D'=>10,'E'=>10] as $col=>$w) {
            $sheet2->getColumnDimension($col)->setWidth($w);
        }

        $basename = "有給申請_{$monthNum}月_{$stamp}.xlsx";
        $disposition = 'attachment; filename="' . $basename . '"; filename*=UTF-8\'\'' . rawurlencode($basename);

        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: ' . $disposition);
        header('Cache-Control: max-age=0');

        $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);
        $writer->save('php://output');
        exit;
    }

    // ===== フォールバック: HTMLテーブル（Excel互換） =====
    $basename = "有給申請_{$monthNum}月_{$stamp}.xls";
    $disposition = 'attachment; filename="' . $basename . '"; filename*=UTF-8\'\'' . rawurlencode($basename);

    header('Content-Type: application/vnd.ms-excel; charset=UTF-8');
    header('Content-Disposition: ' . $disposition);
    header('Cache-Control: max-age=0');

    // UTF-8 BOM（日本語対策）
    echo "\xEF\xBB\xBF";
    ?>
<!DOCTYPE html>
<html lang="ja">
<head>
<meta charset="UTF-8" />
<title>有給エクスポート</title>
<style>
  body { font-family: sans-serif; }
  h3 { margin: 0.5em 0; }
  table { border-collapse: collapse; margin-bottom: 18px; }
  th, td { border: 1px solid #666; padding: 4px 6px; }
  th { background: #eee; }
</style>
</head>
<body>
  <h3>当月取得一覧（対象月: <?php echo htmlspecialchars($ym, ENT_QUOTES, 'UTF-8'); ?>）</h3>
  <table>
    <thead>
      <tr>
        <th>取得日</th>
        <th>氏名</th>
        <th>ユーザーID</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($monthlyRows as $r): ?>
      <tr>
        <td><?php echo htmlspecialchars((string)$r['leave_date'], ENT_QUOTES, 'UTF-8'); ?></td>
        <td><?php echo htmlspecialchars((string)$r['name'], ENT_QUOTES, 'UTF-8'); ?></td>
        <td><?php echo (int)$r['user_id']; ?></td>
      </tr>
      <?php endforeach; ?>
      <?php if (count($monthlyRows) === 0): ?>
      <tr><td colspan="3">データがありません</td></tr>
      <?php endif; ?>
    </tbody>
  </table>

  <h3>残有給（年度）＜会計年度: <?php echo htmlspecialchars($fyFrom, ENT_QUOTES, 'UTF-8'); ?> 〜 <?php echo htmlspecialchars($fyTo, ENT_QUOTES, 'UTF-8'); ?>／管理者含む: <?php echo $includeAdmin ? 'はい' : 'いいえ'; ?>＞</h3>
  <table>
    <thead>
      <tr>
        <th>ID</th>
        <th>氏名</th>
        <th>付与</th>
        <th>使用済</th>
        <th>残（日）</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($remainRows as $r): ?>
      <tr>
        <td><?php echo (int)$r['id']; ?></td>
        <td><?php echo htmlspecialchars($r['name'], ENT_QUOTES, 'UTF-8'); ?></td>
        <td style="text-align:right"><?php echo (int)$r['granted']; ?></td>
        <td style="text-align:right"><?php echo (int)$r['used']; ?></td>
        <td style="text-align:right"><?php echo (int)$r['remain']; ?></td>
      </tr>
      <?php endforeach; ?>
      <?php if (count($remainRows) === 0): ?>
      <tr><td colspan="5">データがありません</td></tr>
      <?php endif; ?>
    </tbody>
  </table>
</body>
</html>
<?php
    exit;

} catch (Throwable $e) {
    header('Content-Type: application/json; charset=utf-8');
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'EXPORT_ERROR',
        'error'   => $e->getMessage(),
    ], JSON_UNESCAPED_UNICODE);
}

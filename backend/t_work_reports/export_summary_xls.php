<?php
/**
 * 作業日報集計表（管理者・全社員）をエクスポート
 * - PhpSpreadsheet があれば XLSX（1シートのみ）を出力
 *   1) 社員別サマリ（氏名ベース、月合計のみ）
 * - なければ HTML(Excel互換) を .xls で出力（同内容1テーブル）
 *
 * GET:
 *   - ym=YYYY-MM            … 対象月（未指定は当月）
 *   - include_admin=1       … 管理者も含める（既定は除外）
 *   - round=0|15|30         … 分丸め（0=丸めなし／既定0）※実働/各時間を同じ単位で丸め
 *
 * 参照テーブル:
 *   t_work_reports(
 *     id, user_id, work_date, start_time, finish_time,
 *     on_site_id, work, is_canceled,
 *     payment1_id, amount1, ... payment5_id, amount5
 *   )
 *   m_users(id, name, is_authorized, shift_id)
 *   m_shifts(id, regular_start, overtime_start, late_overtime_start)
 *   m_payments(id, name)
 */

require_once dirname(__DIR__, 1) . '/common/cors.php';
require_once dirname(__DIR__, 1) . '/common/db_manager.php';

function norm_month($s){ return (isset($s) && $s !== '' && preg_match('/^\d{4}-\d{2}$/', (string)$s)) ? (string)$s : null; }
function month_range(string $ym): array {
    $d = DateTime::createFromFormat('Y-m-d', $ym.'-01') ?: new DateTime('first day of this month');
    return [$d->format('Y-m-01'), $d->format('Y-m-t')];
}
function hasSpreadsheet(): bool {
    try {
        foreach ([dirname(__DIR__,1).'/vendor/autoload.php', dirname(__DIR__,2).'/vendor/autoload.php'] as $p) {
            if (is_file($p)) { require_once $p; break; }
        }
        return class_exists(\PhpOffice\PhpSpreadsheet\Spreadsheet::class);
    } catch (Throwable $e) { return false; }
}
/** 分丸め（0=丸めなし、15なら15分単位の四捨五入） */
function round_minutes(int $min, int $unit): int {
    if ($unit <= 0) return $min;
    return (int) round($min / $unit) * $unit;
}
function hm_to_min(?string $s): ?int {
    if (!$s) return null;
    $s = substr($s, 0, 5);
    if (!preg_match('/^\d{2}:\d{2}$/', $s)) return null;
    [$h,$m] = array_map('intval', explode(':', $s));
    return $h*60 + $m;
}
/** 早出/残業/深夜を算出。特別ルール：出社が17:00以降なら全時間=深夜 */
function calc_parts(?array $shiftRow, ?string $start, ?string $finish): array {
    $st = hm_to_min($start);
    $ft = hm_to_min($finish);
    if ($st === null || $ft === null) return [0,0,0]; // early, overtime, midnight
    if ($ft <= $st) $ft += 24*60;

    // 17:00ルール
    if ($st >= 17*60) {
        return [0, 0, $ft - $st];
    }
    $mReg  = isset($shiftRow['regular_start'])       ? hm_to_min($shiftRow['regular_start'])       : null;
    $mOT   = isset($shiftRow['overtime_start'])      ? hm_to_min($shiftRow['overtime_start'])      : null;
    $mLate = isset($shiftRow['late_overtime_start']) ? hm_to_min($shiftRow['late_overtime_start']) : null;

    $early    = ($mReg !== null && $st < $mReg)  ? max(0, min($ft, $mReg) - $st) : 0;
    $overtime = ($mOT  !== null && $ft > $mOT)   ? max(0, $ft - max($st, $mOT))   : 0;
    $midnight = ($mLate!== null && $ft > $mLate) ? max(0, $ft - max($st, $mLate)) : 0;

    return [$early, $overtime, $midnight];
}

try {
    // ===== 入力 =====
    $ym            = norm_month($_GET['ym'] ?? null) ?? date('Y-m');
    [$dateFrom, $dateTo] = month_range($ym);
    $includeAdmin  = isset($_GET['include_admin']) && (int)$_GET['include_admin'] === 1;
    $roundUnit     = (int)($_GET['round'] ?? 0);
    if (!in_array($roundUnit, [0, 15, 30], true)) $roundUnit = 0;

    $dbh = getDb();

    // ===== ユーザー一覧（氏名・シフト） =====
    $sqlUsers = "
        SELECT id, name, COALESCE(is_authorized,0) AS is_admin, COALESCE(shift_id,0) AS shift_id
          FROM m_users
        " . ($includeAdmin ? "" : "WHERE (is_authorized IS NULL OR is_authorized = 0)") . "
          ORDER BY id ASC
    ";
    $users = $dbh->query($sqlUsers)->fetchAll(PDO::FETCH_ASSOC) ?: [];
    if (!$users) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success'=>true,'message'=>'対象ユーザーがいません','ym'=>$ym,'data'=>[]], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $userName = [];
    $userShiftId = [];
    foreach ($users as $u) {
        $uid = (int)$u['id'];
        $userName[$uid]   = (string)$u['name'];
        $userShiftId[$uid]= (int)$u['shift_id'];
    }

    // ===== ユーザーのシフトまとめ取得 =====
    $shiftMap = []; // shift_id => row
    $shiftIds = array_values(array_unique(array_filter($userShiftId)));
    if ($shiftIds) {
        $in = implode(',', array_map('intval', $shiftIds));
        $sqlSh = "SELECT id, regular_start, overtime_start, late_overtime_start FROM m_shifts WHERE id IN ($in)";
        foreach ($dbh->query($sqlSh) as $r) {
            $shiftMap[(int)$r['id']] = [
                'regular_start'       => (string)$r['regular_start'],
                'overtime_start'      => (string)$r['overtime_start'],
                'late_overtime_start' => (string)$r['late_overtime_start'],
            ];
        }
    }

    // ===== 当月の全レコード =====
    $inUserIds = implode(',', array_map('intval', array_column($users, 'id')));
    $sql = "
        SELECT user_id, work_date, start_time, finish_time, is_canceled,
               payment1_id, amount1, payment2_id, amount2, payment3_id, amount3, payment4_id, amount4, payment5_id, amount5
          FROM t_work_reports
         WHERE user_id IN ($inUserIds)
           AND work_date BETWEEN :df AND :dt
         ORDER BY user_id ASC, work_date ASC, id ASC
    ";
    $st = $dbh->prepare($sql);
    $st->bindValue(':df', $dateFrom);
    $st->bindValue(':dt', $dateTo);
    $st->execute();
    $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

    // ===== 立替で使用された payment_id を収集（列動的生成用） =====
    $paymentIdSet = [];
    foreach ($rows as $r) {
        for ($i=1;$i<=5;$i++) {
            $pid = $r["payment{$i}_id"] ?? null;
            if ($pid !== null && $pid !== '' && is_numeric($pid)) {
                $paymentIdSet[(int)$pid] = true;
            }
        }
    }
    $paymentIds = array_keys($paymentIdSet);
    sort($paymentIds);

    // ===== 支払マスタ names =====
    $paymentName = []; // id => name
    if ($paymentIds) {
        $in = implode(',', array_map('intval', $paymentIds));
        $sqlP = "SELECT id, name FROM m_payments WHERE id IN ($in)";
        foreach ($dbh->query($sqlP) as $p) {
            $paymentName[(int)$p['id']] = (string)$p['name'];
        }
        foreach ($paymentIds as $pid) {
            if (!isset($paymentName[$pid])) $paymentName[$pid] = "ID{$pid}";
        }
    }

    // ===== 集計（月合計のみ。キーは氏名で出力） =====
    $summary = [];   // user_id => {...}
    foreach ($rows as $r) {
        $uid = (int)$r['user_id'];
        if (!isset($summary[$uid])) {
            $payCols = [];
            foreach ($paymentIds as $pid) $payCols[$pid] = 0;

            $summary[$uid] = array_merge([
                'name'        => $userName[$uid] ?? ("ID:{$uid}"),
                'days'        => 0,
                'worked_min'  => 0,
                'early_min'   => 0,
                'overtime_min'=> 0,
                'midnight_min'=> 0,
            ], ['payments'=>$payCols]);
        }

        $isCanceled = (int)($r['is_canceled'] ?? 0);
        $stt = (string)($r['start_time'] ?? '');
        $fin = (string)($r['finish_time'] ?? '');

        // 出社日数：中止でなく、出勤・退勤が両方ある日
        if (!$isCanceled && $stt !== '' && $fin !== '') {
            $summary[$uid]['days']++;
        }

        // 実働分
        $workedMin = 0;
        if (!$isCanceled && $stt !== '' && $fin !== '') {
            $baseStart = strtotime($r['work_date'].' '.substr($stt,0,5));
            $baseFinish= strtotime($r['work_date'].' '.substr($fin,0,5));
            if ($baseFinish <= $baseStart) $baseFinish += 24*60*60;
            $diff = ($baseFinish - $baseStart)/60;
            if (is_finite($diff) && $diff > 0) $workedMin = (int)$diff;
        }

        // シフト時間（早出/残業/深夜）
        $shiftId = $userShiftId[$uid] ?? 0;
        $shiftRow = $shiftId ? ($shiftMap[$shiftId] ?? null) : null;
        [$earlyMin, $otMin, $lateMin] = calc_parts($shiftRow, $stt, $fin);

        // 丸め
        if ($roundUnit > 0) {
            $workedMin = round_minutes($workedMin, $roundUnit);
            $earlyMin  = round_minutes($earlyMin,  $roundUnit);
            $otMin     = round_minutes($otMin,     $roundUnit);
            $lateMin   = round_minutes($lateMin,   $roundUnit);
        }

        $summary[$uid]['worked_min']   += $workedMin;
        $summary[$uid]['early_min']    += $earlyMin;
        $summary[$uid]['overtime_min'] += $otMin;
        $summary[$uid]['midnight_min'] += $lateMin;

        // 立替種類ごとの合計
        for ($i=1;$i<=5;$i++) {
            $pid = $r["payment{$i}_id"] ?? null;
            $amt = (int)($r["amount{$i}"] ?? 0);
            if ($pid !== null && $pid !== '' && is_numeric($pid) && isset($summary[$uid]['payments'][(int)$pid])) {
                $summary[$uid]['payments'][(int)$pid] += $amt;
            }
        }
    }

    // ===== PhpSpreadsheet あり → XLSX（1シートのみ） =====
    if (hasSpreadsheet()) {
        $ss = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
        $ss->getProperties()->setCreator('Report System')->setTitle("WorkReport Summary {$ym}");

        $s1 = $ss->getActiveSheet();
        $s1->setTitle('社員別サマリ');
        $s1->setCellValue('A1', "作業日報 集計（{$ym}）");

        // ヘッダ動的（氏名 + 固定列 + 立替種類列）
        $fixedHeader = ['氏名','出社日数','作業時間(h)','早出(h)','残業(h)','深夜(h)'];
        $payHeader   = array_map(function($pid) use ($paymentName){ return '立替:'.$paymentName[$pid]; }, $paymentIds);
        $header = array_merge($fixedHeader, $payHeader);
        $s1->fromArray([$header], null, 'A3', true);

        // タイトル結合
        $lastColIdx = count($header);
        $lastCell = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($lastColIdx);
        $s1->mergeCells("A1:{$lastCell}1");
        $s1->getStyle('A1')->getFont()->setBold(true)->setSize(14);

        // 本体
        $rIdx = 4;
        $sumDays=0; $sumWorked=0; $sumEarly=0; $sumOT=0; $sumMid=0;
        $sumPay = array_fill_keys($paymentIds, 0);
        foreach ($summary as $uid => $u) {
            $row = [
                $u['name'],
                (int)$u['days'],
                round($u['worked_min']/60, 2),
                round($u['early_min']/60, 2),
                round($u['overtime_min']/60, 2),
                round($u['midnight_min']/60, 2),
            ];
            foreach ($paymentIds as $pid) {
                $val = (int)($u['payments'][$pid] ?? 0);
                $row[] = $val;
                $sumPay[$pid] += $val;
            }
            $s1->fromArray([$row], null, "A{$rIdx}", true);
            $rIdx++;

            $sumDays   += (int)$u['days'];
            $sumWorked += (int)$u['worked_min'];
            $sumEarly  += (int)$u['early_min'];
            $sumOT     += (int)$u['overtime_min'];
            $sumMid    += (int)$u['midnight_min'];
        }
        // 合計行
        $sumRow = ['合計', $sumDays, round($sumWorked/60,2), round($sumEarly/60,2), round($sumOT/60,2), round($sumMid/60,2)];
        foreach ($paymentIds as $pid) $sumRow[] = (int)$sumPay[$pid];
        $s1->fromArray([$sumRow], null, "A{$rIdx}", true);

        // 罫線・幅・オートフィルタ
        $range = "A3:{$lastCell}{$rIdx}";
        $s1->getStyle($range)->getBorders()->getAllBorders()
           ->setBorderStyle(\PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN)->getColor()->setRGB('666666');
        $s1->getStyle("A3:{$lastCell}3")->getFont()->setBold(true);

        // 列幅（ざっくり）
        $s1->getColumnDimension('A')->setWidth(22); // 氏名
        $s1->getColumnDimension('B')->setWidth(10);
        $s1->getColumnDimension('C')->setWidth(14);
        $s1->getColumnDimension('D')->setWidth(10);
        $s1->getColumnDimension('E')->setWidth(10);
        $s1->getColumnDimension('F')->setWidth(10);
        for ($i=7; $i<=$lastColIdx; $i++) {
            $s1->getColumnDimensionByColumn($i)->setWidth(18);
        }
        $s1->setAutoFilter("A3:{$lastCell}".($rIdx-1));

        // 印刷設定
        $s1->getPageSetup()
           ->setOrientation(\PhpOffice\PhpSpreadsheet\Worksheet\PageSetup::ORIENTATION_LANDSCAPE)
           ->setFitToWidth(1)->setFitToHeight(0);

        // ダウンロード
        $filename = "当月集計_{$ym}.xlsx";
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="'.$filename.'"');
        header('Cache-Control: max-age=0');
        (new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($ss))->save('php://output');
        exit;
    }

    // ===== フォールバック: HTML(Excel互換)（1テーブルのみ） =====
    $filename = "work_report_summary_{$ym}.xls";
    header('Content-Type: application/vnd.ms-excel; charset=UTF-8');
    header('Content-Disposition: attachment; filename="'.$filename.'"');
    echo "\xEF\xBB\xBF";
    ?>
<!DOCTYPE html>
<html lang="ja">
<head>
<meta charset="UTF-8" />
<title>作業日報 集計（<?php echo htmlspecialchars($ym, ENT_QUOTES, 'UTF-8'); ?>）</title>
<style>
  body { font-family: sans-serif; }
  table { border-collapse: collapse; margin-bottom: 18px; }
  th, td { border: 1px solid #666; padding: 4px 6px; }
  th { background: #eee; }
</style>
</head>
<body>
<h3>社員別サマリ（<?php echo htmlspecialchars($ym, ENT_QUOTES, 'UTF-8'); ?>）</h3>
<table>
  <thead>
    <tr>
      <th>氏名</th><th>出社日数</th><th>作業時間(h)</th><th>早出(h)</th><th>残業(h)</th><th>深夜(h)</th>
<?php foreach ($paymentIds as $pid): ?>
      <th><?php echo htmlspecialchars('立替:'.$paymentName[$pid], ENT_QUOTES, 'UTF-8'); ?></th>
<?php endforeach; ?>
    </tr>
  </thead>
  <tbody>
<?php
$sumDays=0; $sumWorked=0; $sumEarly=0; $sumOT=0; $sumMid=0;
$sumPay = array_fill_keys($paymentIds, 0);
foreach ($summary as $u):
  $sumDays   += (int)$u['days'];
  $sumWorked += (int)$u['worked_min'];
  $sumEarly  += (int)$u['early_min'];
  $sumOT     += (int)$u['overtime_min'];
  $sumMid    += (int)$u['midnight_min'];
?>
    <tr>
      <td><?php echo htmlspecialchars($u['name'], ENT_QUOTES, 'UTF-8'); ?></td>
      <td><?php echo (int)$u['days']; ?></td>
      <td><?php echo number_format(round($u['worked_min']/60, 2), 2); ?></td>
      <td><?php echo number_format(round($u['early_min']/60, 2), 2); ?></td>
      <td><?php echo number_format(round($u['overtime_min']/60, 2), 2); ?></td>
      <td><?php echo number_format(round($u['midnight_min']/60, 2), 2); ?></td>
<?php foreach ($paymentIds as $pid):
  $v = (int)($u['payments'][$pid] ?? 0); $sumPay[$pid] += $v; ?>
      <td><?php echo $v; ?></td>
<?php endforeach; ?>
    </tr>
<?php endforeach; ?>
    <tr>
      <th>合計</th>
      <th><?php echo (int)$sumDays; ?></th>
      <th><?php echo number_format(round($sumWorked/60, 2), 2); ?></th>
      <th><?php echo number_format(round($sumEarly/60, 2), 2); ?></th>
      <th><?php echo number_format(round($sumOT/60, 2), 2); ?></th>
      <th><?php echo number_format(round($sumMid/60, 2), 2); ?></th>
<?php foreach ($paymentIds as $pid): ?>
      <th><?php echo (int)$sumPay[$pid]; ?></th>
<?php endforeach; ?>
    </tr>
  </tbody>
</table>
</body>
</html>
<?php
    exit;

} catch (Throwable $e) {
    header('Content-Type: application/json; charset=utf-8');
    http_response_code(500);
    echo json_encode(['success'=>false,'message'=>$e->getMessage()], JSON_UNESCAPED_UNICODE);
}

<?php
/**
 * 作業日報（エクスポート）
 *
 * 既存互換:
 *  - type 未指定（または user）: 個人・当月（user_id 必須）
 *
 * 追加:
 *  - type=site : 現場別（A形式）※管理者のみ
 *  - type=date : 日付別（※管理者のみ）
 *
 * 条件（複合可）:
 *  - cond_overtime=1    残業
 *  - cond_midnight=1    深夜
 *  - cond_legal_sun=1   法定休日（日曜のみ）
 *  - cond_company=1     社内休日
 *  - cond_mode=and|or   デフォルト and
 *
 * 休日判定:
 *  - t_calendars.status=1 社内休日
 *  - t_calendars.status=2 法定休日
 *
 * 現場:
 *  - on_site_id  : 現場1
 *  - on_site_id2 : 現場2（途中で現場が変わるケース）
 */

require_once dirname(__DIR__, 1) . '/common/cors.php';
require_once dirname(__DIR__, 1) . '/common/db_manager.php';

/** ===== 共通ヘルパ ===== */
function norm_int($v, $def = 0){ return (isset($v) && $v !== '' && is_numeric($v)) ? (int)$v : $def; }
function norm_month($s){ return (isset($s) && $s !== '' && preg_match('/^\d{4}-\d{2}$/', (string)$s)) ? (string)$s : null; }
function norm_date($s){ return (isset($s) && $s !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$s)) ? (string)$s : null; }
function norm_bool($v): bool { return !empty($v) && (string)$v !== '0'; }

function json_out(int $code, array $payload) {
  http_response_code($code);
  header('Content-Type: application/json; charset=utf-8');
  echo json_encode($payload, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
  exit;
}

/** PhpSpreadsheet 利用可否 */
function hasSpreadsheet(): bool {
  try {
    foreach ([dirname(__DIR__,1).'/vendor/autoload.php', dirname(__DIR__,2).'/vendor/autoload.php'] as $p) {
      if (is_file($p)) { require_once $p; break; }
    }
    return class_exists(\PhpOffice\PhpSpreadsheet\Spreadsheet::class);
  } catch (Throwable $e) { return false; }
}

/** 対象月の開始/終了日 */
function month_range(string $ym): array {
  $d = DateTime::createFromFormat('Y-m-d', $ym.'-01') ?: new DateTime('first day of this month');
  return [$d->format('Y-m-01'), $d->format('Y-m-t')];
}

/** ファイル名に使えない文字を除去 */
function sanitize_filename(string $s, string $fallback = 'user'): string {
  $s = trim($s);
  $s = preg_replace('/[\x00-\x1F\x7F\\\\\/\?\*\:\|\"\<\>]/u', '', $s);
  return $s === '' ? $fallback : $s;
}

/** クエリ配列のうち成功した最初の結果を {id=>label} で返す */
function fetch_map(PDO $dbh, array $queries, string $key = 'id', string $label = 'label'): array {
  foreach ($queries as $sql) {
    try {
      $st = $dbh->query($sql);
      if (!$st) continue;
      $m = [];
      while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
        if (!isset($r[$key])) continue;
        $m[(int)$r[$key]] = (string)$r[$label];
      }
      if ($m) return $m;
    } catch (Throwable $e) { /* 次候補へ */ }
  }
  return [];
}

function wday_ja(DateTime $dt): string {
  return ['日','月','火','水','木','金','土'][(int)$dt->format('w')];
}

function dayjs_wday_ja(string $dateYmd): string {
  $dt = DateTime::createFromFormat('Y-m-d', $dateYmd);
  if (!$dt) return '';
  return ['日','月','火','水','木','金','土'][(int)$dt->format('w')];
}

function hm_to_min($s) {
  if (!is_string($s) || !preg_match('/^\d{2}:\d{2}/', $s)) return null;
  $h = (int)substr($s,0,2);
  $m = (int)substr($s,3,2);
  return $h * 60 + $m;
}

function min_to_hour_int($min): int {
  $min = (int)$min;
  if ($min <= 0) return 0;
  // 小数は出さずに「時間」を整数化（端数30分などがあるなら要件に合わせて変更）
  return (int)ceil($min / 60);
}

/**
 * 残業/深夜（分）を算出
 * - overtime_start / late_overtime_start を基準
 * - midnight は overtime に含まれるので、overtime_excl_midnight も併せて返す
 */
function calc_ot_midnight_minutes(?array $shiftRow, string $startHHMM, string $finishHHMM): array {
  $st = hm_to_min($startHHMM);
  $ft = hm_to_min($finishHHMM);
  if ($st === null || $ft === null) return ['total'=>0,'overtime'=>0,'midnight'=>0,'overtime_excl_midnight'=>0];

  if ($ft <= $st) $ft += 24*60; // 日跨ぎ

  $mOT   = null;
  $mLate = null;
  if ($shiftRow) {
    if (!empty($shiftRow['overtime_start']))      $mOT   = hm_to_min(substr((string)$shiftRow['overtime_start'],0,5));
    if (!empty($shiftRow['late_overtime_start'])) $mLate = hm_to_min(substr((string)$shiftRow['late_overtime_start'],0,5));
  }

  $overtime = ($mOT !== null)   ? max(0, $ft - max($st, $mOT))   : 0;
  $midnight = ($mLate !== null) ? max(0, $ft - max($st, $mLate)) : 0;

  $overtimeEx = max(0, $overtime - $midnight);
  return ['total'=>max(0,$ft-$st),'overtime'=>$overtime,'midnight'=>$midnight,'overtime_excl_midnight'=>$overtimeEx];
}

/** 条件判定（セル） */
function cell_matches(array $cell, array $cond, string $mode): bool {
  $checks = [];

  if (!empty($cond['overtime'])) {
    $checks[] = ((int)($cell['overtime_h'] ?? 0) > 0);
  }
  if (!empty($cond['midnight'])) {
    $checks[] = ((int)($cell['midnight_h'] ?? 0) > 0);
  }
  if (!empty($cond['legal_sun'])) {
    $checks[] = ((int)($cell['holiday_status'] ?? 0) === 2 && ($cell['wday'] ?? '') === '日');
  }
  if (!empty($cond['company'])) {
    $checks[] = ((int)($cell['holiday_status'] ?? 0) === 1);
  }

  if (!$checks) return true; // 条件指定なし＝全件

  $mode = strtolower($mode) === 'or' ? 'or' : 'and';
  return ($mode === 'or')
    ? in_array(true, $checks, true)
    : !in_array(false, $checks, true);
}

/** ===== セッション認証（管理者判定に使用） ===== */
const SESSION_NAME  = 'REPORTSESSID';

function ensure_session_started() {
  if (session_status() !== PHP_SESSION_ACTIVE) {
    session_name(SESSION_NAME);
    session_start();
  }
}

function require_admin(PDO $dbh): array {
  ensure_session_started();
  $auth = $_SESSION['auth'] ?? null;
  if (!$auth || empty($auth['id'])) {
    json_out(401, ['success'=>false,'message'=>'未ログインです']);
  }
  $uid = (int)$auth['id'];

  $stmt = $dbh->prepare('SELECT id, name, is_authorized FROM m_users WHERE id = :id LIMIT 1');
  $stmt->bindValue(':id', $uid, PDO::PARAM_INT);
  $stmt->execute();
  $me = $stmt->fetch(PDO::FETCH_ASSOC);

  $isAuthorized = !empty($me['is_authorized']);
  if (!$isAuthorized) {
    json_out(403, ['success'=>false,'message'=>'権限がありません（管理者のみ）']);
  }
  return ['id'=>$uid, 'name'=>(string)($me['name'] ?? '')];
}

try {
  $type = strtolower((string)($_GET['type'] ?? 'user'));
  if ($type === '') $type = 'user';

  $ym = norm_month($_GET['ym'] ?? null) ?? date('Y-m');
  [$dateFrom, $dateTo] = month_range($ym);

  // 条件
  $cond = [
    'overtime'  => norm_bool($_GET['cond_overtime'] ?? 0),
    'midnight'  => norm_bool($_GET['cond_midnight'] ?? 0),
    'legal_sun' => norm_bool($_GET['cond_legal_sun'] ?? 0),
    'company'   => norm_bool($_GET['cond_company'] ?? 0),
  ];
  $condMode = strtolower((string)($_GET['cond_mode'] ?? 'and'));
  if ($condMode !== 'or') $condMode = 'and';

  // 現場2も拾うか（type=site のみ使用。デフォルトOFFで既存互換）
  $includeSite2 = norm_bool($_GET['include_site2'] ?? 0);

  $dbh = getDb();

  /** ===== マスタMAP ===== */
  $siteMap = fetch_map($dbh, [
    "SELECT id, name AS label FROM m_on_sites",
    "SELECT id, name AS label FROM m_sites",
  ]);
  $userNameMap = fetch_map($dbh, [
    "SELECT id, name AS label FROM m_users",
  ]);

  /** ======= type=user（既存互換） ======= */
  if ($type === 'user') {
    $userId = norm_int($_GET['user_id'] ?? null, 0);
    if ($userId <= 0) {
      json_out(400, ['success'=>false, 'message'=>'user_id は必須です']);
    }

    // ユーザー情報（休日区分に使用）
    $stmt = $dbh->prepare("SELECT id, name, COALESCE(nationality_id,1) AS nationality_id FROM m_users WHERE id = :id");
    $stmt->bindValue(':id', $userId, PDO::PARAM_INT);
    $stmt->execute();
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$user) json_out(404, ['success'=>false, 'message'=>'ユーザーが見つかりません']);

    $userName = (string)$user['name'];
    $safeName = sanitize_filename($userName, "user{$userId}");
    $localeType = ((int)$user['nationality_id'] === 2) ? 2 : 1;

    // 車両/支払い
    $vehicleMap = fetch_map($dbh, [
      "SELECT id, COALESCE(number, name, plate_number) AS label FROM m_vehicles",
    ]);
    $paymentMap = fetch_map($dbh, [
      "SELECT id, name AS label FROM m_payments",
      "SELECT id, name AS label FROM m_payment_kinds",
    ]);

    // 休日マップ（the_date => status）
    $stmt = $dbh->prepare("
      SELECT the_date, status
      FROM t_calendars
      WHERE locale_type = :lt AND the_date BETWEEN :f AND :t
    ");
    $stmt->bindValue(':lt', $localeType, PDO::PARAM_INT);
    $stmt->bindValue(':f', $dateFrom);
    $stmt->bindValue(':t', $dateTo);
    $stmt->execute();
    $holidayMap = [];
    while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
      $holidayMap[(string)$r['the_date']] = (int)$r['status']; // 1=社内,2=法定
    }

    // 有給
    $paidSet = [];
    try {
      $stp = $dbh->prepare("
        SELECT leave_date
        FROM t_paid_leaves
        WHERE user_id = :uid AND leave_date BETWEEN :f AND :t
      ");
      $stp->bindValue(':uid', $userId, PDO::PARAM_INT);
      $stp->bindValue(':f', $dateFrom);
      $stp->bindValue(':t', $dateTo);
      $stp->execute();
      while ($p = $stp->fetch(PDO::FETCH_ASSOC)) {
        if (!empty($p['leave_date'])) $paidSet[(string)$p['leave_date']] = true;
      }
    } catch (Throwable $e) { $paidSet = []; }

    // 日報（入力ある行）
    $stmt = $dbh->prepare("
      SELECT
        id, user_id, work_date, start_time, finish_time,
        on_site_id, on_site_id2,
        work,
        is_canceled, alcohol_checked, condition_checked,
        vehicle_id,
        payment1_id, amount1,
        payment2_id, amount2,
        payment3_id, amount3,
        payment4_id, amount4,
        payment5_id, amount5,
        created_at, updated_at
      FROM t_work_reports
      WHERE user_id = :uid AND work_date BETWEEN :df AND :dt
      ORDER BY work_date ASC, id ASC
    ");
    $stmt->bindValue(':uid', $userId, PDO::PARAM_INT);
    $stmt->bindValue(':df', $dateFrom);
    $stmt->bindValue(':dt', $dateTo);
    $stmt->execute();
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    // 月全日ベース（ここは既存互換：必ず全日出す）
    $baseMap = [];
    $cur = new DateTime($dateFrom);
    $end = new DateTime($dateTo);
    while ($cur <= $end) {
      $d = $cur->format('Y-m-d');
      $w = wday_ja($cur);

      $kind = '';
      if (!empty($paidSet[$d])) {
        $kind = '有給休暇';
      } else {
        $hs = (int)($holidayMap[$d] ?? 0);
        if ($hs === 1) $kind = '社内休日';
        if ($hs === 2) $kind = '法定休日';
      }

      $baseMap[$d] = [
        'date'=>$d,'wday'=>$w,'kind'=>$kind,
        'start'=>'','finish'=>'','canceled'=>'','alcohol'=>'','condition'=>'',
        'site'=>'','site2'=>'','work'=>'','vehicle'=>'',
        'p1'=>'','a1'=>0,'p2'=>'','a2'=>0,'p3'=>'','a3'=>0,'p4'=>'','a4'=>0,'p5'=>'','a5'=>0,
        'sum'=>0,
        'holiday_status'=>(int)($holidayMap[$d] ?? 0),
        'is_paid'=>!empty($paidSet[$d]) ? 1 : 0,
      ];
      $cur->modify('+1 day');
    }

    $sumExpense = 0;
    foreach ($rows as $r) {
      $d = (string)$r['work_date'];
      if (!isset($baseMap[$d])) continue;

      $st  = (string)($r['start_time'] ?? '');
      $ft  = (string)($r['finish_time'] ?? '');
      $amt = (int)($r['amount1'] ?? 0)
           + (int)($r['amount2'] ?? 0)
           + (int)($r['amount3'] ?? 0)
           + (int)($r['amount4'] ?? 0)
           + (int)($r['amount5'] ?? 0);
      $sumExpense += $amt;

      $siteLabel = '';
      if ($r['on_site_id'] !== null && $r['on_site_id'] !== '') {
        $sid = (int)$r['on_site_id'];
        $siteLabel = $siteMap[$sid] ?? (string)$r['on_site_id'];
      }

      $siteLabel2 = '';
      if (array_key_exists('on_site_id2', $r) && $r['on_site_id2'] !== null && $r['on_site_id2'] !== '') {
        $sid2 = (int)$r['on_site_id2'];
        $siteLabel2 = $siteMap[$sid2] ?? (string)$r['on_site_id2'];
      }

      $vehicleLabel = '';
      if ($r['vehicle_id'] !== null && $r['vehicle_id'] !== '') {
        $vid = (int)$r['vehicle_id'];
        $vehicleLabel = $vehicleMap[$vid] ?? (string)$r['vehicle_id'];
      }

      $p1 = ($r['payment1_id'] !== null && $r['payment1_id'] !== '') ? ($paymentMap[(int)$r['payment1_id']] ?? (string)$r['payment1_id']) : '';
      $p2 = ($r['payment2_id'] !== null && $r['payment2_id'] !== '') ? ($paymentMap[(int)$r['payment2_id']] ?? (string)$r['payment2_id']) : '';
      $p3 = ($r['payment3_id'] !== null && $r['payment3_id'] !== '') ? ($paymentMap[(int)$r['payment3_id']] ?? (string)$r['payment3_id']) : '';
      $p4 = ($r['payment4_id'] !== null && $r['payment4_id'] !== '') ? ($paymentMap[(int)$r['payment4_id']] ?? (string)$r['payment4_id']) : '';
      $p5 = ($r['payment5_id'] !== null && $r['payment5_id'] !== '') ? ($paymentMap[(int)$r['payment5_id']] ?? (string)$r['payment5_id']) : '';

      $baseMap[$d] = array_merge($baseMap[$d], [
        'start'  => $st ? substr($st, 0, 5) : '',
        'finish' => $ft ? substr($ft, 0, 5) : '',
        'canceled'  => (int)$r['is_canceled'] ? '中止' : '',
        'alcohol'   => (int)$r['alcohol_checked'] ? '済' : '',
        'condition' => (int)$r['condition_checked'] ? '済' : '',
        'site'      => $siteLabel,
        'site2'     => $siteLabel2,
        'work'      => (string)($r['work'] ?? ''),
        'vehicle'   => $vehicleLabel,
        'p1'=>$p1,'a1'=>(int)($r['amount1'] ?? 0),
        'p2'=>$p2,'a2'=>(int)($r['amount2'] ?? 0),
        'p3'=>$p3,'a3'=>(int)($r['amount3'] ?? 0),
        'p4'=>$p4,'a4'=>(int)($r['amount4'] ?? 0),
        'p5'=>$p5,'a5'=>(int)($r['amount5'] ?? 0),
        'sum'=>$amt,
      ]);
    }

    $outRows = array_values($baseMap);
    usort($outRows, fn($a,$b)=>strcmp($a['date'],$b['date']));

    // ===== XLSX =====
    if (hasSpreadsheet()) {
      $ss = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
      $ss->getProperties()->setCreator('Report System')->setTitle("WorkReport {$ym}");
      $sheet = $ss->getActiveSheet();
      $sheet->setTitle('作業日報');

      $title = sprintf('作業日報（%s / %s）', $userName, $ym);
      $sheet->setCellValue('A1', $title);
      $sheet->mergeCells('A1:W1');
      $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(14);

      // ★ 現場2 追加
      $headers = [
        '日付','曜','区分','出勤','退勤','中止','アルコール','体調',
        '現場','現場2','作業内容','車両',
        '支払1','金額1','支払2','金額2','支払3','金額3','支払4','金額4','支払5','金額5',
        '合計(円)'
      ];
      $sheet->fromArray([$headers], null, 'A3', true);

      // 幅（現場2ぶん追加）
      $widths = [12,5,10,8,8,8,9,8,18,18,28,12, 12,10,12,10,12,10,12,10,12,10, 12];
      foreach ($widths as $i => $w) { $sheet->getColumnDimensionByColumn($i+1)->setWidth($w); }

      $border = ['borders'=>['allBorders'=>['borderStyle'=>\PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN,'color'=>['rgb'=>'666666']]]];
      $fillHoliday1 = ['fillType'=>\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID,'startColor'=>['rgb'=>'D9F2FF']]; // 社内
      $fillHoliday2 = ['fillType'=>\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID,'startColor'=>['rgb'=>'FFD6D0']]; // 法定
      $fillPaid     = $fillHoliday2;
      $fontSun = ['font'=>['color'=>['rgb'=>'DD3333']]];
      $fontSat = ['font'=>['color'=>['rgb'=>'3367CC']]];

      $rowIdx = 4;
      foreach ($outRows as $r) {
        $sheet->fromArray([[
          $r['date'], $r['wday'], $r['kind'], $r['start'], $r['finish'], $r['canceled'], $r['alcohol'], $r['condition'],
          $r['site'], $r['site2'], $r['work'], $r['vehicle'],
          $r['p1'], $r['a1'], $r['p2'], $r['a2'], $r['p3'], $r['a3'], $r['p4'], $r['a4'], $r['p5'], $r['a5'],
          $r['sum'],
        ]], null, "A{$rowIdx}", true);

        if (!empty($r['is_paid'])) {
          $sheet->getStyle("A{$rowIdx}:W{$rowIdx}")->getFill()->applyFromArray($fillPaid);
        } elseif ($r['holiday_status'] === 1) {
          $sheet->getStyle("A{$rowIdx}:W{$rowIdx}")->getFill()->applyFromArray($fillHoliday1);
        } elseif ($r['holiday_status'] === 2) {
          $sheet->getStyle("A{$rowIdx}:W{$rowIdx}")->getFill()->applyFromArray($fillHoliday2);
        }

        if ($r['wday'] === '日') $sheet->getStyle("B{$rowIdx}")->applyFromArray($fontSun);
        if ($r['wday'] === '土') $sheet->getStyle("B{$rowIdx}")->applyFromArray($fontSat);

        $sheet->getStyle("A{$rowIdx}:W{$rowIdx}")->applyFromArray($border);
        $rowIdx++;
      }

      $sheet->getStyle("A3:W3")->applyFromArray($border);
      $sheet->getStyle("A3:W3")->getFont()->setBold(true);

      // 合計行（★ 0固定をやめて既存互換に戻す）
      $sheet->setCellValue("A{$rowIdx}", '合計');
      $sheet->mergeCells("A{$rowIdx}:V{$rowIdx}");
      $sheet->setCellValue("W{$rowIdx}", (int)$sumExpense);
      $sheet->getStyle("A{$rowIdx}:W{$rowIdx}")->applyFromArray($border);
      $sheet->getStyle("A{$rowIdx}")->getFont()->setBold(true);

      $filenameUtf8  = "作業日報_{$safeName}_{$ym}.xlsx";
      $filenameAscii = "work_report_{$userId}_{$ym}.xlsx";
      header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
      header('Content-Disposition: attachment; filename="'.$filenameAscii.'"; filename*=UTF-8\'\''.rawurlencode($filenameUtf8));
      header('Cache-Control: max-age=0');
      (new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($ss))->save('php://output');
      exit;
    }

    // ===== フォールバック: HTML(Excel互換) =====
    $filenameUtf8  = "作業日報_{$safeName}_{$ym}.xls";
    $filenameAscii = "work_report_{$userId}_{$ym}.xls";
    header('Content-Type: application/vnd.ms-excel; charset=UTF-8');
    header('Content-Disposition: attachment; filename="'.$filenameAscii.'"; filename*=UTF-8\'\''.rawurlencode($filenameUtf8));
    echo "\xEF\xBB\xBF";
    ?>
<!DOCTYPE html>
<html lang="ja">
<head>
<meta charset="UTF-8" />
<title>作業日報（<?php echo htmlspecialchars($userName, ENT_QUOTES, 'UTF-8'); ?> / <?php echo htmlspecialchars($ym, ENT_QUOTES, 'UTF-8'); ?>）</title>
<style>
  body { font-family: sans-serif; }
  table { border-collapse: collapse; }
  th, td { border: 1px solid #666; padding: 4px 6px; }
  th { background: #eee; }
  .sun { color: #d33; } .sat { color: #3367cc; }
  .comp { background: #d9f2ff; } .legal { background: #ffd6d0; }
  .paid { background: #ffd6d0; }
</style>
</head>
<body>
<h3>作業日報（<?php echo htmlspecialchars($userName, ENT_QUOTES, 'UTF-8'); ?> / <?php echo htmlspecialchars($ym, ENT_QUOTES, 'UTF-8'); ?>）</h3>
<table>
  <thead>
    <tr>
      <th>日付</th><th>曜</th><th>区分</th><th>出勤</th><th>退勤</th><th>中止</th><th>アルコール</th><th>体調</th>
      <th>現場</th><th>現場2</th><th>作業内容</th><th>車両</th>
      <th>支払1</th><th>金額1</th><th>支払2</th><th>金額2</th><th>支払3</th><th>金額3</th><th>支払4</th><th>金額4</th><th>支払5</th><th>金額5</th>
      <th>合計(円)</th>
    </tr>
  </thead>
  <tbody>
  <?php foreach ($outRows as $r):
    $cls = !empty($r['is_paid']) ? 'paid' : (($r['holiday_status']===2)?'legal':(($r['holiday_status']===1)?'comp':'')); ?>
    <tr class="<?php echo $cls; ?>">
      <td><?php echo htmlspecialchars($r['date'], ENT_QUOTES, 'UTF-8'); ?></td>
      <td class="<?php echo $r['wday']==='日'?'sun':($r['wday']==='土'?'sat':''); ?>"><?php echo htmlspecialchars($r['wday'], ENT_QUOTES, 'UTF-8'); ?></td>
      <td><?php echo htmlspecialchars($r['kind'], ENT_QUOTES, 'UTF-8'); ?></td>
      <td><?php echo htmlspecialchars($r['start'], ENT_QUOTES, 'UTF-8'); ?></td>
      <td><?php echo htmlspecialchars($r['finish'], ENT_QUOTES, 'UTF-8'); ?></td>
      <td><?php echo htmlspecialchars($r['canceled'], ENT_QUOTES, 'UTF-8'); ?></td>
      <td><?php echo htmlspecialchars($r['alcohol'], ENT_QUOTES, 'UTF-8'); ?></td>
      <td><?php echo htmlspecialchars($r['condition'], ENT_QUOTES, 'UTF-8'); ?></td>
      <td><?php echo htmlspecialchars($r['site'], ENT_QUOTES, 'UTF-8'); ?></td>
      <td><?php echo htmlspecialchars($r['site2'], ENT_QUOTES, 'UTF-8'); ?></td>
      <td><?php echo htmlspecialchars($r['work'], ENT_QUOTES, 'UTF-8'); ?></td>
      <td><?php echo htmlspecialchars($r['vehicle'], ENT_QUOTES, 'UTF-8'); ?></td>

      <td><?php echo htmlspecialchars($r['p1'], ENT_QUOTES, 'UTF-8'); ?></td><td><?php echo (int)$r['a1']; ?></td>
      <td><?php echo htmlspecialchars($r['p2'], ENT_QUOTES, 'UTF-8'); ?></td><td><?php echo (int)$r['a2']; ?></td>
      <td><?php echo htmlspecialchars($r['p3'], ENT_QUOTES, 'UTF-8'); ?></td><td><?php echo (int)$r['a3']; ?></td>
      <td><?php echo htmlspecialchars($r['p4'], ENT_QUOTES, 'UTF-8'); ?></td><td><?php echo (int)$r['a4']; ?></td>
      <td><?php echo htmlspecialchars($r['p5'], ENT_QUOTES, 'UTF-8'); ?></td><td><?php echo (int)$r['a5']; ?></td>
      <td><?php echo (int)$r['sum']; ?></td>
    </tr>
  <?php endforeach; ?>
    <tr>
      <td colspan="22" style="text-align:right;font-weight:bold;">合計</td>
      <td style="font-weight:bold;"><?php echo (int)$sumExpense; ?></td>
    </tr>
  </tbody>
</table>
</body>
</html>
<?php
    exit;
  }

  /** ======= type=site / type=date（管理者のみ） ======= */
  $me = require_admin($dbh);

  // shifts（全ユーザー用）
  $uRows = $dbh->query("SELECT id, name, shift_id FROM m_users ORDER BY id ASC")->fetchAll(PDO::FETCH_ASSOC) ?: [];
  $userShiftMap = [];
  $shiftIds = [];
  foreach ($uRows as $u) {
    $uid = (int)($u['id'] ?? 0);
    $sid = (int)($u['shift_id'] ?? 0);
    if ($uid > 0) $userShiftMap[$uid] = $sid;
    if ($sid > 0) $shiftIds[$sid] = true;
  }

  $shiftMap = [];
  if ($shiftIds) {
    $ids = implode(',', array_keys($shiftIds));
    $sRows = $dbh->query("SELECT id, regular_start, regular_finish, overtime_start, late_overtime_start FROM m_shifts WHERE id IN ($ids)")->fetchAll(PDO::FETCH_ASSOC) ?: [];
    foreach ($sRows as $s) $shiftMap[(int)$s['id']] = $s;
  }

  // 休日（locale_type は運用に合わせて。ここは従来通り 1 固定）
  $localeType = 1;
  $stmt = $dbh->prepare("
    SELECT the_date, status
    FROM t_calendars
    WHERE locale_type = :lt AND the_date BETWEEN :f AND :t
  ");
  $stmt->bindValue(':lt', $localeType, PDO::PARAM_INT);
  $stmt->bindValue(':f', $dateFrom);
  $stmt->bindValue(':t', $dateTo);
  $stmt->execute();
  $holidayMap = [];
  while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $holidayMap[(string)$r['the_date']] = (int)$r['status']; // 1=社内,2=法定
  }

  if ($type === 'date') {
    // 日付別：その日の全レコードを出す（条件は行単位で判定）
    $stmt = $dbh->prepare("
      SELECT
        r.work_date, r.user_id, r.on_site_id, r.on_site_id2, r.work, r.start_time, r.finish_time
      FROM t_work_reports r
      WHERE r.work_date BETWEEN :f AND :t
      ORDER BY r.work_date ASC, r.on_site_id ASC, r.user_id ASC, r.id ASC
    ");
    $stmt->bindValue(':f', $dateFrom);
    $stmt->bindValue(':t', $dateTo);
    $stmt->execute();
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    // 日付ごとにグループ
    $byDate = [];
    foreach ($rows as $r) {
      $d = (string)$r['work_date'];
      $uid = (int)$r['user_id'];
      $sid = (int)($r['on_site_id'] ?? 0);
      $sid2 = (int)($r['on_site_id2'] ?? 0);

      $w = dayjs_wday_ja($d);
      $hs = (int)($holidayMap[$d] ?? 0);

      // shift
      $shiftRow = null;
      $shiftId = (int)($userShiftMap[$uid] ?? 0);
      if ($shiftId > 0) $shiftRow = $shiftMap[$shiftId] ?? null;

      $st = (string)($r['start_time'] ?? '');
      $ft = (string)($r['finish_time'] ?? '');
      $st5 = $st ? substr($st,0,5) : '';
      $ft5 = $ft ? substr($ft,0,5) : '';
      $ot = calc_ot_midnight_minutes($shiftRow, $st5, $ft5);

      $cell = [
        'holiday_status'=>$hs,
        'wday'=>$w,
        'overtime_h'=>min_to_hour_int($ot['overtime_excl_midnight']),
        'midnight_h'=>min_to_hour_int($ot['midnight']),
      ];
      if (!cell_matches($cell, $cond, $condMode)) continue;

      $byDate[$d][] = [
        'date'=>$d,
        'wday'=>$w,
        'user'=>$userNameMap[$uid] ?? (string)$uid,
        'site'=>$siteMap[$sid] ?? ($sid ? (string)$sid : ''),
        'site2'=>$siteMap[$sid2] ?? ($sid2 ? (string)$sid2 : ''),
        'work'=>(string)($r['work'] ?? ''),
        'start'=>$st5,
        'finish'=>$ft5,
      ];
    }

    if (!hasSpreadsheet()) json_out(500, ['success'=>false,'message'=>'PhpSpreadsheet が必要です（date出力）']);

    $ss = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
    $ss->getProperties()->setCreator('Report System')->setTitle("WorkReport DATE {$ym}");
    $sheet = $ss->getActiveSheet();
    $sheet->setTitle('日付別');

    $sheet->setCellValue('A1', "日付別 作業日報（{$ym}）");
    $sheet->mergeCells('A1:F1');
    $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(14);

    $rIdx = 3;
    foreach ($byDate as $d => $list) {
      $dt = DateTime::createFromFormat('Y-m-d', $d);
      $w = $dt ? wday_ja($dt) : '';
      $sheet->setCellValue("A{$rIdx}", "{$d}（{$w}）");
      $sheet->mergeCells("A{$rIdx}:F{$rIdx}");
      $sheet->getStyle("A{$rIdx}")->getFont()->setBold(true);
      $rIdx++;

      // ★ 現場2追加
      $sheet->fromArray([['作業者','現場','現場2','作業','出社','退社']], null, "A{$rIdx}", true);
      $sheet->getStyle("A{$rIdx}:F{$rIdx}")->getFont()->setBold(true);
      $rIdx++;

      foreach ($list as $x) {
        $sheet->fromArray([[$x['user'],$x['site'],$x['site2'],$x['work'],$x['start'],$x['finish']]], null, "A{$rIdx}", true);
        $rIdx++;
      }
      $rIdx++; // 空行
    }

    foreach (['A'=>16,'B'=>18,'C'=>18,'D'=>30,'E'=>10,'F'=>10] as $col=>$w) {
      $sheet->getColumnDimension($col)->setWidth($w);
    }

    $filenameUtf8  = "作業日報_日付別_{$ym}.xlsx";
    $filenameAscii = "work_report_date_{$ym}.xlsx";
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="'.$filenameAscii.'"; filename*=UTF-8\'\''.rawurlencode($filenameUtf8));
    header('Cache-Control: max-age=0');
    (new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($ss))->save('php://output');
    exit;
  }

  if ($type === 'site') {
    $onSiteId = norm_int($_GET['on_site_id'] ?? null, 0);
    if ($onSiteId <= 0) json_out(400, ['success'=>false,'message'=>'type=site の場合 on_site_id は必須です']);

    $siteName = $siteMap[$onSiteId] ?? "現場{$onSiteId}";
    $safeSite = sanitize_filename($siteName, "site{$onSiteId}");

    // ★ 既存互換: デフォルトは on_site_id のみ
    // ★ include_site2=1 のときだけ on_site_id2 も拾う
    $whereSite = $includeSite2
      ? "(r.on_site_id = :sid OR r.on_site_id2 = :sid)"
      : "r.on_site_id = :sid";

    $stmt = $dbh->prepare("
      SELECT
        r.user_id, r.work_date, r.start_time, r.finish_time,
        r.is_canceled, r.work,
        r.on_site_id, r.on_site_id2
      FROM t_work_reports r
      WHERE {$whereSite}
        AND r.work_date BETWEEN :f AND :t
      ORDER BY r.user_id ASC, r.work_date ASC, r.id ASC
    ");
    $stmt->bindValue(':sid', $onSiteId, PDO::PARAM_INT);
    $stmt->bindValue(':f', $dateFrom);
    $stmt->bindValue(':t', $dateTo);
    $stmt->execute();
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    // 月の日付配列
    $dates = [];
    $cur = new DateTime($dateFrom);
    $end = new DateTime($dateTo);
    while ($cur <= $end) {
      $dates[] = $cur->format('Y-m-d');
      $cur->modify('+1 day');
    }

    // user_id -> shiftRow
    $userShiftRow = [];
    foreach ($userShiftMap as $uid => $sid) {
      $userShiftRow[(int)$uid] = $shiftMap[(int)$sid] ?? null;
    }

    // work[uid][date] = cell
    $work = [];
    foreach ($rows as $r) {
      $uid = (int)$r['user_id'];
      $d = (string)$r['work_date'];

      $st = (string)($r['start_time'] ?? '');
      $ft = (string)($r['finish_time'] ?? '');
      $st5 = $st ? substr($st,0,5) : '';
      $ft5 = $ft ? substr($ft,0,5) : '';

      $dt = DateTime::createFromFormat('Y-m-d', $d);
      $w = $dt ? wday_ja($dt) : '';
      $hs = (int)($holidayMap[$d] ?? 0);

      $calc = calc_ot_midnight_minutes($userShiftRow[$uid] ?? null, $st5, $ft5);
      $totalH = min_to_hour_int($calc['total']);
      $otH = min_to_hour_int($calc['overtime_excl_midnight']);
      $midH = min_to_hour_int($calc['midnight']);

      $cell = [
        'date'=>$d,
        'wday'=>$w,
        'holiday_status'=>$hs,
        'total_h'=>$totalH,
        'overtime_h'=>$otH,
        'midnight_h'=>$midH,
        'start'=>$st5,
        'finish'=>$ft5,
      ];

      // 条件フィルタ
      if (!cell_matches($cell, $cond, $condMode)) continue;

      $work[$uid][$d] = $cell;
    }

    // 出力対象ユーザー（セルが1つでもある人だけ）
    $outUsers = [];
    foreach ($work as $uid => $m) {
      if (!empty($m)) $outUsers[] = (int)$uid;
    }
    sort($outUsers);

    if (!hasSpreadsheet()) json_out(500, ['success'=>false,'message'=>'PhpSpreadsheet が必要です（site出力）']);

    $ss = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
    $ss->getProperties()->setCreator('Report System')->setTitle("WorkReport SITE {$ym}");
    $sheet = $ss->getActiveSheet();
    $sheet->setTitle('現場別');

    // タイトル
    $sheet->setCellValue('A1', "{$siteName}　{$ym}");
    $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(14);

    // ヘッダ（作業者 + 日付列）
    $header = ['作業者'];
    foreach ($dates as $d) {
      $dt = DateTime::createFromFormat('Y-m-d', $d);
      $day = $dt ? (int)$dt->format('j') : 0;
      $w = $dt ? wday_ja($dt) : '';
      $header[] = "{$day}日({$w})";
    }
    $sheet->fromArray([$header], null, 'A3', true);
    $sheet->getStyle("A3:".\PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex(count($header))."3")
          ->getFont()->setBold(true);

    $rowIdx = 4;

    // 月集計用
    $summary = []; // uid => totals

    foreach ($outUsers as $uid) {
      $name = $userNameMap[$uid] ?? (string)$uid;
      $row = [$name];

      $totNormal = 0;
      $totOT = 0;
      $totMid = 0;
      $cntLegal = 0;
      $cntCompany = 0;

      foreach ($dates as $d) {
        $cell = $work[$uid][$d] ?? null;
        if (!$cell) {
          $row[] = '';
          continue;
        }

        $hs = (int)$cell['holiday_status'];
        if ($hs === 2) $cntLegal++;
        if ($hs === 1) $cntCompany++;

        $totOT += (int)$cell['overtime_h'];
        $totMid += (int)$cell['midnight_h'];

        $normal = max(0, (int)$cell['total_h'] - (int)$cell['overtime_h'] - (int)$cell['midnight_h']);
        $totNormal += $normal;

        $suffix = [];
        if ($cell['overtime_h'] > 0) $suffix[] = "残{$cell['overtime_h']}";
        if ($cell['midnight_h'] > 0) $suffix[] = "深{$cell['midnight_h']}";
        if ($hs === 2) $suffix[] = "法";
        if ($hs === 1) $suffix[] = "社";

        $txt = "◯ {$cell['total_h']}";
        if ($suffix) $txt .= "（".implode('・', $suffix)."）";
        $row[] = $txt;
      }

      $sheet->fromArray([$row], null, "A{$rowIdx}", true);

      $summary[$uid] = [
        'name'=>$name,
        'normal'=>$totNormal,
        'overtime'=>$totOT,
        'legal'=>$cntLegal,
        'company'=>$cntCompany,
        'midnight'=>$totMid,
        'total'=>$totNormal + $totOT + $totMid,
      ];

      $rowIdx++;
    }

    // 月集計ブロック
    $rowIdx += 1;
    $sheet->setCellValue("A{$rowIdx}", "月集計");
    $sheet->getStyle("A{$rowIdx}")->getFont()->setBold(true);
    $rowIdx++;

    $sheet->fromArray([['作業者','通常','残業(h)','法定休日','社内休日','深夜(h)','合計']], null, "A{$rowIdx}", true);
    $sheet->getStyle("A{$rowIdx}:G{$rowIdx}")->getFont()->setBold(true);
    $rowIdx++;

    foreach ($outUsers as $uid) {
      $s = $summary[$uid];
      $sheet->fromArray([[
        $s['name'], $s['normal'], $s['overtime'], $s['legal'], $s['company'], $s['midnight'], $s['total']
      ]], null, "A{$rowIdx}", true);
      $rowIdx++;
    }

    // 幅
    $sheet->getColumnDimension('A')->setWidth(16);
    for ($i=2; $i<=count($header); $i++) {
      $col = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($i);
      $sheet->getColumnDimension($col)->setWidth(14);
    }

    $filenameUtf8  = "作業日報_現場別_{$safeSite}_{$ym}.xlsx";
    $filenameAscii = "work_report_site_{$onSiteId}_{$ym}.xlsx";
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="'.$filenameAscii.'"; filename*=UTF-8\'\''.rawurlencode($filenameUtf8));
    header('Cache-Control: max-age=0');
    (new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($ss))->save('php://output');
    exit;
  }

  json_out(400, ['success'=>false,'message'=>'type が不正です（user|site|date）']);

} catch (Throwable $e) {
  header('Content-Type: application/json; charset=utf-8');
  http_response_code(500);
  echo json_encode(['success'=>false,'message'=>$e->getMessage()], JSON_UNESCAPED_UNICODE);
}

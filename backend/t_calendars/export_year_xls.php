<?php
/**
 * １年分のカレンダーを「表のみ」でエクスポート
 * - PhpSpreadsheet がある場合 … XLSX（2シート: 日本人/外国人）を生成し、各シートに 3×4 配置で 12ヶ月の“表カレンダー”を書き込み（色塗り対応）
 * - 無い場合 … HTML(Excel互換) を .xls でダウンロード（同じ見た目）
 *
 * GET:
 *   year=YYYY        ... 対象年（未指定は当年）
 *
 * t_calendars: the_date(YYYY-MM-DD), locale_type(1/2), status(0=出勤,1=社内休日,2=法定休日)
 */
require_once dirname(__DIR__, 1) . '/common/cors.php';
require_once dirname(__DIR__, 1) . '/common/db_manager.php';

function pdo(): PDO {
    if (function_exists('getDb')) return getDb();
    if (function_exists('getPdo')) return getPdo();
    throw new RuntimeException('No PDO provider');
}
function getYearMap(PDO $pdo, int $year, int $lt): array {
    $from = sprintf('%04d-01-01', $year);
    $to   = sprintf('%04d-12-31', $year);
    $sql  = "SELECT the_date, status FROM t_calendars WHERE locale_type=:lt AND the_date BETWEEN :f AND :t";
    $st = $pdo->prepare($sql);
    $st->bindValue(':lt', $lt, PDO::PARAM_INT);
    $st->bindValue(':f',  $from);
    $st->bindValue(':t',  $to);
    $st->execute();
    $m = [];
    while ($r = $st->fetch(PDO::FETCH_ASSOC)) $m[$r['the_date']] = (int)$r['status'];
    return $m;
}

// ---- HTML(Excel互換)の月表 ----
function renderMonthTableHtml(int $y, int $m, array $map): string {
    $first  = new DateTime(sprintf('%04d-%02d-01', $y, $m));
    $startW = (int)$first->format('w');
    $last   = (int)$first->format('t');
    $h  = '<table class="cal"><thead><tr>';
    foreach (['日','月','火','水','木','金','土'] as $i=>$w) {
        $cls = ($i===0?'sun':($i===6?'sat':'')); $h .= "<th class='{$cls}'>{$w}</th>";
    }
    $h .= '</tr></thead><tbody>';
    $d=1;
    for($r=0;$r<6;$r++){
        $h .= '<tr>';
        for($c=0;$c<7;$c++){
            $idx = $r*7+$c;
            if($idx<$startW || $d>$last){ $h.='<td class="empty"></td>'; continue; }
            $ds = sprintf('%04d-%02d-%02d',$y,$m,$d);
            $st = $map[$ds]??0;
            $cls = $st===2?'legal':($st===1?'company':'');
            // 日曜/土曜セルにもクラス付与（文字色適用）
            if ($c===0) { $cls .= ($cls? ' ' : '').'sun'; }
            if ($c===6) { $cls .= ($cls? ' ' : '').'sat'; }
            $h .= "<td class='{$cls}'><div class='d'>{$d}</div></td>";
            $d++;
        }
        $h .= '</tr>';
    }
    return $h.'</tbody></table>';
}

try {
    $year = isset($_GET['year']) ? (int)$_GET['year'] : (int)date('Y');
    if ($year < 1970 || $year > 2100) $year = (int)date('Y');

    $pdo = pdo();
    $jp  = getYearMap($pdo, $year, 1);
    $fr  = getYearMap($pdo, $year, 2);

    // ===== PhpSpreadsheet が使える場合は XLSX で“表”を敷き詰める =====
    $hasSpreadsheet = false;
    try {
        foreach ([dirname(__DIR__,1).'/vendor/autoload.php', dirname(__DIR__,2).'/vendor/autoload.php'] as $p) {
            if (is_file($p)) { require_once $p; $hasSpreadsheet = true; break; }
        }
    } catch (Throwable $e) { $hasSpreadsheet = false; }

    if ($hasSpreadsheet && class_exists(\PhpOffice\PhpSpreadsheet\Spreadsheet::class)) {
        $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
        $spreadsheet->getProperties()->setCreator('Report System')->setTitle("Calendar {$year}");

        // 色
        $fillCompany = ['fillType'=>\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID,'startColor'=>['rgb'=>'D9F2FF']]; // 社内休日＝薄青
        $fillLegal   = ['fillType'=>\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID,'startColor'=>['rgb'=>'FFB3AD']]; // 法定休日＝淡赤
        $fontSun     = ['font'=>['color'=>['rgb'=>'DD3333']]];  // 日曜＝赤
        $fontSat     = ['font'=>['color'=>['rgb'=>'3367CC']]];  // 土曜＝青
        $borderThin  = ['borders'=>['allBorders'=>['borderStyle'=>\PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN,'color'=>['rgb'=>'666666']]]];

        // 配置計画（3列 × 4行）
        $blockW = 9;   // 7日 + 余白2
        $blockH = 9;   // 見出し2 + 週6
        $colOff = function(int $c){ return 1 + $c * 9; };
        $rowOff = function(int $r){ return 4 + $r * 9; }; // 年ヘッダ1 + 凡例1 + 空白1

        $buildSheet = function(\PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $sheet, int $y, array $map) use ($blockW,$blockH,$colOff,$rowOff,$fillCompany,$fillLegal,$fontSun,$fontSat,$borderThin) {
            // 列幅
            for ($i=1; $i<= $colOff(2)+7; $i++) $sheet->getColumnDimensionByColumn($i)->setWidth(4);

            // シート上部に「YYYY年カレンダー」
            $lastColIndex = $colOff(2) + 7;
            $lastCol = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($lastColIndex);
            $sheet->setCellValue('A1', "{$y}年カレンダー");
            $sheet->mergeCells("A1:{$lastCol}1");
            $sheet->getStyle("A1")->getFont()->setBold(true)->setSize(14);
            $sheet->getStyle("A1")->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER);

            //  凡例（A2 に表示、見出しと同幅で結合）
            $legendRt = new \PhpOffice\PhpSpreadsheet\RichText\RichText();
            $legendRt->createText('赤塗：法定休日　青塗：社内休日　');
            $runRed = $legendRt->createTextRun('赤字');
            $runRed->getFont()->setColor(new \PhpOffice\PhpSpreadsheet\Style\Color('DD3333'));
            $legendRt->createText('：日曜　');
            $runBlue = $legendRt->createTextRun('青字');
            $runBlue->getFont()->setColor(new \PhpOffice\PhpSpreadsheet\Style\Color('3367CC'));
            $legendRt->createText('：土曜');

            $sheet->setCellValue('A2', $legendRt);
            $sheet->mergeCells("A2:{$lastCol}2");
            $sheet->getStyle("A2")->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_LEFT);
            $sheet->getStyle("A2")->getFont()->setSize(10);

            for ($m=1; $m<=12; $m++) {
                $gridX = ($m-1) % 3;
                $gridY = intdiv($m-1, 3);
                $sc = $colOff($gridX);
                $sr = $rowOff($gridY);

                // タイトル（月）
                $sheet->setCellValueByColumnAndRow($sc, $sr, sprintf('%02d月', $m));
                $sheet->getStyleByColumnAndRow($sc, $sr)->getFont()->setBold(true);

                // 曜日ヘッダ（色設定）
                $week = ['日','月','火','水','木','金','土'];
                for ($i=0; $i<7; $i++) {
                    $sheet->setCellValueByColumnAndRow($sc+$i, $sr+1, $week[$i]);
                    if ($i===0) $sheet->getStyleByColumnAndRow($sc+$i, $sr+1)->applyFromArray($fontSun); // 日曜=赤
                    if ($i===6) $sheet->getStyleByColumnAndRow($sc+$i, $sr+1)->applyFromArray($fontSat); // 土曜=青
                }

                // 表本体（6週）
                $first  = new DateTime(sprintf('%04d-%02d-01', $y, $m));
                $startW = (int)$first->format('w');
                $last   = (int)$first->format('t');
                $d=1;
                for ($r=0; $r<6; $r++) {
                    for ($c=0; $c<7; $c++) {
                        $cc = $sc+$c;  $rr = $sr+2+$r;
                        $sheet->getStyleByColumnAndRow($cc,$rr)->applyFromArray($borderThin);
                        $idx = $r*7+$c;
                        if ($idx < $startW || $d > $last) { $sheet->setCellValueByColumnAndRow($cc,$rr,''); continue; }
                        $sheet->setCellValueByColumnAndRow($cc,$rr,$d);

                        // 休日塗り
                        $ds = sprintf('%04d-%02d-%02d',$y,$m,$d);
                        $st = $map[$ds] ?? 0;
                        if ($st === 1) $sheet->getStyleByColumnAndRow($cc,$rr)->getFill()->applyFromArray($fillCompany); // 社内休日＝薄青
                        if ($st === 2) $sheet->getStyleByColumnAndRow($cc,$rr)->getFill()->applyFromArray($fillLegal);   // 法定休日＝淡赤

                        // 土日文字色
                        if ($c===0) $sheet->getStyleByColumnAndRow($cc,$rr)->applyFromArray($fontSun); // 日曜=赤
                        if ($c===6) $sheet->getStyleByColumnAndRow($cc,$rr)->applyFromArray($fontSat); // 土曜=青
                        $d++;
                    }
                }
            }
        };

        // Sheet1: 日本人
        $s1 = $spreadsheet->getActiveSheet();
        $s1->setTitle('日本人');
        $buildSheet($s1, $year, $jp);

        // Sheet2: 外国人
        $s2 = $spreadsheet->createSheet(1);
        $s2->setTitle('外国人');
        $buildSheet($s2, $year, $fr);

        $filename = "カレンダー_{$year}.xlsx";
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="'.$filename.'"');
        header('Cache-Control: max-age=0');
        (new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet))->save('php://output');
        exit;
    }

    // ===== フォールバック: HTML(Excel互換) ─ 見た目は同じ“表カレンダー” =====
    $filename = "カレンダー_{$year}.xls";
    header('Content-Type: application/vnd.ms-excel; charset=UTF-8');
    header('Content-Disposition: attachment; filename="'.$filename.'"');
    echo "\xEF\xBB\xBF";
    ?>
<!DOCTYPE html>
<html lang="ja">
<head>
<meta charset="UTF-8" />
<title>Calendar <?php echo (int)$year; ?></title>
<style>
  body { font-family: sans-serif; }
  h2 { margin: 6px 0; }
  .sheet-title { margin: 8px 0 2px; padding: 6px 8px; background: #f0f0f0; border: 1px solid #ddd; }
  .legend { margin: 0 0 8px; padding: 0 2px; font-size: 12px; }
  .legend .sunTxt { color: #d33; }
  .legend .satTxt { color: #3367cc; }
  .cal { border-collapse: collapse; margin: 4px 12px 18px 0; }
  .cal th, .cal td { border: 1px solid #666; padding: 3px 4px; width: 28px; height: 22px; text-align: right; }
  .cal th.sun, .cal td.sun { color: #d33; }        /* 日曜＝赤 */
  .cal th.sat, .cal td.sat { color: #3367cc; }     /* 土曜＝青 */
  .cal td.company { background: #d9f2ff; }         /* 社内休日＝薄青 */
  .cal td.legal   { background: #ffb3ad; }         /* 法定休日＝淡赤 */
  .wrap { display: grid; grid-template-columns: repeat(3, auto); gap: 10px 18px; }
</style>
</head>
<body>
  <div class="sheet-title">日本人（<?php echo (int)$year; ?>年）</div>
  <!--  凡例（年タイトルの一つ下の行に相当） -->
  <div class="legend">赤塗：法定休日　青塗：社内休日　<span class="sunTxt">赤字</span>：日曜　<span class="satTxt">青字</span>：土曜</div>
  <<div style="height:22px;"></div>

  <div class="wrap">
    <?php for ($m=1;$m<=12;$m++): ?>
      <div>
        <h2><?php echo sprintf('%02d月',$m); ?></h2>
        <?php echo renderMonthTableHtml($year,$m,$jp); ?>
      </div>
    <?php endfor; ?>
  </div>

  <div class="sheet-title">外国人（<?php echo (int)$year; ?>年）</div>
  <!--  凡例 -->
  <div class="legend">赤塗：法定休日　青塗：社内休日　<span class="sunTxt">赤字</span>：日曜　<span class="satTxt">青字</span>：土曜</div>
  <div style="height:22px;"></div>

  <div class="wrap">
    <?php for ($m=1;$m<=12;$m++): ?>
      <div>
        <h2><?php echo sprintf('%02d月',$m); ?></h2>
        <?php echo renderMonthTableHtml($year,$m,$fr); ?>
      </div>
    <?php endfor; ?>
  </div>
</body>
</html>
<?php
    exit;
} catch (Throwable $e) {
    header('Content-Type: application/json; charset=utf-8');
    http_response_code(500);
    echo json_encode(['success'=>false,'message'=>$e->getMessage()], JSON_UNESCAPED_UNICODE);
}

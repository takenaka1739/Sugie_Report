<?php
/**
 * 出勤簿出力（管理者・全社員）
 * 現時点では年月、日付、社員名のみを出力する。
 */

require_once dirname(__DIR__, 1) . '/common/cors.php';
require_once dirname(__DIR__, 1) . '/common/db_manager.php';

function json_out(int $code, array $payload): void
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function load_spreadsheet(): bool
{
    try {
        foreach ([dirname(__DIR__, 1) . '/vendor/autoload.php', dirname(__DIR__, 2) . '/vendor/autoload.php'] as $path) {
            if (is_file($path)) {
                require_once $path;
                break;
            }
        }
        return class_exists(\PhpOffice\PhpSpreadsheet\Spreadsheet::class);
    } catch (Throwable $e) {
        return false;
    }
}

function norm_month($value): ?string
{
    return (isset($value) && $value !== '' && preg_match('/^\d{4}-\d{2}$/', (string)$value))
        ? (string)$value
        : null;
}

function month_start(string $ym): string
{
    return $ym . '-01';
}

function require_admin(PDO $dbh): void
{
    $auth = $_SESSION['auth'] ?? null;
    $uid = (int)($auth['id'] ?? 0);
    if ($uid <= 0) {
        json_out(401, ['success' => false, 'message' => '未ログインです']);
    }

    $stmt = $dbh->prepare('SELECT is_authorized FROM m_users WHERE id = :id LIMIT 1');
    $stmt->bindValue(':id', $uid, PDO::PARAM_INT);
    $stmt->execute();
    $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    if (!$row || empty($row['is_authorized'])) {
        json_out(403, ['success' => false, 'message' => '管理者権限が必要です']);
    }
}

function fetch_users(PDO $dbh, string $dateFrom): array
{
    $stmt = $dbh->prepare("
        SELECT id, name
          FROM m_users
         WHERE retiremented_on IS NULL OR retiremented_on >= :date_from
         ORDER BY id ASC
    ");
    $stmt->bindValue(':date_from', $dateFrom, PDO::PARAM_STR);
    $stmt->execute();
    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function column_letter(int $col): string
{
    return \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($col);
}

function apply_attendance_layout($sheet, int $year, int $month, array $users): void
{
    $employeeStartRow = 3;
    $employeeRows = max(32, count($users));
    $employeeEndRow = $employeeStartRow + $employeeRows - 1;
    $noteRow1 = $employeeEndRow + 3;
    $noteRow2 = $employeeEndRow + 4;
    $lastDayCol = 64; // BL
    $lastCol = 'BL';

    $sheet->setTitle('出勤簿');
    $sheet->getDefaultStyle()->getFont()->setName('ＭＳ Ｐゴシック')->setSize(11);
    $sheet->getDefaultStyle()->getAlignment()->setVertical(\PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER);

    $sheet->getColumnDimension('A')->setWidth(9.875);
    $sheet->getColumnDimension('B')->setWidth(2.375);
    for ($col = 3; $col <= $lastDayCol; $col++) {
        $sheet->getColumnDimension(column_letter($col))->setWidth(13);
    }

    for ($row = 1; $row <= $noteRow2; $row++) {
        $sheet->getRowDimension($row)->setRowHeight($row >= $noteRow1 ? 17.1 : 16.5);
    }

    $sheet->setCellValue('A1', $year);
    $sheet->setCellValue('B1', '年');
    $sheet->setCellValue('A2', $month);
    $sheet->setCellValue('B2', '月');
    $sheet->getStyle('A1:A2')->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_RIGHT);
    $sheet->getStyle('B1:B2')->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER);

    $daysInMonth = (int)date('t', strtotime(sprintf('%04d-%02d-01', $year, $month)));
    for ($day = 1; $day <= 31; $day++) {
        $col = 3 + (($day - 1) * 2);
        $col1 = column_letter($col);
        $col2 = column_letter($col + 1);
        $sheet->mergeCells("{$col1}1:{$col2}1");
        $sheet->mergeCells("{$col1}2:{$col2}2");

        if ($day <= $daysInMonth) {
            $date = \PhpOffice\PhpSpreadsheet\Shared\Date::PHPToExcel(strtotime(sprintf('%04d-%02d-%02d', $year, $month, $day)));
            $sheet->setCellValue("{$col1}1", $date);
            $sheet->setCellValue("{$col1}2", $date);
        }

        $sheet->getStyle("{$col1}1:{$col2}1")->getNumberFormat()->setFormatCode('m"月"d"日"');
        $sheet->getStyle("{$col1}2:{$col2}2")->getNumberFormat()->setFormatCode('aaa');
        $sheet->getStyle("{$col1}1:{$col2}2")->getAlignment()
            ->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER);
    }

    for ($row = $employeeStartRow; $row <= $employeeEndRow; $row++) {
        $sheet->mergeCells("A{$row}:B{$row}");
    }

    foreach ($users as $index => $user) {
        $row = $employeeStartRow + $index;
        $sheet->setCellValue("A{$row}", (string)($user['name'] ?? ''));
    }

    $thinBorder = [
        'borders' => [
            'allBorders' => [
                'borderStyle' => \PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN,
                'color' => ['rgb' => '666666'],
            ],
        ],
    ];
    $outerMedium = [
        'borders' => [
            'outline' => [
                'borderStyle' => \PhpOffice\PhpSpreadsheet\Style\Border::BORDER_MEDIUM,
                'color' => ['rgb' => '000000'],
            ],
        ],
    ];
    $headerBottom = [
        'borders' => [
            'bottom' => [
                'borderStyle' => \PhpOffice\PhpSpreadsheet\Style\Border::BORDER_DOUBLE,
                'color' => ['rgb' => '000000'],
            ],
        ],
    ];

    $sheet->getStyle("A1:{$lastCol}{$employeeEndRow}")->applyFromArray($thinBorder);
    $sheet->getStyle("A1:{$lastCol}{$employeeEndRow}")->applyFromArray($outerMedium);
    $sheet->getStyle("A2:{$lastCol}2")->applyFromArray($headerBottom);
    $sheet->getStyle("A1:{$lastCol}2")->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER);
    $sheet->getStyle("A{$employeeStartRow}:B{$employeeEndRow}")->getAlignment()
        ->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_LEFT);
    $sheet->getStyle("C{$employeeStartRow}:{$lastCol}{$employeeEndRow}")->getAlignment()
        ->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER);

    $sheet->mergeCells("C{$noteRow1}:{$lastCol}{$noteRow1}");
    $sheet->mergeCells("C{$noteRow2}:{$lastCol}{$noteRow2}");
    $sheet->setCellValue("C{$noteRow1}", '杉江工務店の工事は「●」、前田道路の工事は「○」、半日出勤は「△」で入力できます。');
    $sheet->setCellValue("C{$noteRow2}", '残業時間などの入力ルールは運用確定後に反映します。');
    $sheet->getStyle("C{$noteRow1}:{$lastCol}{$noteRow2}")->getFont()->setSize(10);
    $sheet->getStyle("C{$noteRow1}:{$lastCol}{$noteRow2}")->getAlignment()
        ->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_LEFT);

    $sheet->freezePane('C3');
    $sheet->getPageSetup()
        ->setOrientation(\PhpOffice\PhpSpreadsheet\Worksheet\PageSetup::ORIENTATION_LANDSCAPE)
        ->setFitToWidth(1)
        ->setFitToHeight(0);
    $sheet->getPageMargins()
        ->setTop(0.3)
        ->setBottom(0.3)
        ->setLeft(0.25)
        ->setRight(0.25);
    $sheet->getPageSetup()->setPrintArea("A1:{$lastCol}{$noteRow2}");
}

try {
    if (!load_spreadsheet()) {
        json_out(500, ['success' => false, 'message' => 'PhpSpreadsheet が必要です（出勤簿出力）']);
    }

    $ym = norm_month($_GET['ym'] ?? null) ?? date('Y-m');
    $dateFrom = month_start($ym);
    $year = (int)substr($ym, 0, 4);
    $month = (int)substr($ym, 5, 2);

    $dbh = getDb();
    require_admin($dbh);

    $users = fetch_users($dbh, $dateFrom);
    if (!$users) {
        json_out(404, ['success' => false, 'message' => '対象社員が見つかりません']);
    }

    $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
    $spreadsheet->getProperties()->setCreator('Report System')->setTitle("Attendance Book {$ym}");
    $sheet = $spreadsheet->getActiveSheet();
    apply_attendance_layout($sheet, $year, $month, $users);
    $spreadsheet->setActiveSheetIndex(0);

    $filenameUtf8 = "出勤簿_{$ym}.xlsx";
    $filenameAscii = "attendance_book_{$ym}.xlsx";
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . $filenameAscii . '"; filename*=UTF-8\'\'' . rawurlencode($filenameUtf8));
    header('Cache-Control: max-age=0');

    (new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet))->save('php://output');
    exit;
} catch (Throwable $e) {
    json_out(500, ['success' => false, 'message' => '出勤簿出力でエラーが発生しました', 'error' => $e->getMessage()]);
}

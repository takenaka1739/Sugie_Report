<?php
require_once dirname(__DIR__, 1) . '/common/db_manager.php';

// CORS
header('Access-Control-Allow-Origin: ' . ORIGIN);
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
  http_response_code(204);
  exit;
}

// 入力取得（JSON or x-www-form-urlencoded）
$raw = file_get_contents('php://input');
$data = $raw ? json_decode($raw, true) : $_POST;
if (!is_array($data)) { $data = []; }

// ヘルパ：空文字は NULL 扱い
$nullable = function ($v) {
  return ($v === '' || $v === null) ? null : $v;
};

// 型正規化
$id                 = isset($data['id']) ? (int)$data['id'] : null;
$name               = isset($data['name']) ? trim($data['name']) : null;
$is_authorized      = isset($data['is_authorized']) ? (int)(!!$data['is_authorized']) : 0;
$nationality_id     = isset($data['nationality_id']) ? (int)$data['nationality_id'] : null;
$shift_id           = isset($data['shift_id']) ? (int)$data['shift_id'] : null;
$blood_type_id      = isset($data['blood_type_id']) ? (int)$data['blood_type_id'] : null;
$health_checked_on  = $nullable($data['health_checked_on'] ?? null);
$paid_holidays_num  = isset($data['paid_holidays_num']) ? (int)$data['paid_holidays_num'] : 0;
$retiremented_on    = $nullable($data['retiremented_on'] ?? null);
$work_cloth_1_id    = isset($data['work_cloth_1_id']) ? (int)$data['work_cloth_1_id'] : 0;
$work_cloth_2_id    = isset($data['work_cloth_2_id']) ? (int)$data['work_cloth_2_id'] : 0;
$work_cloth_3_id    = isset($data['work_cloth_3_id']) ? (int)$data['work_cloth_3_id'] : 0;
$work_cloth_4_id    = isset($data['work_cloth_4_id']) ? (int)$data['work_cloth_4_id'] : 0;
$work_cloth_5_id    = isset($data['work_cloth_5_id']) ? (int)$data['work_cloth_5_id'] : 0;
$work_cloth_6_id    = isset($data['work_cloth_6_id']) ? (int)$data['work_cloth_6_id'] : 0;
$work_cloth_7_id    = isset($data['work_cloth_7_id']) ? (int)$data['work_cloth_7_id'] : 0;

// 必須チェック（不足なら 400）
$missing = [];
if (!$id)   { $missing[] = 'id'; }
if ($name === null || $name === '') { $missing[] = 'name'; }
if ($nationality_id === null) { $missing[] = 'nationality_id'; }
if ($shift_id === null)       { $missing[] = 'shift_id'; }
if ($blood_type_id === null)  { $missing[] = 'blood_type_id'; }
if ($missing) {
  http_response_code(400);
  echo json_encode(['ok' => false, 'error' => 'missing_fields', 'fields' => $missing], JSON_UNESCAPED_UNICODE);
  exit;
}

try {
  $dbh = getDb();
  // 例外を投げる
  $dbh->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

  $sql = "UPDATE m_users
            SET
              name = :name,
              is_authorized = :is_authorized,
              nationality_id = :nationality_id,
              shift_id = :shift_id,
              blood_type_id = :blood_type_id,
              health_checked_on = :health_checked_on,
              paid_holidays_num = :paid_holidays_num,
              retiremented_on = :retiremented_on,
              work_cloth_1_id = :work_cloth_1_id,
              work_cloth_2_id = :work_cloth_2_id,
              work_cloth_3_id = :work_cloth_3_id,
              work_cloth_4_id = :work_cloth_4_id,
              work_cloth_5_id = :work_cloth_5_id,
              work_cloth_6_id = :work_cloth_6_id,
              work_cloth_7_id = :work_cloth_7_id,
              updated_at = NOW()
          WHERE id = :id";

  $stmt = $dbh->prepare($sql);
  $stmt->bindValue(':name', $name, PDO::PARAM_STR);
  $stmt->bindValue(':is_authorized', $is_authorized, PDO::PARAM_INT);
  $stmt->bindValue(':nationality_id', $nationality_id, PDO::PARAM_INT);
  $stmt->bindValue(':shift_id', $shift_id, PDO::PARAM_INT);
  $stmt->bindValue(':blood_type_id', $blood_type_id, PDO::PARAM_INT);

  // 日付の NULL 対応
  if ($health_checked_on === null) {
    $stmt->bindValue(':health_checked_on', null, PDO::PARAM_NULL);
  } else {
    $stmt->bindValue(':health_checked_on', $health_checked_on, PDO::PARAM_STR);
  }
  $stmt->bindValue(':paid_holidays_num', $paid_holidays_num, PDO::PARAM_INT);
  if ($retiremented_on === null) {
    $stmt->bindValue(':retiremented_on', null, PDO::PARAM_NULL);
  } else {
    $stmt->bindValue(':retiremented_on', $retiremented_on, PDO::PARAM_STR);
  }

  $stmt->bindValue(':work_cloth_1_id', $work_cloth_1_id, PDO::PARAM_INT);
  $stmt->bindValue(':work_cloth_2_id', $work_cloth_2_id, PDO::PARAM_INT);
  $stmt->bindValue(':work_cloth_3_id', $work_cloth_3_id, PDO::PARAM_INT);
  $stmt->bindValue(':work_cloth_4_id', $work_cloth_4_id, PDO::PARAM_INT);
  $stmt->bindValue(':work_cloth_5_id', $work_cloth_5_id, PDO::PARAM_INT);
  $stmt->bindValue(':work_cloth_6_id', $work_cloth_6_id, PDO::PARAM_INT);
  $stmt->bindValue(':work_cloth_7_id', $work_cloth_7_id, PDO::PARAM_INT);

  $stmt->bindValue(':id', $id, PDO::PARAM_INT);
  $stmt->execute();

  echo json_encode([
    'ok' => true,
    'affected_rows' => $stmt->rowCount(),
  ], JSON_UNESCAPED_UNICODE);

} catch (PDOException $e) {
  http_response_code(500);
  echo json_encode([
    'ok' => false,
    'error' => 'pdo_exception',
    'message' => $e->getMessage(),
  ], JSON_UNESCAPED_UNICODE);
} finally {
  $dbh = null;
}

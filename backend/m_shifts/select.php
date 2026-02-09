<?php
require_once dirname(__DIR__, 1) . '/common/cors.php';

// DB
require_once dirname(__DIR__, 1) . '/common/db_manager.php';

/**
 * t_calendars と同等の PDO リゾルバ
 * - 既存のどのヘルパでも動くようフォールバック
 */
function resolvePdo(): PDO {
    if (function_exists('getPdo')) { return getPdo(); }
    if (function_exists('get_pdo')) { return get_pdo(); }
    if (function_exists('db')) { $pdo = db(); if ($pdo instanceof PDO) return $pdo; }
    if (function_exists('getDb')) { $pdo = getDb(); if ($pdo instanceof PDO) return $pdo; }
    if (class_exists('DBManager') && method_exists('DBManager', 'getPdo')) {
        return DBManager::getPdo();
    }
    if (defined('DB_DSN') && defined('DB_USER')) {
        $opts = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ];
        return new PDO(DB_DSN, DB_USER, defined('DB_PASSWORD') ? DB_PASSWORD : null, $opts);
    }
    throw new RuntimeException('PDO resolver not found.');
}

try {
    // CORSはcors.php側で済んでいるのでここではJSONヘッダのみ
    header('Content-Type: application/json; charset=utf-8');

    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    if ($method !== 'GET') {
        http_response_code(405);
        echo json_encode(['ok' => false, 'message' => 'Method Not Allowed (GET only)'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $pdo = resolvePdo();

    // クエリ: 単一 id or 複数 ids（カンマ区切り）
    $idParam  = isset($_GET['id'])  ? trim((string)$_GET['id'])  : '';
    $idsParam = isset($_GET['ids']) ? trim((string)$_GET['ids']) : '';

    // 取得カラム（フロントで使う列を明示）
    $columns = "id, regular_start, regular_finish, overtime_start, late_overtime_start";

    if ($idParam !== '') {
        // 単一 id
        $id = (int)$idParam;
        $sql = "SELECT {$columns} FROM m_shifts WHERE id = :id LIMIT 1";
        $stmt = $pdo->prepare($sql);
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;

        // 既存互換：配列で返却（なければ空配列）
        echo json_encode($row ? [$row] : [], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($idsParam !== '') {
        // 複数 id（例: ids=1,2,3）
        $ids = array_values(array_filter(array_map(function ($v) {
            $v = trim($v);
            return $v === '' ? null : (int)$v;
        }, explode(',', $idsParam)), function ($v) {
            return $v !== null;
        }));

        if (empty($ids)) {
            echo json_encode([], JSON_UNESCAPED_UNICODE);
            exit;
        }

        // IN 句を動的生成
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $sql = "SELECT {$columns} FROM m_shifts WHERE id IN ({$placeholders}) ORDER BY id ASC";
        $stmt = $pdo->prepare($sql);
        foreach ($ids as $i => $val) {
            $stmt->bindValue($i + 1, $val, PDO::PARAM_INT);
        }
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode($rows, JSON_UNESCAPED_UNICODE);
        exit;
    }

    // デフォルト: 全件
    $sql = "SELECT {$columns} FROM m_shifts ORDER BY id ASC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute();
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode($rows, JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    header('Content-Type: application/json; charset=utf-8');
    http_response_code(500);
    echo json_encode([
        'ok'      => false,
        'error'   => 'SERVER_ERROR',
        'message' => $e->getMessage(),
    ], JSON_UNESCAPED_UNICODE);
}

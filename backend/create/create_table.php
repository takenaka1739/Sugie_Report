<?php
require_once dirname(__DIR__, 1) . '/common/db_manager.php';

try {
    $dbh = getDb();

    $sql = "CREATE TABLE IF NOT EXISTS m_payments (
        id INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY COMMENT '管理ID',
        name VARCHAR(50) NOT NULL COMMENT '立替金名',
        created_at TIMESTAMP DEFAULT NOW() COMMENT '作成日時',
        updated_at TIMESTAMP DEFAULT NOW() COMMENT '更新日時'
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='立替金';";
    $dbh->query($sql);

    $sql = "CREATE TABLE IF NOT EXISTS m_vehicles (
        id INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY COMMENT '管理ID',
        number VARCHAR(50) DEFAULT '' COMMENT 'ナンバー',
        model VARCHAR(50) DEFAULT '' COMMENT '車種',
        inspected_on DATE COMMENT '車検日',
        liability_insuranced_on DATE COMMENT '自賠責保険',
        voluntary_insuranced_on DATE COMMENT '任意保険',
        created_at TIMESTAMP DEFAULT NOW() COMMENT '作成日時',
        updated_at TIMESTAMP DEFAULT NOW() COMMENT '更新日時'
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='車両';";
    $dbh->query($sql);

    $sql = "CREATE TABLE IF NOT EXISTS m_on_sites (
        id INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY COMMENT '管理ID',
        name VARCHAR(50) NOT NULL DEFAULT '現場' COMMENT '現場名',
        type_id INT(11) COMMENT '自社/外注',
        created_at TIMESTAMP DEFAULT NOW() COMMENT '作成日時',
        updated_at TIMESTAMP DEFAULT NOW() COMMENT '更新日時'
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='現場';";
    $dbh->query($sql);

    $sql = "CREATE TABLE IF NOT EXISTS m_shifts (
        id INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY COMMENT '管理ID',
        regular_start TIME NOT NULL COMMENT '定時(始業)',
        regular_finish TIME NOT NULL COMMENT '定時(終業)',
        overtime_start TIME NOT NULL COMMENT '残業開始',
        late_overtime_start TIME NOT NULL COMMENT '深夜残業開始',
        created_at TIMESTAMP DEFAULT NOW() COMMENT '作成日時',
        updated_at TIMESTAMP DEFAULT NOW() COMMENT '更新日時'
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='シフト';";
    $dbh->query($sql);

    $sql = "CREATE TABLE IF NOT EXISTS m_users (
        id INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY COMMENT '管理ID',
        name VARCHAR(50) NOT NULL COMMENT '氏名',
        password VARCHAR(255) NOT NULL COMMENT 'パスワード',
        is_authorized BOOLEAN NOT NULL COMMENT '管理者',
        nationality_id INT(11) NOT NULL COMMENT '日本人/外国人',
        shift_id INT(11) NOT NULL COMMENT 'シフトID',
        blood_type_id INT(11) NOT NULL COMMENT '血液型ID',
        health_checked_on DATE COMMENT '健康診断日',
        paid_holidays_num INT(11) NOT NULL COMMENT '有休日数',
        retiremented_on DATE COMMENT '退職日',
        work_cloth_1_id INT(11) COMMENT '作業着ID',
        work_cloth_2_id INT(11) COMMENT '作業着ID',
        work_cloth_3_id INT(11) COMMENT '作業着ID',
        work_cloth_4_id INT(11) COMMENT '作業着ID',
        work_cloth_5_id INT(11) COMMENT '作業着ID',
        created_at TIMESTAMP DEFAULT NOW() COMMENT '作成日時',
        updated_at TIMESTAMP DEFAULT NOW() COMMENT '更新日時'
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='ユーザー';";
    $dbh->query($sql);

    $sql = "CREATE TABLE IF NOT EXISTS m_mail_settings (
        id INT(11) NOT NULL COMMENT '管理ID',
        is_enabled TINYINT(1) NOT NULL DEFAULT 1 COMMENT 'メール通知有効フラグ',
        recipient_mail VARCHAR(255) NOT NULL COMMENT '通知先メールアドレス',
        cc_mail VARCHAR(255) DEFAULT NULL COMMENT 'CCメールアドレス',
        sender_name VARCHAR(100) NOT NULL COMMENT '送信者名',
        sender_mail VARCHAR(255) NOT NULL COMMENT '送信元メールアドレス',
        smtp_host VARCHAR(255) NOT NULL COMMENT 'SMTPホスト',
        smtp_port INT(11) NOT NULL DEFAULT 587 COMMENT 'SMTPポート',
        smtp_user VARCHAR(255) NOT NULL COMMENT 'SMTPユーザー名',
        smtp_password VARCHAR(255) NOT NULL COMMENT 'SMTPパスワード',
        encryption_type VARCHAR(20) NOT NULL DEFAULT 'tls' COMMENT '暗号化方式',
        subject VARCHAR(255) NOT NULL COMMENT 'メール件名',
        body_header TEXT DEFAULT NULL COMMENT 'メール本文先頭メッセージ',
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '作成日時',
        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '更新日時'
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='メール通知設定';";
    $dbh->query($sql);

    $sql = "CREATE TABLE IF NOT EXISTS t_calendars (
        id INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY COMMENT '管理ID',
        the_date DATE NOT NULL COMMENT '対象日',
        locale_type TINYINT UNSIGNED NOT NULL COMMENT '1=日本人, 2=外国人',
        status TINYINT UNSIGNED NOT NULL COMMENT '0=出勤, 1=社内休日, 2=法定休日',
        note VARCHAR(255) DEFAULT NULL COMMENT 'メモ',
        updated_by_user_id INT(11) DEFAULT NULL COMMENT '最終更新ユーザーID',
        created_at TIMESTAMP DEFAULT NOW() COMMENT '作成日時',
        updated_at TIMESTAMP DEFAULT NOW() COMMENT '更新日時',
        UNIQUE KEY uq_t_calendars_date_locale (the_date, locale_type),
        KEY idx_t_calendars_date (the_date),
        KEY idx_t_calendars_locale_status (locale_type, status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='勤怠カレンダー';";
    $dbh->query($sql);

    $sql = "CREATE TABLE IF NOT EXISTS t_paid_leaves (
        id INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY COMMENT '管理ID',
        user_id INT(11) NOT NULL COMMENT '申請者ユーザーID (m_users.id)',
        leave_date DATE NOT NULL COMMENT '有給取得日',
        created_at TIMESTAMP DEFAULT NOW() COMMENT '作成日時',
        updated_at TIMESTAMP DEFAULT NOW() COMMENT '更新日時',
        UNIQUE KEY uq_t_pl_user_date (user_id, leave_date),
        KEY idx_t_pl_leave_date (leave_date),
        KEY idx_t_pl_user (user_id)
    ) COMMENT='有給休暇';";
    $dbh->query($sql);

    $sql = "CREATE TABLE IF NOT EXISTS t_work_reports (
        id INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY COMMENT '管理ID',
        user_id INT(11) NOT NULL COMMENT 'ユーザーID (m_users.id)',
        work_date DATE NOT NULL COMMENT '作業日',
        start_time TIME DEFAULT NULL COMMENT '出社時刻',
        finish_time TIME DEFAULT NULL COMMENT '退社時刻',
        on_site_id INT(11) DEFAULT NULL COMMENT '現場ID (m_on_sites.id)',
        work TEXT DEFAULT NULL COMMENT '作業内容',
        is_canceled TINYINT(1) NOT NULL DEFAULT 0 COMMENT '中止フラグ (0=通常,1=中止)',
        alcohol_checked TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'アルコールチェック',
        condition_checked TINYINT(1) NOT NULL DEFAULT 0 COMMENT '体調チェック',
        vehicle_id INT(11) DEFAULT NULL COMMENT '車両ID (m_vehicles.id)',
        payment1_id INT(11) DEFAULT NULL COMMENT '立替金ID (m_payments.id)',
        amount1 INT(11) DEFAULT NULL COMMENT '金額',
        payment2_id INT(11) DEFAULT NULL COMMENT '立替金ID (m_payments.id)',
        amount2 INT(11) DEFAULT NULL COMMENT '金額',
        payment3_id INT(11) DEFAULT NULL COMMENT '立替金ID (m_payments.id)',
        amount3 INT(11) DEFAULT NULL COMMENT '金額',
        payment4_id INT(11) DEFAULT NULL COMMENT '立替金ID (m_payments.id)',
        amount4 INT(11) DEFAULT NULL COMMENT '金額',
        payment5_id INT(11) DEFAULT NULL COMMENT '立替金ID (m_payments.id)',
        amount5 INT(11) DEFAULT NULL COMMENT '金額',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP COMMENT '作成日時',
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '更新日時',
        UNIQUE KEY uq_user_date (user_id, work_date),
        KEY idx_user (user_id),
        KEY idx_work_date (work_date),
        KEY idx_on_site (on_site_id),
        KEY idx_vehicle (vehicle_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='日次作業報告';";
    $dbh->query($sql);

    $dbh->exec("ALTER TABLE t_work_reports ADD COLUMN IF NOT EXISTS on_site_id INT(11) DEFAULT NULL COMMENT '現場ID (m_on_sites.id)' AFTER finish_time");
    $dbh->exec("ALTER TABLE t_work_reports ADD COLUMN IF NOT EXISTS work TEXT DEFAULT NULL COMMENT '作業内容' AFTER on_site_id");
    $dbh->exec("ALTER TABLE t_work_reports ADD INDEX IF NOT EXISTS idx_on_site (on_site_id)");
    $dbh->exec("ALTER TABLE t_work_reports ADD INDEX IF NOT EXISTS idx_vehicle (vehicle_id)");
    $dbh->exec("ALTER TABLE t_work_reports MODIFY COLUMN is_canceled TINYINT(1) NOT NULL DEFAULT 0 COMMENT '中止フラグ (0=通常,1=中止)'");

    $dbh->exec("CREATE INDEX IF NOT EXISTS idx_work_reports_user_date ON t_work_reports (user_id, work_date)");
    $dbh->exec("CREATE INDEX IF NOT EXISTS idx_pl_user_date ON t_paid_leaves (user_id, leave_date)");
    $dbh->exec("CREATE INDEX IF NOT EXISTS idx_cal_date ON t_calendars (the_date)");

    echo 'テーブル作成が完了しました。';
} catch (PDOException $e) {
    die('作成エラー: ' . $e->getMessage());
} finally {
    $dbh = null;
}
?>

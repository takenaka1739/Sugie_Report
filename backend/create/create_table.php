<?php
    require_once dirname(__DIR__, 1) . '/common/db_manager.php';

    try
    {
        // データベース接続
        $dbh = getDb();

        // 立替金マスタ
        $sql = "CREATE TABLE IF NOT EXISTS m_payments (
            id INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY COMMENT '管理ID',
            name VARCHAR(50) NOT NULL COMMENT '立替金名',
            created_at TIMESTAMP DEFAULT NOW() COMMENT '作成日時',
            updated_at TIMESTAMP DEFAULT NOW() COMMENT '更新日時'
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='立替金';";
        $dbh->query($sql);

        // 車両マスタ
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

        // 現場マスタ
        $sql = "CREATE TABLE IF NOT EXISTS m_on_sites (
            id INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY COMMENT '管理ID',
            name VARCHAR(50) NOT NULL DEFAULT '現場' COMMENT '現場名',
            type_id INT(11) COMMENT '自社/前田',
            created_at TIMESTAMP DEFAULT NOW() COMMENT '作成日時',
            updated_at TIMESTAMP DEFAULT NOW() COMMENT '更新日時'
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='現場';";
        $dbh->query($sql);

        // シフトマスタ
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

        // ユーザーマスタ
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
            work_cloth_1_id INT(11) COMMENT '作業服1ID',
            work_cloth_2_id INT(11) COMMENT '作業服2ID',
            work_cloth_3_id INT(11) COMMENT '作業服3ID',
            work_cloth_4_id INT(11) COMMENT '作業服4ID',
            work_cloth_5_id INT(11) COMMENT '作業服5ID',
            created_at TIMESTAMP DEFAULT NOW() COMMENT '作成日時',
            updated_at TIMESTAMP DEFAULT NOW() COMMENT '更新日時'
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='ユーザー';";
        $dbh->query($sql);

        // 勤怠カレンダー
        $sql = "CREATE TABLE IF NOT EXISTS t_calendars (
            id INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY COMMENT '管理ID',
            the_date DATE NOT NULL COMMENT '対象日',
            locale_type TINYINT UNSIGNED NOT NULL COMMENT '1=日本人, 2=外国人',
            status TINYINT UNSIGNED NOT NULL COMMENT '0=出勤, 1=社内休日, 2=法定休日',
            note VARCHAR(255) DEFAULT NULL COMMENT 'メモ',
            updated_by_user_id INT(11) DEFAULT NULL COMMENT '最終更新者ユーザーID',
            created_at TIMESTAMP DEFAULT NOW() COMMENT '作成日時',
            updated_at TIMESTAMP DEFAULT NOW() COMMENT '更新日時',
            UNIQUE KEY uq_t_calendars_date_locale (the_date, locale_type),
            KEY idx_t_calendars_date (the_date),
            KEY idx_t_calendars_locale_status (locale_type, status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='勤怠カレンダー（日本人/外国人×出勤/社内休日/法定休日）';";
        $dbh->query($sql);

        //　有給申請テーブル
        $sql = "CREATE TABLE IF NOT EXISTS t_paid_leaves (
            id INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY COMMENT '管理ID',
            user_id INT(11) NOT NULL COMMENT '申請者ユーザーID (m_users.id)',
            leave_date DATE NOT NULL COMMENT '有給取得日',
            created_at TIMESTAMP DEFAULT NOW() COMMENT '作成日時',
            updated_at TIMESTAMP DEFAULT NOW() COMMENT '更新日時',
            UNIQUE KEY uq_t_pl_user_date (user_id, leave_date),
            KEY idx_t_pl_leave_date (leave_date),
            KEY idx_t_pl_user (user_id)
        ) COMMENT='有給休暇(ユーザーID+日付)';";
        $dbh->query($sql);

        // 日次作業報告テーブル
        $sql = "CREATE TABLE IF NOT EXISTS t_work_reports (
            id INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY COMMENT '管理ID',
            user_id INT(11) NOT NULL COMMENT 'ユーザーID (m_users.id)',
            work_date DATE NOT NULL COMMENT '作業日',

            start_time TIME DEFAULT NULL COMMENT '出社時間',
            finish_time TIME DEFAULT NULL COMMENT '退社時間',

            on_site_id INT(11) DEFAULT NULL COMMENT '現場ID (m_on_sites.id)',
            work TEXT DEFAULT NULL COMMENT '作業内容',
            is_canceled TINYINT(1) NOT NULL DEFAULT 0 COMMENT '中止フラグ (0=通常,1=中止)',
            alcohol_checked TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'アルコールチェック',
            condition_checked TINYINT(1) NOT NULL DEFAULT 0 COMMENT '体調チェック',
            vehicle_id INT(11) DEFAULT NULL COMMENT '車両ID (m_vehicles.id)',

            payment1_id INT(11) DEFAULT NULL COMMENT '立替金1ID (m_payments.id)',
            amount1 INT(11) DEFAULT NULL COMMENT '金額1',
            payment2_id INT(11) DEFAULT NULL COMMENT '立替金2ID (m_payments.id)',
            amount2 INT(11) DEFAULT NULL COMMENT '金額2',
            payment3_id INT(11) DEFAULT NULL COMMENT '立替金3ID (m_payments.id)',
            amount3 INT(11) DEFAULT NULL COMMENT '金額3',
            payment4_id INT(11) DEFAULT NULL COMMENT '立替金4ID (m_payments.id)',
            amount4 INT(11) DEFAULT NULL COMMENT '金額4',
            payment5_id INT(11) DEFAULT NULL COMMENT '立替金5ID (m_payments.id)',
            amount5 INT(11) DEFAULT NULL COMMENT '金額5',

            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP COMMENT '作成日時',
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '更新日時',

            UNIQUE KEY uq_user_date (user_id, work_date),
            KEY idx_user (user_id),
            KEY idx_work_date (work_date),
            KEY idx_on_site (on_site_id),
            KEY idx_vehicle (vehicle_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='日次作業報告';";
        $dbh->query($sql);

        // 既存環境向けの差分適用（MySQL 8.0 以降想定）
        $dbh->exec("ALTER TABLE t_work_reports ADD COLUMN IF NOT EXISTS on_site_id INT(11) DEFAULT NULL COMMENT '現場ID (m_on_sites.id)' AFTER finish_time");
        $dbh->exec("ALTER TABLE t_work_reports ADD COLUMN IF NOT EXISTS work TEXT DEFAULT NULL COMMENT '作業内容' AFTER on_site_id");
        $dbh->exec("ALTER TABLE t_work_reports ADD INDEX IF NOT EXISTS idx_on_site (on_site_id)");
        $dbh->exec("ALTER TABLE t_work_reports ADD INDEX IF NOT EXISTS idx_vehicle (vehicle_id)");
        // 念のため is_canceled の定義を揃える
        $dbh->exec("ALTER TABLE t_work_reports MODIFY COLUMN is_canceled TINYINT(1) NOT NULL DEFAULT 0 COMMENT '中止フラグ (0=通常,1=中止)'");

        $dbh->exec("CREATE INDEX IF NOT EXISTS idx_work_reports_user_date ON t_work_reports (user_id, work_date)");
        $dbh->exec("CREATE INDEX IF NOT EXISTS idx_pl_user_date ON t_paid_leaves (user_id, leave_date)");
        $dbh->exec("CREATE INDEX IF NOT EXISTS idx_cal_date ON t_calendars (the_date)");

        echo "テーブル作成完了しました。";

    }
    catch (PDOException $e)
    {
        die('接続エラー：' . $e->getMessage());
    }
    finally
    {
        $dbh = null;
    }
?>

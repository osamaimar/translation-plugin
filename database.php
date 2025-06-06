<?php

function setup_custom_translation_tables() {
    global $wpdb;
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';

    $table1 = $wpdb->prefix . 'custom_translations';
    $table2 = $wpdb->prefix . 'custom_languages';
    $table3 = $wpdb->prefix . 'api_integrations';
    $table4 = $wpdb->prefix . 'custom_translation_logs';

    $sql1 = "
        CREATE TABLE IF NOT EXISTS $table1 (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            text_hash CHAR(64) NOT NULL,
            original_text TEXT NOT NULL,
            source_lang VARCHAR(10) NOT NULL,
            target_lang VARCHAR(10) DEFAULT NULL,
            translated_text TEXT DEFAULT NULL,
            context VARCHAR(255) DEFAULT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY unique_translation (text_hash, target_lang),
            INDEX idx_text_hash (text_hash),
            INDEX idx_target_lang (target_lang)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ";

    $sql2 = "
        CREATE TABLE IF NOT EXISTS $table2 (
            id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(100),
            code VARCHAR(10) UNIQUE,
            direction ENUM('ltr','rtl') DEFAULT 'ltr',
            flag_url VARCHAR(255)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ";

    $sql3 = "
        CREATE TABLE IF NOT EXISTS $table3 (
            id INT AUTO_INCREMENT PRIMARY KEY,
            tool VARCHAR(100) NOT NULL,
            model VARCHAR(100) DEFAULT NULL,
            api_key TEXT DEFAULT NULL,
            api_url VARCHAR(255) DEFAULT NULL,
            is_active TINYINT(1) DEFAULT 0,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ";

    $sql4 = "
        CREATE TABLE IF NOT EXISTS $table4 (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            action_type VARCHAR(50) NOT NULL,
            message TEXT NOT NULL,
            lang_code VARCHAR(10),
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ";

    dbDelta($sql1);
    dbDelta($sql2);
    dbDelta($sql3);
    dbDelta($sql4);

    // ✅ إدخال بيانات اللغات
    $languages = [
        ['English', 'en', 'ltr', 'https://flagcdn.com/us.svg'],
        ['العربية', 'ar', 'rtl', 'https://flagcdn.com/sa.svg'],
        ['Français', 'fr', 'ltr', 'https://flagcdn.com/fr.svg'],
        ['Deutsch', 'de', 'ltr', 'https://flagcdn.com/de.svg'],
        ['Español', 'es', 'ltr', 'https://flagcdn.com/es.svg'],
        ['Italiano', 'it', 'ltr', 'https://flagcdn.com/it.svg'],
        ['Português', 'pt', 'ltr', 'https://flagcdn.com/pt.svg'],
        ['Русский', 'ru', 'ltr', 'https://flagcdn.com/ru.svg'],
        ['简体中文', 'zh', 'ltr', 'https://flagcdn.com/cn.svg'],
        ['日本語', 'ja', 'ltr', 'https://flagcdn.com/jp.svg'],
        ['한국어', 'ko', 'ltr', 'https://flagcdn.com/kr.svg'],
        ['Türkçe', 'tr', 'ltr', 'https://flagcdn.com/tr.svg'],
        ['हिन्दी', 'hi', 'ltr', 'https://flagcdn.com/in.svg'],
        ['עברית', 'he', 'rtl', 'https://flagcdn.com/il.svg'],
        ['فارسی', 'fa', 'rtl', 'https://flagcdn.com/ir.svg'],
        ['اردو', 'ur', 'rtl', 'https://flagcdn.com/pk.svg'],
        ['Nederlands', 'nl', 'ltr', 'https://flagcdn.com/nl.svg'],
        ['Polski', 'pl', 'ltr', 'https://flagcdn.com/pl.svg'],
        ['Svenska', 'sv', 'ltr', 'https://flagcdn.com/se.svg'],
        ['Norsk', 'no', 'ltr', 'https://flagcdn.com/no.svg'],
        ['Dansk', 'da', 'ltr', 'https://flagcdn.com/dk.svg'],
        ['Suomi', 'fi', 'ltr', 'https://flagcdn.com/fi.svg'],
        ['ไทย', 'th', 'ltr', 'https://flagcdn.com/th.svg'],
        ['Tiếng Việt', 'vi', 'ltr', 'https://flagcdn.com/vn.svg'],
        ['Bahasa Indonesia', 'id', 'ltr', 'https://flagcdn.com/id.svg']
    ];

    foreach ($languages as $lang) {
        $wpdb->query(
            $wpdb->prepare(
                "INSERT IGNORE INTO $table2 (name, code, direction, flag_url) VALUES (%s, %s, %s, %s)",
                ...$lang
            )
        );
    }

    // ✅ إدخال init row
    $init_hash = hash('sha256', 'init');
    $exists = $wpdb->get_var(
        $wpdb->prepare("SELECT COUNT(*) FROM $table1 WHERE text_hash = %s", $init_hash)
    );
    if (!$exists) {
        $wpdb->insert($table1, [
            'text_hash' => $init_hash,
            'original_text' => 'init',
            'source_lang' => 'en',
            'target_lang' => null,
            'translated_text' => null,
            'context' => null,
            'created_at' => current_time('mysql'),
            'updated_at' => current_time('mysql')
        ]);
    }
}

function uninstall_custom_translation_plugin() {
    global $wpdb;
    $wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}custom_translations");
    $wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}custom_languages");
    $wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}api_integrations");
    $wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}custom_translation_logs");
}


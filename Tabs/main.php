<?php
require_once 'current-languages-tab.php';
require_once 'api-settings-tab.php';
require_once 'manual-translate-tab.php';
require_once 'export-import-tab.php';

// Menu
add_action('admin_menu', function () {
    add_menu_page('Run Translation', 'Translation Plugin', 'manage_options', 'translation-plugin', 'cls_translation_plugin_page');
});


// ✅ REST API registration
add_action('rest_api_init', function () {
    register_rest_route('custom-translate/v1', '/translate-missing', [
        'methods' => 'POST',
        'callback' => 'cls_translate_missing_texts_rest',
        'permission_callback' => function () {
            return current_user_can('manage_options');
        }
    ]);
});



function cls_translation_plugin_page() {
    $active_tab = isset($_GET['tab']) ? $_GET['tab'] : 'current_languages';
    ?>
    <div class="wrap">
        <h1>Translation Plugin</h1>
        <h2 class="nav-tab-wrapper">
            <a href="?page=translation-plugin&tab=current_languages" class="nav-tab <?= $active_tab == 'current_languages' ? 'nav-tab-active' : ''; ?>">Current Languages</a>
            <a href="?page=translation-plugin&tab=manual_translate" class="nav-tab <?= $active_tab == 'manual_translate' ? 'nav-tab-active' : ''; ?>">Manual Translate</a>
            <a href="?page=translation-plugin&tab=scaning" class="nav-tab <?= $active_tab == 'scaning' ? 'nav-tab-active' : ''; ?>">Scanning</a>
            <a href="?page=translation-plugin&tab=Export/Import" class="nav-tab <?= $active_tab == 'Export/Import' ? 'nav-tab-active' : ''; ?>">Export/Import</a>
            <a href="?page=translation-plugin&tab=log" class="nav-tab <?= $active_tab == 'log' ? 'nav-tab-active' : ''; ?>">Log</a>
            <a href="?page=translation-plugin&tab=api_settings" class="nav-tab <?= $active_tab == 'api_settings' ? 'nav-tab-active' : ''; ?>">API Settings</a>
        </h2>
        <div class="tab-content">
            <?php
            switch ($active_tab) {
                case 'current_languages':
                    echo '<h2>Current Languages</h2>';
                    cls_render_current_languages_tab();
                    break;
                case 'manual_translate':
                    echo '<h2>Manual Translate Interface</h2>';
                    cls_render_manual_translate_tab('');
                    break;
                case 'scaning':
                    echo '<h2>Scanning</h2>';
                    include 'scaning-tab.php';
                    break;
                case 'Export/Import':
                    cls_render_import_export_tab();
                    break;
                case 'log':
                    include 'log-tab.php';
                    break;
                case 'api_settings':
                    echo '<h2>API Settings</h2>';
                    cls_render_api_settings_tab();
                    break;
            }
            ?>
        </div>
    </div>
    <?php
}

// ✅ Batch REST logic using dynamic API
function cls_translate_missing_texts_rest($request) {
    global $wpdb;
    $target_lang = sanitize_text_field($request->get_param('target_lang'));
    $limit = intval($request->get_param('limit')) ?: 100;
    $table = $wpdb->prefix . 'custom_translations';

    $rows = $wpdb->get_results($wpdb->prepare("
        SELECT id, original_text FROM $table
        WHERE target_lang = %s AND (translated_text IS NULL OR translated_text = '')
        LIMIT %d
    ", $target_lang, $limit));

    if (empty($rows)) {
        return rest_ensure_response(['completed' => true, 'count' => 0]);
    }

    $texts = array_column($rows, 'original_text');
    $translations = cls_translate_batch_by_active_api($texts, 'en', $target_lang);
    $updated = 0;

    foreach ($rows as $i => $row) {
        $translated = $translations[$i] ?? null;
        if ($translated) {
            $wpdb->update($table, [
                'translated_text' => $translated,
                'updated_at' => current_time('mysql')
            ], ['id' => $row->id]);
            $updated++;
        }
    }

    return rest_ensure_response(['completed' => false, 'count' => $updated]);
}


//For Api
add_action('wp_ajax_test_api_connection', 'cls_test_api_connection');
add_action('wp_ajax_save_api_settings', 'cls_save_api_settings');
function cls_test_api_connection() {
    $tool = sanitize_text_field($_POST['tool'] ?? '');
    $api_url = esc_url_raw($_POST['api_url'] ?? '');
    $api_key = sanitize_text_field($_POST['api_key'] ?? '');

    // Skip API key check only for LibreTranslate
    if (!$tool || !$api_url || ($tool !== 'libretranslate' && !$api_key)) {
        echo "❌ Missing required fields.";
        wp_die();
    }

    $body = [];
    $headers = [];
    $response = null;

    switch ($tool) {
        case 'openai':
            $headers = [
                'Authorization' => 'Bearer ' . $api_key,
                'Content-Type'  => 'application/json',
            ];
            $body = json_encode([
                'model' => 'gpt-3.5-turbo',
                'messages' => [['role' => 'user', 'content' => 'Say Hello']],
                'temperature' => 0.5
            ]);
            break;

        case 'google':
            $body = http_build_query([
                'q' => 'Hello',
                'source' => 'en',
                'target' => 'es',
                'format' => 'text',
                'key' => $api_key
            ]);
            $headers = ['Content-Type' => 'application/x-www-form-urlencoded'];
            break;

        case 'deepl':
            $body = http_build_query([
                'text' => 'Hello',
                'source_lang' => 'EN',
                'target_lang' => 'DE',
                'auth_key' => $api_key
            ]);
            $headers = ['Content-Type' => 'application/x-www-form-urlencoded'];
            break;

        case 'libretranslate':
            $body = json_encode([
                'q' => 'Hello',
                'source' => 'en',
                'target' => 'es'
            ]);
            $headers = ['Content-Type' => 'application/json'];
            if (!empty($api_key)) {
                $headers['Authorization'] = 'Bearer ' . $api_key;
            }
            break;
    }

    $response = wp_remote_post($api_url, [
        'headers' => $headers,
        'body' => $body,
        'timeout' => 15
    ]);

    if (is_wp_error($response)) {
        echo '❌ Connection failed: ' . $response->get_error_message();
    } else {
        $code = wp_remote_retrieve_response_code($response);
        if ($code >= 200 && $code < 300) {
            echo '✅ Connection successful!';
        } else {
            $msg = wp_remote_retrieve_body($response);
            echo "❌ API Error ($code):<br><code>" . esc_html($msg) . "</code>";
        }
    }

    wp_die();
}

function cls_save_api_settings() {
    global $wpdb;
    $table = $wpdb->prefix . 'api_integrations';

    $tool     = sanitize_text_field($_POST['tool'] ?? '');
    $model    = sanitize_text_field($_POST['model'] ?? null);
    $api_key  = sanitize_textarea_field($_POST['api_key'] ?? '');
    $api_url  = esc_url_raw($_POST['api_url'] ?? '');

    // Skip API key check only for LibreTranslate
    if (!$tool || !$api_url || ($tool !== 'libretranslate' && !$api_key)) {
        echo "❌ Missing required fields.";
        wp_die();
    }

    // Check if there's an existing record
    $existing = $wpdb->get_var("SELECT id FROM $table LIMIT 1");

    if ($existing) {
        // Update existing record
        $wpdb->update(
            $table,
            [
                'tool' => $tool,
                'model' => $model,
                'api_key' => $api_key,
                'api_url' => $api_url,
                'is_active' => 1,
                'updated_at' => current_time('mysql')
            ],
            ['id' => $existing]
        );
        echo "✅ API settings updated successfully.";
    } else {
        // Insert new record
        $wpdb->insert(
            $table,
            [
                'tool' => $tool,
                'model' => $model,
                'api_key' => $api_key,
                'api_url' => $api_url,
                'is_active' => 1,
                'created_at' => current_time('mysql'),
                'updated_at' => current_time('mysql')
            ]
        );
        echo "✅ API settings saved successfully.";
    }

    wp_die();
}


//Log file

function cls_log_action($type, $message, $lang = null) {
    global $wpdb;
    $table = $wpdb->prefix . 'custom_translation_logs';

    $wpdb->insert($table, [
        'action_type' => $type,
        'message' => $message,
        'lang_code' => $lang,
        'created_at' => current_time('mysql')
    ]);
}

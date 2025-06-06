<?php
/**
 * Plugin Name:     Assasyat Translate Langs
 * Plugin URI:      PLUGIN SITE HERE
 * Description:     PLUGIN DESCRIPTION HERE
 * Author:          Osama Imar
 * Author URI:      www.osamaimar.site
 * Text Domain:     translate-langs
 * Domain Path:     /languages
 * Version:         0.1.0
 *
 * @package         Translate_Langs
 */

// Your code starts here.
require_once plugin_dir_path(__FILE__) . 'database.php';
include_once plugin_dir_path(__FILE__) . 'Tabs/main.php';
require plugin_dir_path(__FILE__) . 'scanner/scanner.php';
require plugin_dir_path(__FILE__) . 'scanner/database-scanner.php';
require plugin_dir_path(__FILE__) . 'rtl.php';
require plugin_dir_path(__FILE__) . 'loading.php';
require plugin_dir_path(__FILE__) . 'api-urls.php';
register_activation_hook(__FILE__, 'setup_custom_translation_tables');
register_uninstall_hook(__FILE__, 'uninstall_custom_translation_plugin');




// 1. Register the AJAX endpoint for fetching translations
add_action('wp_ajax_get_translations', 'cls_get_translations');
add_action('wp_ajax_nopriv_get_translations', 'cls_get_translations');

function cls_get_translations() {
    global $wpdb;
    $translation_table = $wpdb->prefix . 'custom_translations';

    $hashes = isset($_POST['hashes']) ? $_POST['hashes'] : [];
    $target_lang = sanitize_text_field($_POST['lang']);

    if (!is_array($hashes) || empty($target_lang)) {
        wp_send_json_error('Invalid input');
    }

    $placeholders = implode(',', array_fill(0, count($hashes), '%s'));
    $query = $wpdb->prepare(
        "SELECT text_hash, original_text, translated_text FROM $translation_table WHERE text_hash IN ($placeholders) AND target_lang = %s",
        [...$hashes, $target_lang]
    );

    $results = $wpdb->get_results($query);
    $translations = [];
    foreach ($results as $row) {
        $translations[$row->text_hash] = ($target_lang === 'en') ? $row->original_text : $row->translated_text;
    }

    wp_send_json_success($translations);
}

// 2. Enqueue frontend scripts
add_action('wp_enqueue_scripts', 'cls_enqueue_scripts');
function cls_enqueue_scripts() {
     wp_enqueue_script(
        'headline-translate',
        plugin_dir_url(__FILE__) . 'js/headline-translate.js',
        [],
        null,
        true
    );

    wp_enqueue_script('cls-translation-js', plugin_dir_url(__FILE__) . 'js/cls-translation.js', ['jquery'], null, true);
    wp_localize_script('cls-translation-js', 'cls_ajax_obj', [
        'ajax_url' => admin_url('admin-ajax.php'),
    ]);

}

add_action('wp_ajax_add_api_tool', function () {
    global $wpdb;
    $table = $wpdb->prefix . 'api_integrations';

    $tool = sanitize_text_field($_POST['tool']);
    $model = sanitize_text_field($_POST['model']);
    $api_key = sanitize_text_field($_POST['api_key']);
    $api_url = esc_url_raw($_POST['api_url']);
    $is_active = intval($_POST['is_active']);

    $result = $wpdb->insert($table, [
        'tool'      => $tool,
        'model'     => $model,
        'api_key'   => $api_key,
        'api_url'   => $api_url,
        'is_active' => $is_active
    ]);

    if ($result) {
        wp_send_json_success();
    } else {
        wp_send_json_error();
    }
});



function get_translated_texts_by_lang(WP_REST_Request $request) {
    global $wpdb;
    $lang = $request->get_param('lang');
    $results = $wpdb->get_results($wpdb->prepare(
        "SELECT text_hash, translated_text FROM {$wpdb->prefix}custom_translations WHERE target_lang = %s AND translated_text IS NOT NULL",
        $lang
    ));

    return new WP_REST_Response($results, 200);
}



//////////////////////////////////////

function save_extracted_texts_to_db($request) {
    global $wpdb;

    $table = $wpdb->prefix . 'custom_translations';
    $texts = $request->get_json_params();
    if (!is_array($texts) || empty($texts)) {
        return new WP_REST_Response(['status' => 'error', 'message' => 'No texts received'], 400);
    }

    $hashes = array_column($texts, 'text_hash');
    $placeholders = implode(',', array_fill(0, count($hashes), '%s'));

    // جلب الموجود مسبقًا
    $existing = $wpdb->get_col(
        $wpdb->prepare("SELECT text_hash FROM $table WHERE text_hash IN ($placeholders) AND target_lang IS NULL", $hashes)
    );
    $existing_map = array_flip($existing);

    $inserted = 0;

    foreach ($texts as $item) {
        $text_hash = sanitize_text_field($item['text_hash']);
        $original_text = sanitize_text_field($item['original_text']);
        $source_lang = sanitize_text_field($item['source_lang']);

        // لا تدخل النص إذا كان موجود مسبقًا
        if (isset($existing_map[$text_hash])) {
            continue;
        }

        $result = $wpdb->insert($table, [
            'text_hash' => $text_hash,
            'original_text' => $original_text,
            'source_lang' => $source_lang,
        ]);

        if ($result !== false) {
            $inserted++;
        }
    }

    return new WP_REST_Response([
        'status' => 'success',
        'inserted' => $inserted,
        'skipped' => count($texts) - $inserted
    ]);
}


/////////////////////////////////////////////////////
add_action('admin_enqueue_scripts', function($hook) {
    wp_enqueue_script(
        'database-scanner',
        plugin_dir_url(__FILE__) . 'js/database-scanner.js',
        [],
        null,
        true // تحميله في الفوتر
    );
});


function get_avail_languages() {
    global $wpdb;

    // جلب الأكواد الفريدة للغات المستخدمة في الترجمات
    $lang_codes = $wpdb->get_col("
        SELECT DISTINCT source_lang FROM ".$wpdb->prefix."custom_translations
        UNION
        SELECT DISTINCT target_lang FROM ".$wpdb->prefix."custom_translations WHERE target_lang IS NOT NULL
    ");

    if (empty($lang_codes)) return [];

    $placeholders = implode(',', array_fill(0, count($lang_codes), '%s'));
    $query = "
        SELECT name, code, direction, flag_url
        FROM ".$wpdb->prefix."custom_languages
        WHERE code IN ($placeholders)
    ";
    $languages = $wpdb->get_results($wpdb->prepare($query, ...$lang_codes), ARRAY_A);

    return $languages;
}
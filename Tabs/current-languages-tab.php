<?php
include "action-api.php";
function cls_render_current_languages_tab() {
global $wpdb;

    $translations_table = $wpdb->prefix . 'custom_translations';
    $languages_table = $wpdb->prefix . 'custom_languages';

    // Get default language
    $default_lang = $wpdb->get_var("SELECT source_lang FROM $translations_table GROUP BY source_lang ORDER BY COUNT(*) DESC LIMIT 1");
    $default_lang_info = $wpdb->get_row($wpdb->prepare("SELECT * FROM $languages_table WHERE code = %s", $default_lang));
    
    // Get target languages
    $target_langs = $wpdb->get_col("SELECT target_lang FROM $translations_table WHERE target_lang != '' GROUP BY target_lang");

    echo '<div class="wrap">';
    echo '<div style="display:flex; justify-content:space-between; align-items:center; margin-bottom: 10px;">';
    echo '<p style="margin:0;"><strong>Default Language: ' . esc_html($default_lang_info->name) . '</strong></p>';
    echo '<button class="button button-primary" onclick="document.getElementById(\'addLanguageModal\').style.display=\'block\'">➕ Add Language</button>';
    echo '</div>';

    echo '<h2 style="margin-top:20px;">Current Languages</h2>';
    echo '<table class="widefat fixed striped">';
    echo '<thead><tr><th>Flag</th><th>Language</th><th>Total Texts</th><th>Translated</th><th>Untranslated</th><th>Missing Hashes</th><th>Completion</th><th>Actions</th></tr></thead>';

    echo '<tbody>';

    foreach ($target_langs as $lang_code) {
        $lang_info = $wpdb->get_row($wpdb->prepare("SELECT * FROM $languages_table WHERE code = %s", $lang_code));
        if (!$lang_info) continue;

        // Get counts for the language
        $total_texts = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(DISTINCT text_hash) FROM $translations_table 
             WHERE (source_lang = %s OR target_lang = %s)",
            $default_lang,
            $lang_code
        ));

        $translated_texts = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(DISTINCT text_hash) FROM $translations_table 
             WHERE target_lang = %s AND translated_text IS NOT NULL AND translated_text != ''",
            $lang_code
        ));

        $untranslated_texts = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(DISTINCT text_hash) FROM $translations_table 
             WHERE target_lang = %s AND (translated_text IS NULL OR translated_text = '')",
            $lang_code
        ));

        // Get count of missing hashes (texts added after language was added)
        $missing_hashes = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(DISTINCT t1.text_hash) 
             FROM $translations_table t1
             WHERE t1.source_lang = %s
             AND NOT EXISTS (
                 SELECT 1 FROM $translations_table t2 
                 WHERE t2.target_lang = %s AND t2.text_hash = t1.text_hash
             )",
            $default_lang,
            $lang_code
        ));

        // Calculate completion percentage
        $completion = $total_texts > 0 ? round(($translated_texts / $total_texts) * 100) : 0;

        echo '<tr>';
        echo '<td><img src="' . esc_url($lang_info->flag_url) . '" alt="" width="30"></td>';
        echo '<td>' . esc_html($lang_info->name) . '</td>';
        echo '<td>' . esc_html($total_texts) . '</td>';
        echo '<td>' . esc_html($translated_texts) . '</td>';
        echo '<td>' . esc_html($untranslated_texts) . '</td>';
        echo '<td>' . esc_html($missing_hashes) . ' 
                <a href="' . esc_url(admin_url('admin-post.php?action=cls_add_missing_hashes&lang=' . $lang_code)) . '" 
                onclick="return confirm(\'Are you sure you want to add missing translation rows for ' . esc_attr($lang_info->name) . '?\')" 
                class="button button-small" style="margin-left:5px;">➕ Add</a>
                ' . cls_get_missing_hashes_progress($lang_code) . '
            </td>';        
        echo '<td>';
        echo '<div style="background:#f0f0f0; height:20px; width:100%; border-radius:3px;">';
        echo '<div style="background:' . ($completion >= 80 ? '#46b450' : ($completion >= 50 ? '#ffb900' : '#dc3232')) . '; height:100%; width:' . esc_attr($completion) . '%; border-radius:3px;"></div>';
        echo '</div>';
        echo esc_html($completion) . '%';
        echo '</td>';
        echo '<td>
                <form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" onsubmit="return confirm(\'Are you sure?\');" style="display:inline-block;">
                    <input type="hidden" name="action" value="cls_delete_language">
                    <input type="hidden" name="lang_to_delete" value="' . esc_attr($lang_code) . '">
                    <input type="submit" class="button" value="Delete">
                </form>
              </td>';
        echo '</tr>';
    }

    echo '</tbody></table>';

    // MODAL: Add Language
    echo '<div id="addLanguageModal" style="display:none; position:fixed; top:10%; left:50%; transform:translateX(-50%); background:#fff; padding:20px; border:1px solid #ccc; z-index:9999;">
        <h3>Select Language to Add</h3>
        <form method="post" action="" id="add-language-form">';

    $used_languages = $wpdb->get_col("SELECT target_lang FROM $translations_table WHERE target_lang != '' GROUP BY target_lang");
    $placeholders = implode(',', array_fill(0, count($used_languages), '%s'));
    $query = "SELECT * FROM $languages_table" . (count($used_languages) ? " WHERE code NOT IN ($placeholders)" : '');
    $available_languages = count($used_languages) ? $wpdb->get_results($wpdb->prepare($query, ...$used_languages)) : $wpdb->get_results($query);

    echo '<select name="new_lang" required>';
    echo '<option value="">-- Select Language --</option>';
    foreach ($available_languages as $lang) {
        echo '<option value="' . esc_attr($lang->code) . '">' . esc_html($lang->name) . '</option>';
    }
    echo '</select><br><br>';
    echo '<button type="submit" class="button button-primary">Add Language</button> ';
    echo '<button type="button" class="button" onclick="document.getElementById(\'addLanguageModal\').style.display=\'none\'">Cancel</button>';
    echo '</form></div>';

    // Process Add Language
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['new_lang'])) {
        $new_lang = sanitize_text_field($_POST['new_lang']);
        
        // Use batches for better performance
        $batch_size = 4000 ;
        $offset = 0;
        
        do {
            $originals = $wpdb->get_results($wpdb->prepare(
                "SELECT DISTINCT text_hash, original_text FROM $translations_table 
                 WHERE source_lang = %s 
                 LIMIT %d OFFSET %d",
                $default_lang,
                $batch_size,
                $offset
            ));
            
            if (empty($originals)) break;
            
            foreach ($originals as $entry) {
                $exists = $wpdb->get_var($wpdb->prepare(
                    "SELECT 1 FROM $translations_table 
                     WHERE text_hash = %s AND target_lang = %s 
                     LIMIT 1",
                    $entry->text_hash,
                    $new_lang
                ));
                
                if (!$exists) {
                    $wpdb->insert($translations_table, [
                        'text_hash' => $entry->text_hash,
                        'original_text' => $entry->original_text,
                        'source_lang' => $default_lang,
                        'target_lang' => $new_lang,
                        'translated_text' => null,
                        'context' => '',
                        'created_at' => current_time('mysql'),
                        'updated_at' => current_time('mysql')
                    ]);
                }
            }
            
            $offset += $batch_size;
        } while (true);

        echo '<div class="updated"><p>✅ Language added and records initialized.</p></div>';
        cls_log_action('add_language', '✅ Added language and initialized records', $new_lang);

        echo '<script>setTimeout(() => location.href = location.href, 1000);</script>';
    }

    echo '</div>'; // .wrap

    if (isset($_GET['translated']) && $_GET['translated'] == 1) {
        echo '<div class="updated"><p>✅ All valid and untranslated texts were translated successfully.</p></div>';
    }

    if (isset($_GET['added']) && $_GET['added'] == 1) {
        echo '<div class="updated"><p>✅ Missing text rows have been added successfully.</p></div>';
    }

    render_translation_manager_page();
}
// حذف اللغة مع تحسين الأداء
add_action('admin_post_cls_delete_language', 'cls_handle_delete_language');
function cls_handle_delete_language() {
    if (!current_user_can('manage_options')) wp_die('Unauthorized');

    global $wpdb;
    if (isset($_POST['lang_to_delete'])) {
        $lang = sanitize_text_field($_POST['lang_to_delete']);
        $table = $wpdb->prefix . 'custom_translations';
        
        // حذف على دفعات لتجنب وقت التنفيذ الطويل
        $batch_size = 1000;
        do {
            $deleted = $wpdb->query($wpdb->prepare(
                "DELETE FROM $table WHERE target_lang = %s LIMIT %d",
                $lang,
                $batch_size
            ));
            if ($deleted === false || $deleted < $batch_size) break;
        } while (true);
        
        cls_log_action('delete_language', '🗑️ Deleted language and its translations', $lang);
    }

    wp_redirect(admin_url('admin.php?page=translation-plugin&tab=current_languages'));
    exit;
}


// تحسين وظيفة إضافة الهاشات الناقصة
add_action('admin_post_cls_add_missing_hashes', 'cls_add_missing_hashes');
function cls_add_missing_hashes() {
    if (!current_user_can('manage_options')) wp_die('Unauthorized');

    global $wpdb;
    $translations_table = $wpdb->prefix . 'custom_translations';

    $target_lang = sanitize_text_field($_GET['lang'] ?? '');
    if (!$target_lang) wp_die('Invalid target language.');

    $source_lang = $wpdb->get_var("SELECT source_lang FROM $translations_table GROUP BY source_lang ORDER BY COUNT(*) DESC LIMIT 1");
    $batch_size = 1000;
    $offset = 0;

    do {
        $missing = $wpdb->get_results($wpdb->prepare(
            "SELECT DISTINCT t1.text_hash, t1.original_text FROM $translations_table t1
             WHERE t1.source_lang = %s
             AND NOT EXISTS (
                 SELECT 1 FROM $translations_table t2 
                 WHERE t2.target_lang = %s AND t2.text_hash = t1.text_hash
                 LIMIT 1
             )
             LIMIT %d OFFSET %d",
            $source_lang,
            $target_lang,
            $batch_size,
            $offset
        ));

        if (empty($missing)) break;

        foreach ($missing as $row) {
            $wpdb->insert($translations_table, [
                'text_hash' => $row->text_hash,
                'original_text' => $row->original_text,
                'source_lang' => $source_lang,
                'target_lang' => $target_lang,
                'translated_text' => null,
                'context' => null,
                'created_at' => current_time('mysql'),
                'updated_at' => current_time('mysql')
            ]);
        }

        $offset += $batch_size;
    } while (true);

    cls_log_action('add_missing_hashes', '🪄 Added missing translation rows for language', $target_lang);

    wp_redirect(admin_url('admin.php?page=translation-plugin&tab=current_languages&added=1'));
    exit;
}

// Helper function to show progress
function cls_get_missing_hashes_progress($lang_code) {
    $process = get_option('cls_missing_hashes_process', []);
    if (empty($process) || $process['target_lang'] !== $lang_code) return '';

    if ($process['status'] === 'processing') {
                $percentage = $process['total'] > 0 ? round(($process['processed'] / $process['total']) * 100) : 0;        
                return '<div style="margin-top:5px;font-size:12px;color:#666;">
                Adding... ' . $process['processed'] . '/' . $process['total'] . '
                <div style="background:#f0f0f0; height:4px; width:100%; border-radius:2px; margin-top:2px;">
                    <div style="background:#2271b1; height:100%; width:' . $percentage . '%; border-radius:2px;"></div>
                </div>
                </div>';
    } elseif ($process['status'] === 'completed') {
        return '<div style="margin-top:5px;font-size:12px;color:#46b450;">✅ Completed</div>';
    }

    return '';
}
if (isset($_GET['processing'])) {
    echo '<div class="notice notice-info"><p>⏳ The missing translations are being added in the background. The page will update automatically.</p></div>';
}

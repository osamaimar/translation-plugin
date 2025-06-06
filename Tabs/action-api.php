<?php

function render_translation_manager_page() {
    $manager = Custom_Translation_Manager::get_instance();
    
    echo '<div class="wrap"><h1>Translation Management</h1>';
    
    // Process commands
    if (isset($_POST['start_translation'])) {
        $result = $manager->start_translation();
        if ($result === true) {
            echo '<div class="notice notice-success"><p>✅ Continuous translation process started!</p></div>';
        }
    } elseif (isset($_POST['stop_translation'])) {
        $manager->stop_translation();
        echo '<div class="notice notice-warning"><p>⏹ Translation process stopped!</p></div>';
    }
    
    global $wpdb;
    $translations_table = $wpdb->prefix . 'custom_translations';
    $languages_table = $wpdb->prefix . 'custom_languages';
    
    // Get all target languages
    $target_langs = $wpdb->get_col("SELECT DISTINCT target_lang FROM $translations_table WHERE target_lang IS NOT NULL");
    
    echo '<div class="card">
        <h2>Translation Status</h2>';
    
        // Get all target languages with their info
        $languages = $wpdb->get_results("
            SELECT lt.code, lt.name, lt.flag_url, 
                COUNT(DISTINCT ct.text_hash) as total_texts,
                SUM(CASE WHEN ct.translated_text IS NOT NULL AND ct.translated_text != '' THEN 1 ELSE 0 END) as translated_texts
            FROM $translations_table ct
            JOIN $languages_table lt ON ct.target_lang = lt.code
            WHERE ct.target_lang IS NOT NULL
            GROUP BY lt.code, lt.name, lt.flag_url
        ");
            // Calculate overall progress
        $overall_total = 0;
        $overall_translated = 0;
        foreach ($languages as $lang) {
            $overall_total += $lang->total_texts;
            $overall_translated += $lang->translated_texts;
        }
        $overall_percentage = $overall_total > 0 ? round(($overall_translated / $overall_total) * 100) : 0;
        // Overall progress bar
        echo '<div style="margin-bottom: 30px;">
                <h4>Overall Progress</h4>
                <div style="display: flex; align-items: center; gap: 15px; margin-bottom: 10px;">
                    <div style="flex-grow: 1; background: #f0f0f0; height: 30px; border-radius: 5px; overflow: hidden;">
                        <div style="background: ' . ($overall_percentage >= 80 ? '#46b450' : ($overall_percentage >= 50 ? '#ffb900' : '#dc3232')) . ';
                            height: 100%; width: ' . $overall_percentage . '%; transition: width 0.5s ease;">
                        </div>
                    </div>
                    <div style="font-weight: bold; min-width: 60px;">
                        ' . $overall_percentage . '%
                    </div>
                </div>
                <div style="display: flex; justify-content: space-between;">
                    <div>Total: ' . $overall_total . '</div>
                    <div>Translated: ' . $overall_translated . '</div>
                    <div>Remaining: ' . ($overall_total - $overall_translated) . '</div>
                </div>
            </div>';

    $is_running = $manager->is_translation_running();
    echo '<form method="post" style="margin-top:20px;">';
    if ($is_running) {
        echo '<button type="submit" name="stop_translation" class="button button-danger" style="background:#dc3232;color:white;">
            ⏹ Stop Translation
        </button>';
    } else {
        echo '<button type="submit" name="start_translation" class="button button-primary">
            ▶ Start Continuous Translation
        </button>';
    }
    echo '</form></div>';
    
    echo '<script>
    jQuery(document).ready(function($) {
        function updateProgress() {
            $.post(ajaxurl, {action: "get_translation_progress"}, function(response) {
                if(response.success) {
                    // You may want to update the progress for each language here
                    // This would require modifying the AJAX endpoint to return per-language data
                }
            });
        }
        
        ' . ($is_running ? 'setInterval(updateProgress, 5000); updateProgress();' : '') . '
    });
    </script>';
}


// AJAX endpoint to fetch progress
add_action('wp_ajax_get_translation_progress', function() {
    global $wpdb;
    $translations_table = $wpdb->prefix . 'custom_translations';
    $languages_table = $wpdb->prefix . 'custom_languages';
    
    $target_langs = $wpdb->get_col("SELECT DISTINCT target_lang FROM $translations_table WHERE target_lang IS NOT NULL");
    $progress = [];
    
    foreach ($target_langs as $lang_code) {
        $total = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(DISTINCT text_hash) FROM $translations_table WHERE target_lang = %s",
            $lang_code
        ));
        
        $translated = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(DISTINCT text_hash) FROM $translations_table 
             WHERE target_lang = %s AND translated_text IS NOT NULL AND translated_text != ''",
            $lang_code
        ));
        
        $progress[$lang_code] = [
            'total' => $total,
            'translated' => $translated,
            'untranslated' => $total - $translated,
            'percentage' => $total > 0 ? round(($translated / $total) * 100) : 0
        ];
    }
    
    $manager = Custom_Translation_Manager::get_instance();
    wp_send_json_success([
        'progress' => $progress,
        'is_running' => $manager->is_translation_running()
    ]);
});
// ================================
// Main Translation Manager Class
// ================================
class Custom_Translation_Manager {
    private static $instance = null;
    private $batch_size = 50;
    private $lock_key = 'translation_process_lock';
    private $last_id_key = 'translation_last_processed_id';
    private $api_config = [];

    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
            self::$instance->init();
        }
        return self::$instance;
    }

    private function init() {
        global $wpdb;
        $api_table = $wpdb->prefix . 'api_integrations';
        $this->api_config = $wpdb->get_row("SELECT * FROM $api_table WHERE is_active = 1 LIMIT 1", ARRAY_A);

        // Ensure the cron schedule is set up
        add_filter('cron_schedules', [$this, 'add_custom_schedule']);
        
        // Register the scheduled event if not already registered
        if (!wp_next_scheduled('process_translation_batch')) {
            wp_schedule_event(time(), 'five_minutes', 'process_translation_batch');
        }
        
        // Hook the batch processing function
        add_action('process_translation_batch', [$this, 'process_batch']);
    }

    public function setup_scheduler() {
        add_filter('cron_schedules', function($schedules) {
            $schedules['five_minutes'] = [
                'interval' => 2 * MINUTE_IN_SECONDS,
                'display' => __('Every 5 minutes')
            ];
            return $schedules;
        });
    }

    public function activate_plugin() {
        if (!wp_next_scheduled('process_translation_batch')) {
            wp_schedule_event(time(), 'five_minutes', 'process_translation_batch');
        }
    }

    public function deactivate_plugin() {
        wp_clear_scheduled_hook('process_translation_batch');
        delete_option($this->lock_key);
        delete_option($this->last_id_key);
    }


     public function stop_translation() {
        delete_option($this->lock_key);
        delete_option($this->last_id_key);
    }

    public function is_translation_running() {
        return (bool) get_option($this->lock_key, false);
    }

    public function add_custom_schedule($schedules) {
        $schedules['five_minutes'] = [
            'interval' => 5 * MINUTE_IN_SECONDS,
            'display' => __('Every 5 minutes')
        ];
        return $schedules;
    }

    public function start_translation() {
        // Verify API configuration exists
        if (empty($this->api_config)) {
            error_log('Translation Manager: No active API configuration found');
            return false;
        }

        // Verify we have texts to translate
        $progress = $this->get_translation_progress();
        if ($progress['pending'] <= 0) {
            error_log('Translation Manager: No texts to translate');
            return false;
        }

        // Start the process
        update_option($this->lock_key, time(), false);
        update_option($this->last_id_key, 0, false);
        
        // Immediately process the first batch
        $this->process_batch();
        
        return true;
    }

    public function get_translation_progress() {
        global $wpdb;
        $table = $wpdb->prefix . 'custom_translations';

        $total = $wpdb->get_var("SELECT COUNT(DISTINCT text_hash) FROM $table WHERE target_lang IS NOT NULL");
        $pending = $wpdb->get_var("SELECT COUNT(DISTINCT text_hash) FROM $table WHERE target_lang IS NOT NULL AND (translated_text IS NULL OR translated_text = '')");
        $completed = $total - $pending;
        $percentage = $total > 0 ? round(($completed / $total) * 100) : 0;

        return [
            'total' => $total,
            'pending' => $pending,
            'completed' => $completed,
            'percentage' => $percentage
        ];
    }

public function process_batch() {
        if (!$this->is_translation_running()) {
            error_log('Translation Manager: Process not running, skipping batch');
            return;
        }

        global $wpdb;
        $table = $wpdb->prefix . 'custom_translations';
        $last_id = (int) get_option($this->last_id_key, 0);

        $items = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $table 
             WHERE id > %d 
             AND target_lang IS NOT NULL 
             AND (translated_text IS NULL OR translated_text = '') 
             ORDER BY id ASC 
             LIMIT %d",
            $last_id,
            $this->batch_size
        ));

        if (empty($items)) {
            error_log('Translation Manager: No more items to process');
            $this->stop_translation();
            return;
        }

        foreach ($items as $item) {
            $translated = $this->translate_text($item->original_text, $item->source_lang, $item->target_lang);
            if ($translated) {
                $wpdb->update(
                    $table, 
                    ['translated_text' => $translated], 
                    ['id' => $item->id], 
                    ['%s'], 
                    ['%d']
                );
            }
            update_option($this->last_id_key, $item->id, false);
        }

        // Schedule next batch if there are more items
        $remaining = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $table 
             WHERE id > %d 
             AND target_lang IS NOT NULL 
             AND (translated_text IS NULL OR translated_text = '')",
            $item->id
        ));

        if ($remaining > 0) {
            wp_schedule_single_event(time() + 30, 'process_translation_batch');
            error_log("Translation Manager: Scheduled next batch with $remaining items remaining");
        } else {
            $this->stop_translation();
            error_log('Translation Manager: Translation completed');
        }
    }

    private function translate_text($text, $source, $target) {
            $api_url = $this->api_config['api_url'] ?? '';
            $api_key = $this->api_config['api_key'] ?? '';
            $tool = strtolower($this->api_config['tool'] ?? 'libretranslate');

            // Validate API configuration
            if (empty($api_url)) {
                set_transient('translation_error_message', 'API URL is not configured');
                return false;
            }

            // Skip API key check only for LibreTranslate
            if ($tool !== 'libretranslate' && empty($api_key)) {
                set_transient('translation_error_message', 'API key is required for ' . ucfirst($tool) . ' API');
                return false;
            }

            $headers = ['Content-Type' => 'application/json'];
            $body = [
                'q' => $text,
                'source' => substr($source, 0, 2),
                'target' => substr($target, 0, 2),
                'format' => 'text'
            ];

            try {
                // Prepare request based on API type
                if ($tool === 'openai') {
                    $headers['Authorization'] = 'Bearer ' . $api_key;
                    $body = json_encode([
                        'model' => $this->api_config['model'] ?? 'gpt-3.5-turbo',
                        'messages' => [['role' => 'user', 'content' => "Translate this from $source to $target: $text"]],
                        'temperature' => 0.5
                    ]);
                } elseif ($tool === 'google') {
                    $body = http_build_query([
                        'q' => $text,
                        'source' => $source,
                        'target' => $target,
                        'key' => $api_key
                    ]);
                    $headers = ['Content-Type' => 'application/x-www-form-urlencoded'];
                } elseif ($tool === 'deepl') {
                    $body = http_build_query([
                        'text' => $text,
                        'source_lang' => strtoupper($source),
                        'target_lang' => strtoupper($target),
                        'auth_key' => $api_key
                    ]);
                    $headers = ['Content-Type' => 'application/x-www-form-urlencoded'];
                } elseif ($tool === 'libretranslate' && !empty($api_key)) {
                    $headers['Authorization'] = 'Bearer ' . $api_key;
                }

                $response = wp_remote_post($api_url, [
                    'headers' => $headers,
                    'body' => is_array($body) ? json_encode($body) : $body,
                    'timeout' => 30
                ]);

                if (is_wp_error($response)) {
                    $error_message = 'API Connection Error: ' . $response->get_error_message();
                    set_transient('translation_error_message', $error_message);
                    error_log($error_message);
                    return false;
                }

                $response_code = wp_remote_retrieve_response_code($response);
                $response_body = wp_remote_retrieve_body($response);
                $decoded_response = json_decode($response_body, true);

                if ($response_code >= 400) {
                    $error_message = 'API Error (' . $response_code . '): ' . 
                                    ($decoded_response['error']['message'] ?? $decoded_response['error'] ?? $response_body);
                    set_transient('translation_error_message', $error_message);
                    error_log($error_message);
                    return false;
                }

                // Parse response based on API type
                switch ($tool) {
                    case 'openai':
                        return $decoded_response['choices'][0]['message']['content'] ?? false;
                    case 'google':
                        return $decoded_response['data']['translations'][0]['translatedText'] ?? false;
                    case 'deepl':
                        return $decoded_response['translations'][0]['text'] ?? false;
                    default: // LibreTranslate
                        return $decoded_response['translatedText'] ?? false;
                }

            } catch (Exception $e) {
                set_transient('translation_error_message', 'Translation Error: ' . $e->getMessage());
                error_log('Translation Exception: ' . $e->getMessage());
                return false;
            }
        }

    public function add_translation_item($text, $source, $target, $context = '') {
        global $wpdb;
        $table = $wpdb->prefix . 'custom_translations';
        $hash = hash('sha256', $text . $source . $target);

        $exists = $wpdb->get_var($wpdb->prepare("SELECT id FROM $table WHERE text_hash = %s", $hash));
        if (!$exists) {
            $wpdb->insert($table, [
                'text_hash' => $hash,
                'original_text' => $text,
                'source_lang' => $source,
                'target_lang' => $target,
                'context' => $context,
                'created_at' => current_time('mysql')
            ]);

            if (!$this->is_translation_running() && $this->get_translation_progress()['pending'] > 0) {
                $this->start_translation();
            }
        }
    }
}

add_action('plugins_loaded', function() {
    Custom_Translation_Manager::get_instance();
});


add_action('admin_post_cls_add_missing_hashes', 'cls_handle_add_missing_hashes');
function cls_handle_add_missing_hashes() {
    if (!current_user_can('manage_options')) wp_die('Unauthorized');

    $target_lang = sanitize_text_field($_GET['lang'] ?? '');
    if (!$target_lang) wp_die('Invalid target language.');

    // Start the background process
    update_option('cls_missing_hashes_process', [
        'target_lang' => $target_lang,
        'status' => 'processing',
        'processed' => 0,
        'total' => 0, // Will be updated in the first batch
        'last_update' => current_time('mysql')
    ], false);

    // Schedule the first batch immediately
    wp_schedule_single_event(time(), 'cls_process_missing_hashes_batch');

    wp_redirect(admin_url('admin.php?page=translation-plugin&tab=current_languages&processing=1'));
    exit;
}

// Scheduled batch processing
add_action('cls_process_missing_hashes_batch', 'cls_process_missing_hashes_batch');
function cls_process_missing_hashes_batch() {
    global $wpdb;
    $translations_table = $wpdb->prefix . 'custom_translations';
    
    $process = get_option('cls_missing_hashes_process', []);
    if (empty($process) || $process['status'] !== 'processing') return;

    $target_lang = $process['target_lang'];
    $source_lang = $wpdb->get_var("SELECT source_lang FROM $translations_table GROUP BY source_lang ORDER BY COUNT(*) DESC LIMIT 1");
    $batch_size = 500; // Smaller batch for better server load management

    // Get total count on first run
    if ($process['total'] == 0) {
        $total = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(DISTINCT t1.text_hash) 
             FROM $translations_table t1
             WHERE t1.source_lang = %s
             AND NOT EXISTS (
                 SELECT 1 FROM $translations_table t2 
                 WHERE t2.target_lang = %s AND t2.text_hash = t1.text_hash
             )",
            $source_lang,
            $target_lang
        ));
        
        $process['total'] = (int)$total;
        update_option('cls_missing_hashes_process', $process, false);
    }

    // Process current batch
    $missing = $wpdb->get_results($wpdb->prepare(
        "SELECT DISTINCT t1.text_hash, t1.original_text 
         FROM $translations_table t1
         WHERE t1.source_lang = %s
         AND NOT EXISTS (
             SELECT 1 FROM $translations_table t2 
             WHERE t2.target_lang = %s AND t2.text_hash = t1.text_hash
         )
         LIMIT %d",
        $source_lang,
        $target_lang,
        $batch_size
    ));

    if (empty($missing)) {
        // No more items to process - complete
        $process['status'] = 'completed';
        $process['last_update'] = current_time('mysql');
        update_option('cls_missing_hashes_process', $process, false);
        cls_log_action('add_missing_hashes', '✅ Completed adding missing translation rows for language', $target_lang);
        return;
    }

    // Process the batch
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

    // Update progress
    $process['processed'] += count($missing);
    $process['last_update'] = current_time('mysql');
    update_option('cls_missing_hashes_process', $process, false);

    // Schedule next batch if not completed
    if ($process['processed'] < $process['total']) {
        wp_schedule_single_event(time() + 5, 'cls_process_missing_hashes_batch');
    } else {
        $process['status'] = 'completed';
        update_option('cls_missing_hashes_process', $process, false);
        cls_log_action('add_missing_hashes', '✅ Completed adding missing translation rows for language', $target_lang);
    }
}

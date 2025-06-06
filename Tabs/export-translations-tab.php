<?php
// File: bulk-translations-import-export.php
add_action('admin_post_cls_export_translations', 'cls_export_translations_to_excel');
add_action('admin_post_cls_import_translations', 'cls_import_translations_from_excel');

function cls_export_translations_to_excel() {
    if (!current_user_can('manage_options')) {
        wp_die(__('You do not have sufficient permissions to access this page.'));
    }

    // ✅ تسجيل الحدث
    if (function_exists('cls_log_action')) {
        cls_log_action('export', 'Started bulk translations export');
    }

    global $wpdb;
    $table = $wpdb->prefix . 'custom_translations';
    $filename = 'translations_export_' . date('Y-m-d_H-i-s') . '.xls';

    // زيادة وقت التنفيذ وحد الذاكرة
    @set_time_limit(0);
    @ini_set('memory_limit', '512M');

    header("Content-Type: application/vnd.ms-excel");
    header("Content-Disposition: attachment; filename=$filename");
    header("Pragma: no-cache");
    header("Expires: 0");

    // الأعمدة المطلوبة فقط
    $columns = ['text_hash', 'original_text', 'source_lang', 'target_lang', 'translated_text'];

    // بدء إخراج البيانات
    echo "<table border='1'>";
    echo "<tr>";
    foreach ($columns as $column) {
        echo "<th>" . esc_html($column) . "</th>";
    }
    echo "</tr>";

    // التصدير بمجموعات لتجنب مشاكل الذاكرة
    $offset = 0;
    $batch_size = 5000; // عدد الصفوف في كل دفعة

    do {
        $results = $wpdb->get_results(
            $wpdb->prepare("SELECT " . implode(', ', $columns) . " FROM $table LIMIT %d OFFSET %d", 
            $batch_size, 
            $offset
        ), ARRAY_A);

        foreach ($results as $row) {
            echo "<tr>";
            foreach ($columns as $column) {
                echo "<td>" . esc_html($row[$column] ?? '') . "</td>";
            }
            echo "</tr>";
        }

        $offset += $batch_size;
        // إفراز المخزن المؤقت لضمان عدم تجاوز حد الذاكرة
        ob_flush();
        flush();
    } while (!empty($results));

    echo "</table>";

    // ✅ تسجيل الحدث
    if (function_exists('cls_log_action')) {
        cls_log_action('export', 'Completed bulk translations export');
    }

    exit;
}

function cls_import_translations_from_excel() {
    if (!current_user_can('manage_options')) {
        wp_die(__('You do not have required permissions to access this page.'));
    }

    check_admin_referer('cls_import_translations_nonce');

    if (empty($_FILES['import_file']['tmp_name'])) {
        wp_die(__('Please upload a file to import.'));
    }

    $file_path = $_FILES['import_file']['tmp_name'];
    $file_type = wp_check_filetype($_FILES['import_file']['name'], ['xls' => 'application/vnd.ms-excel']);

    if ($file_type['ext'] !== 'xls') {
        wp_die(__('Please upload a valid .xls file.'));
    }

    // ✅ تسجيل الحدث
    if (function_exists('cls_log_action')) {
        cls_log_action('import', 'Started bulk translations import');
    }

    global $wpdb;
    $table = $wpdb->prefix . 'custom_translations';

    // زيادة وقت التنفيذ وحد الذاكرة
    @set_time_limit(0);
    @ini_set('memory_limit', '512M');

    // استخدام مكتبة PHPExcel/PhpSpreadsheet لمعالجة الملفات الكبيرة
    require_once ABSPATH . 'wp-admin/includes/file.php';
    if (!class_exists('SpreadsheetReader')) {
        require_once ABSPATH . 'wp-content/plugins/custom-translations/vendor/autoload.php';
    }

    try {
        $reader = new SpreadsheetReader($file_path);
        $total_rows = count($reader);
        
        // تخطي الصف الأول (العناوين)
        $reader->next();

        $imported = 0;
        $updated = 0;
        $batch = [];
        $batch_size = 1000; // عدد الصفوف في كل دفعة

        foreach ($reader as $row) {
            if (empty($row[0])) continue;

            $data = [
                'text_hash' => sanitize_text_field($row[0]),
                'original_text' => sanitize_textarea_field($row[1]),
                'source_lang' => sanitize_text_field($row[2]),
                'target_lang' => sanitize_text_field($row[3]),
                'translated_text' => sanitize_textarea_field($row[4])
            ];

            $batch[] = $data;

            // معالجة الدفعات عند الوصول للحجم المحدد
            if (count($batch) >= $batch_size) {
                $processed = cls_process_import_batch($batch, $table);
                $imported += $processed['imported'];
                $updated += $processed['updated'];
                $batch = [];
            }
        }

        // معالجة أي بقايا بعد انتهاء الحلقة
        if (!empty($batch)) {
            $processed = cls_process_import_batch($batch, $table);
            $imported += $processed['imported'];
            $updated += $processed['updated'];
        }

        // ✅ تسجيل الحدث
        if (function_exists('cls_log_action')) {
            cls_log_action('import', sprintf(
                'Completed bulk translations import: %d new, %d updated', 
                $imported, 
                $updated
            ));
        }

        wp_redirect(add_query_arg([
            'page' => 'translations-import-export',
            'imported' => $imported,
            'updated' => $updated,
            'success' => 1
        ], admin_url('admin.php')));
        exit;

    } catch (Exception $e) {
        // ✅ تسجيل الخطأ
        if (function_exists('cls_log_action')) {
            cls_log_action('error', 'Import failed: ' . $e->getMessage());
        }

        wp_die(__('Error processing import file: ') . $e->getMessage());
    }
}

function cls_process_import_batch($batch, $table) {
    global $wpdb;
    
    $imported = 0;
    $updated = 0;

    // بدء المعاملة
    $wpdb->query('START TRANSACTION');

    try {
        foreach ($batch as $data) {
            // التحقق من وجود الترجمة
            $existing = $wpdb->get_row($wpdb->prepare(
                "SELECT id, translated_text FROM $table 
                WHERE text_hash = %s AND target_lang = %s",
                $data['text_hash'],
                $data['target_lang']
            ));

            if ($existing) {
                // تحديث الترجمة الموجودة إذا كانت مختلفة
                if ($existing->translated_text !== $data['translated_text']) {
                    $wpdb->update(
                        $table,
                        [
                            'translated_text' => $data['translated_text'],
                            'updated_at' => current_time('mysql')
                        ],
                        ['id' => $existing->id]
                    );
                    $updated++;
                }
            } else {
                // إنشاء سجلين جديدين (للنص الأصلي والترجمة)
                
                // 1. التحقق من وجود النص الأصلي
                $original_exists = $wpdb->get_var($wpdb->prepare(
                    "SELECT COUNT(*) FROM $table 
                    WHERE text_hash = %s AND target_lang IS NULL",
                    $data['text_hash']
                ));

                if (!$original_exists) {
                    $wpdb->insert(
                        $table,
                        [
                            'text_hash' => $data['text_hash'],
                            'original_text' => $data['original_text'],
                            'source_lang' => $data['source_lang'],
                            'target_lang' => null,
                            'translated_text' => null,
                            'created_at' => current_time('mysql'),
                            'updated_at' => current_time('mysql')
                        ]
                    );
                }

                // 2. إضافة الترجمة الجديدة
                $wpdb->insert(
                    $table,
                    [
                        'text_hash' => $data['text_hash'],
                        'original_text' => $data['original_text'],
                        'source_lang' => $data['source_lang'],
                        'target_lang' => $data['target_lang'],
                        'translated_text' => $data['translated_text'],
                        'created_at' => current_time('mysql'),
                        'updated_at' => current_time('mysql')
                    ]
                );

                $imported++;
            }
        }

        // تأكيد المعاملة
        $wpdb->query('COMMIT');

        return [
            'imported' => $imported,
            'updated' => $updated
        ];

    } catch (Exception $e) {
        // التراجع عن المعاملة في حالة الخطأ
        $wpdb->query('ROLLBACK');
        throw $e;
    }
}

// إضافة صفحة الاستيراد/التصدير
add_action('admin_menu', 'cls_add_import_export_menu');

function cls_add_import_export_menu() {
    add_submenu_page(
        'custom-translations',
        'Bulk Import/Export Translations',
        'Bulk Import/Export',
        'manage_options',
        'translations-import-export',
        'cls_import_export_page'
    );
}

function cls_import_export_page() {
    // عرض نتائج الاستيراد إذا وجدت
    $imported = isset($_GET['imported']) ? (int)$_GET['imported'] : 0;
    $updated = isset($_GET['updated']) ? (int)$_GET['updated'] : 0;
    $success = isset($_GET['success']);
    ?>
    <div class="wrap">
        <h1>Bulk Import/Export Translations</h1>
        
        <?php if ($success): ?>
            <div class="notice notice-success">
                <p>Import completed successfully! <?php echo $imported; ?> new translations added, <?php echo $updated; ?> translations updated.</p>
            </div>
        <?php endif; ?>
        
        <div class="card">
            <h2>Export Translations</h2>
            <form action="<?php echo admin_url('admin-post.php'); ?>" method="post">
                <input type="hidden" name="action" value="cls_export_translations">
                <?php wp_nonce_field('cls_export_translations_nonce'); ?>
                <p>Export all translations to Excel file (XLS format).</p>
                <p class="description">For large databases, this may take several minutes.</p>
                <button type="submit" class="button button-primary">Export to Excel</button>
            </form>
        </div>
        
        <div class="card">
            <h2>Import Translations</h2>
            <form enctype="multipart/form-data" action="<?php echo admin_url('admin-post.php'); ?>" method="post">
                <input type="hidden" name="action" value="cls_import_translations">
                <?php wp_nonce_field('cls_import_translations_nonce'); ?>
                <p>Select Excel file (XLS format) to import:</p>
                <input type="file" name="import_file" accept=".xls" required>
                <p class="description">File must match the exported format with these columns: text_hash, original_text, source_lang, target_lang, translated_text</p>
                <p class="description">Large files may take several minutes to process.</p>
                <button type="submit" class="button button-primary">Import Translations</button>
            </form>
        </div>
    </div>
    <?php
}
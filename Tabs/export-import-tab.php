<?php
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
    use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
    use PhpOffice\PhpSpreadsheet\Cell\Cell;
    use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
    use PhpOffice\PhpSpreadsheet\Spreadsheet\Reader\IReader;
    use PhpOffice\PhpSpreadsheet\Writer\Xlsx\Writer;
    use PhpOffice\PhpSpreadsheet\Spreadsheet\Reader\Xlsx as ReaderXlsx;
    use PhpOffice\PhpSpreadsheet\Spreadsheet\Writer\Xlsx as WriterXlsx;
    use PhpOffice\PhpSpreadsheet\Spreadsheet\Writer\Xlsx\Worksheet as WriterWorksheet;
// Unified Export + Import Tab (.xlsx format with 4 columns)
// Export and Import actions for CSV
add_action('admin_post_cls_export_translations', 'cls_export_translations_to_csv');
add_action('admin_post_cls_import_translations', 'cls_import_translations_csv');

function cls_render_import_export_tab() {
    if (isset($_GET['import']) && $_GET['import'] === 'success') {
        echo '<div class="updated"><p>✅ Import successful.</p></div>';
    }

    echo '<h2>⬇️ Export Translations</h2>';
    echo '<form method="post" action="' . admin_url('admin-post.php') . '">
        <input type="hidden" name="action" value="cls_export_translations" />
        <input type="submit" class="button button-primary" value="Download CSV (.csv)" />
    </form>';

    echo '<hr style="margin:30px 0;">';

    echo '<h2>⬆️ Import Translations</h2>';
    echo '<form method="post" enctype="multipart/form-data" action="' . admin_url('admin-post.php') . '">
        <input type="hidden" name="action" value="cls_import_translations" />
        <input type="file" name="import_file" accept=".csv" required />
        <input type="submit" class="button button-primary" value="Import CSV (.csv)" />
    </form>';

    echo '<h3 style="margin-top:30px;">📄 Example File Format</h3>';
    echo '<p>The file must contain the following columns in order:</p>';
    echo '<table class="widefat fixed striped" style="max-width:100%; margin-top:10px;">
            <thead>
                <tr>
                    <th>original_text</th>
                    <th>source_lang</th>
                    <th>target_lang</th>
                    <th>translated_text</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td>Hello</td>
                    <td>en</td>
                    <td>ar</td>
                    <td>مرحبا</td>
                </tr>
            </tbody>
        </table>';
}


function cls_export_translations_to_csv() {
    if (!current_user_can('manage_options')) {
        wp_die(__('You do not have sufficient permissions to access this page.'));
    }

    if (function_exists('cls_log_action')) {
        cls_log_action('export', 'Exported translations as CSV');
    }

    global $wpdb;
    $table = $wpdb->prefix . 'custom_translations';
    $filename = 'translations_export_' . date('Y-m-d_H-i-s') . '.csv';

    // Set UTF-8 with BOM for Arabic compatibility
    header("Content-Type: text/csv; charset=UTF-8");
    header("Content-Disposition: attachment; filename=\"$filename\"");
    header("Pragma: no-cache");
    header("Expires: 0");

    $output = fopen('php://output', 'w');

    // Add UTF-8 BOM
    fwrite($output, "\xEF\xBB\xBF");

    // Write CSV header
    fputcsv($output, ['original_text', 'source_lang', 'target_lang', 'translated_text']);

    $offset = 0;
    $limit = 1000;

    while (true) {
        $rows = $wpdb->get_results(
            $wpdb->prepare("SELECT original_text, source_lang, target_lang, translated_text FROM $table LIMIT %d OFFSET %d", $limit, $offset),
            ARRAY_A
        );

        if (empty($rows)) break;

        foreach ($rows as $row) {
            fputcsv($output, $row);
        }

        $offset += $limit;
        ob_flush();
        flush();
    }

    fclose($output);
    exit;
}



function cls_import_translations_csv() {
    if (!current_user_can('manage_options')) wp_die('Permission denied');
    if (!isset($_FILES['import_file']) || $_FILES['import_file']['error'] !== UPLOAD_ERR_OK) {
        wp_die('No valid file uploaded.');
    }

    // Increase limits for large imports
    ini_set('memory_limit', '2048M');
    set_time_limit(300); // 5 minutes

    $file = fopen($_FILES['import_file']['tmp_name'], 'r');
    if (!$file) {
        wp_die('Unable to read uploaded file.');
    }

    global $wpdb;
    $table = $wpdb->prefix . 'custom_translations';
    $inserted = 0;
    $updated = 0;

    // Read and clean header (remove BOM if present)
    $header = fgetcsv($file);

    if (isset($header[0])) {
        $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', $header[0]);
    }

    $map = array_flip($header);

    if (!isset($map['original_text'], $map['source_lang'], $map['target_lang'], $map['translated_text'])) {
        error_log('❌ Invalid CSV header: ' . print_r($header, true));
        wp_die('CSV file must contain: original_text, source_lang, target_lang, translated_text');
    }

    // Prepare for batch processing
    $batch_size = 2000;
    $batch_data = [];
    $batch_counter = 0;

    while (($data = fgetcsv($file)) !== false) {
        $original_text = trim($data[$map['original_text']] ?? '');
        $source_lang = sanitize_text_field($data[$map['source_lang']] ?? '');
        $target_lang = sanitize_text_field($data[$map['target_lang']] ?? '');
        $translated_text = trim($data[$map['translated_text']] ?? '');

        if (!$original_text || !$source_lang || !$target_lang) continue;

        $text_hash = hash('sha256', $original_text);
        $now = current_time('mysql');

        $batch_data[] = [
            'text_hash' => $text_hash,
            'original_text' => $original_text,
            'source_lang' => $source_lang,
            'target_lang' => $target_lang,
            'translated_text' => $translated_text,
            'context' => null,
            'created_at' => $now,
            'updated_at' => $now
        ];

        $batch_counter++;

        if ($batch_counter >= $batch_size) {
            $result = cls_process_import_batch($batch_data, $table);
            $inserted += $result['inserted'];
            $updated += $result['updated'];
            $batch_data = [];
            $batch_counter = 0;
        }
    }

    if (!empty($batch_data)) {
        $result = cls_process_import_batch($batch_data, $table);
        $inserted += $result['inserted'];
        $updated += $result['updated'];
    }

    fclose($file);

    wp_redirect(add_query_arg([
        'import' => 'success',
        'inserted' => $inserted,
        'updated' => $updated
    ], admin_url('admin.php?page=translation-plugin&tab=import-export')));
    exit;
}


function cls_process_import_batch($batch_data, $table) {
    global $wpdb;
    $inserted = 0;
    $updated = 0;

    // Get existing records for this batch
    $existing_hashes = array_column($batch_data, 'text_hash');
    $existing_targets = array_column($batch_data, 'target_lang');
    
    $placeholders = implode(',', array_fill(0, count($batch_data), '%s'));
    $query = $wpdb->prepare(
        "SELECT text_hash, target_lang FROM $table 
         WHERE text_hash IN ($placeholders) 
         AND target_lang IN ($placeholders)",
        array_merge($existing_hashes, $existing_targets)
    );
    
    $existing_records = $wpdb->get_results($query, ARRAY_A);
    $existing_map = [];
    foreach ($existing_records as $record) {
        $existing_map[$record['text_hash'] . '|' . $record['target_lang']] = true;
    }

    // Process batch
    foreach ($batch_data as $row) {
        $exists = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $table WHERE text_hash = %s AND target_lang = %s",
            $row['text_hash'], $row['target_lang']
        ));

        if ($exists) {
            $wpdb->update(
                $table,
                [
                    'translated_text' => $row['translated_text'],
                    'updated_at' => $row['updated_at']
                ],
                [
                    'text_hash' => $row['text_hash'],
                    'target_lang' => $row['target_lang']
                ]
            );
            $updated++;
        } else {
            // Insert new record
            $wpdb->insert($table, $row);
            $inserted++;
        }

    }

    return ['inserted' => $inserted, 'updated' => $updated];
}
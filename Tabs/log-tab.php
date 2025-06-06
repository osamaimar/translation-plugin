<?php
global $wpdb;
$table = $wpdb->prefix . 'custom_translation_logs';

// Pagination variables
$items_per_page = isset($_GET['items_per_page']) ? intval($_GET['items_per_page']) : 20;
$current_page = isset($_GET['paged']) ? max(1, intval($_GET['paged'])) : 1;
$offset = ($current_page - 1) * $items_per_page;

// Total count
$total_items = $wpdb->get_var("SELECT COUNT(*) FROM $table");
$total_pages = ceil($total_items / $items_per_page);

// Fetch paginated results
$logs = $wpdb->get_results($wpdb->prepare(
    "SELECT * FROM $table ORDER BY created_at DESC LIMIT %d OFFSET %d",
    $items_per_page,
    $offset
));

echo '<div class="wrap">';
echo '<h2>Translation Logs</h2>';

if (!$logs) {
    echo '<p>No logs found.</p>';
    return;
}

echo '<div style="margin-bottom: 10px;">';
echo '<form method="get" style="display: inline-block; margin-right: 10px;">';
echo '<input type="hidden" name="page" value="' . esc_attr($_GET['page']) . '">';
echo '<label for="items_per_page">Items per page: </label>';
echo '<select name="items_per_page" onchange="this.form.submit()">';
foreach ([10, 20, 50, 100] as $count) {
    $selected = $items_per_page == $count ? 'selected' : '';
    echo "<option value='$count' $selected>$count</option>";
}
echo '</select>';
echo '</form>';
echo '</div>';

echo '<table class="widefat fixed striped">';
echo '<thead><tr>
    <th style="width: 140px;">Date</th>
    <th style="width: 100px;">Type</th>
    <th style="width: 100px;">Language</th>
    <th>Message</th>
</tr></thead><tbody>';

foreach ($logs as $log) {
    echo '<tr>';
    echo '<td>' . esc_html(date("Y-m-d H:i", strtotime($log->created_at))) . '</td>';
    echo '<td><code>' . esc_html($log->action_type) . '</code></td>';
    echo '<td>' . esc_html($log->lang_code ?: '-') . '</td>';
    echo '<td>' . esc_html($log->message) . '</td>';
    echo '</tr>';
}

echo '</tbody></table>';

// Render pagination
if ($total_pages > 1) {
    echo '<div class="tablenav-pages" style="margin-top: 20px;">';
    $base_url = remove_query_arg('paged');
    $base_url = add_query_arg('paged', '%#%');
    $base_url = add_query_arg('items_per_page', $items_per_page, $base_url);

    echo paginate_links([
        'base' => $base_url,
        'format' => '',
        'current' => $current_page,
        'total' => $total_pages,
        'prev_text' => '« Prev',
        'next_text' => 'Next »',
        'type' => 'plain'
    ]);
    echo '</div>';
}

echo '</div>'; // wrap
?>

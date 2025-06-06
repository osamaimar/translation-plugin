<?php
function cls_render_manual_translate_tab() {
    global $wpdb;
    $table = $wpdb->prefix . 'custom_translations';
    $lang_table = $wpdb->prefix . 'custom_languages';

    // Get all available target languages and their names
    $language_codes = $wpdb->get_col("SELECT DISTINCT target_lang FROM $table WHERE target_lang != ''");
    $languages = [];
    if (!empty($language_codes)) {
        $placeholders = implode(',', array_fill(0, count($language_codes), '%s'));
        $query = $wpdb->prepare("SELECT code, name FROM $lang_table WHERE code IN ($placeholders)", ...$language_codes);
        $results = $wpdb->get_results($query);
        foreach ($results as $row) {
            $languages[$row->code] = $row->name;
        }
    }
    ?>

    <div id="manual-translate-container" style="margin-bottom: 20px;">
        <div style="display:flex; align-items:center; gap:15px; flex-wrap:wrap;">
            <div>
                <label for="language_select"><strong>Select Language:</strong></label><br>
                <select id="language_select">
                    <option value="">-- Choose Language --</option>
                    <?php foreach ($languages as $code => $name): ?>
                        <option value="<?php echo esc_attr($code); ?>"><?php echo esc_html($name); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div>
                <label for="perPageSelect"><strong>Items per page:</strong></label><br>
                <select id="perPageSelect">
                    <option value="5">5</option>
                    <option value="10" selected>10</option>
                    <option value="25">25</option>
                    <option value="50">50</option>
                </select>
            </div>

            <button id="addNewTextBtn" class="button button-primary">Add New Original Text</button>

            <form id="searchForm" style="margin-left:auto; display:flex; gap:5px; align-items:flex-end;">
                <input type="text" id="searchInput" placeholder="Search..." />
                <button type="submit" class="button">Search</button>
            </form>
        </div>

        <div style="clear:both;"></div>
        <div id="translations_table_wrapper" style="margin-top: 20px;"></div>
    </div>

    <div id="editModal" style="display:none; position:fixed; top:50%; left:50%; transform:translate(-50%,-50%); background:#fff; padding:20px; border-radius:8px; box-shadow:0 0 10px rgba(0,0,0,0.3); z-index:9999;">
        <h3>Edit Translation</h3>
        <form id="editForm">
            <input type="hidden" id="edit_id" name="id">
            <textarea name="translated_text" id="edit_translated_text" rows="4" style="width:100%"></textarea>
            <button type="submit" class="button button-primary">Save</button>
            <button type="button" id="closeEditModal" class="button">Cancel</button>
        </form>
    </div>

    <div id="addTextModal" style="display:none; position:fixed; top:50%; left:50%; transform:translate(-50%,-50%); background:#fff; padding:20px; border-radius:8px; box-shadow:0 0 10px rgba(0,0,0,0.3); z-index:9999; width:500px; max-width:90%;">
        <h3>Add New Original Text</h3>
        <form id="addTextForm">
            <div style="margin-bottom:15px;">
                <label for="new_original_text" style="display:block; margin-bottom:5px;"><strong>Original Text (English):</strong></label>
                <textarea name="original_text" id="new_original_text" rows="4" style="width:100%" required></textarea>
                <p id="textError" style="color:red; margin-top:5px; display:none;">Text must contain English characters only</p>
            </div>
            <button type="submit" class="button button-primary">Add Text</button>
            <button type="button" id="closeAddModal" class="button">Cancel</button>
        </form>
    </div>

    <script>
    document.addEventListener("DOMContentLoaded", function() {
        const langSelect = document.getElementById("language_select");
        const searchForm = document.getElementById("searchForm");
        const searchInput = document.getElementById("searchInput");
        const perPageSelect = document.getElementById("perPageSelect");
        const wrapper = document.getElementById("translations_table_wrapper");
        const modal = document.getElementById("editModal");
        const closeEditModal = document.getElementById("closeEditModal");
        const addTextModal = document.getElementById("addTextModal");
        const addTextForm = document.getElementById("addTextForm");
        const newOriginalText = document.getElementById("new_original_text");
        const textError = document.getElementById("textError");
        const addNewTextBtn = document.getElementById("addNewTextBtn");
        const closeAddModal = document.getElementById("closeAddModal");

        // Show add text modal
        addNewTextBtn.addEventListener("click", () => {
            addTextModal.style.display = "block";
            newOriginalText.focus();
        });

        // Close add text modal
        closeAddModal.addEventListener("click", () => {
            addTextModal.style.display = "none";
            addTextForm.reset();
            textError.style.display = "none";
        });

        // Validate and submit new original text
        addTextForm.addEventListener("submit", function(e) {
            e.preventDefault();
            
            const text = newOriginalText.value.trim();
            if (!text) return;
            
            // Check if text contains English characters
            if (!/^[a-zA-Z0-9\s\p{P}]+$/u.test(text)) {
                textError.style.display = "block";
                return;
            }
            
            textError.style.display = "none";
            
            fetch(ajaxurl, {
                method: 'POST',
                body: new URLSearchParams({
                    action: 'cls_add_original_text',
                    original_text: text
                })
            })
            .then(res => res.json())
            .then(res => {
                if (res.success) {
                    alert("Text added successfully!");
                    addTextModal.style.display = "none";
                    addTextForm.reset();
                    // Reload current view if a language is selected
                    if (langSelect.value) {
                        loadTranslations(langSelect.value, searchInput.value, 1, parseInt(perPageSelect.value));
                    }
                } else {
                    alert("Error: " + (res.message || "Failed to add text"));
                }
            });
        });

        function loadTranslations(lang, search = '', page = 1, per_page = 10) {
            if (!lang) return (wrapper.innerHTML = '');
            const params = new URLSearchParams({action: 'cls_get_translations_by_lang', lang, search, page, per_page});
            fetch(ajaxurl + '?' + params.toString())
                .then(res => res.json())
                .then(data => {
                    if (data.success) {
                        const rows = data.data.data;
                        const totalPages = data.data.total_pages;
                        const currentPage = data.data.page;

                        let html = '<table class="widefat fixed"><thead><tr><th>Original Text</th><th>Language</th><th>Translated Text</th><th>Actions</th></tr></thead><tbody>';
                        rows.forEach(row => {
                            html += `<tr data-id="${row.id}">
                                <td>${row.original_text}</td>
                                <td>${langSelect.options[langSelect.selectedIndex].text}</td>
                                <td class="translated-cell">${row.translated_text || ''}</td>
                                <td>
                                    <button class="button edit-btn" data-id="${row.id}" data-text="${row.translated_text || ''}">Edit</button>
                                    <button class="button delete-btn" data-id="${row.id}">Delete</button>
                                </td>
                            </tr>`;
                        });
                        html += '</tbody></table>';

                        if (totalPages > 1) {
                            html += '<div class="pagination" style="margin-top:10px; display:flex; flex-wrap:wrap; gap:4px; align-items:center;">';

                            const maxButtons = 5;
                            const half = Math.floor(maxButtons / 2);
                            let start = Math.max(1, currentPage - half);
                            let end = Math.min(totalPages, start + maxButtons - 1);
                            if (end - start < maxButtons - 1) start = Math.max(1, end - maxButtons + 1);

                            if (start > 1) {
                                html += `<button class="button pagination-btn" data-page="1">1</button>`;
                                if (start > 2) html += `<span style="padding:0 4px;">...</span>`;
                            }

                            for (let i = start; i <= end; i++) {
                                html += `<button class="button pagination-btn${i === currentPage ? ' button-primary' : ''}" data-page="${i}">${i}</button>`;
                            }

                            if (end < totalPages) {
                                if (end < totalPages - 1) html += `<span style="padding:0 4px;">...</span>`;
                                html += `<button class="button pagination-btn" data-page="${totalPages}">${totalPages}</button>`;
                            }

                            html += '</div>';
                        }

                        wrapper.innerHTML = html;

                        document.querySelectorAll(".edit-btn").forEach(btn => {
                            btn.addEventListener("click", () => {
                                document.getElementById("edit_id").value = btn.dataset.id;
                                document.getElementById("edit_translated_text").value = btn.dataset.text;
                                modal.style.display = "block";
                            });
                        });

                        document.querySelectorAll(".delete-btn").forEach(btn => {
                            btn.addEventListener("click", () => {
                                if (confirm("Are you sure you want to delete this translation?")) {
                                    fetch(ajaxurl, {
                                        method: 'POST',
                                        body: new URLSearchParams({ action: 'cls_delete_translation', id: btn.dataset.id })
                                    })
                                    .then(res => res.json())
                                    .then(res => {
                                        if (res.success) {
                                            const row = document.querySelector(`tr[data-id='${btn.dataset.id}']`);
                                            row?.remove();
                                        } else {
                                            alert("Failed to delete translation.");
                                        }
                                    });
                                }
                            });
                        });

                        document.querySelectorAll(".pagination-btn").forEach(btn => {
                            btn.addEventListener("click", () => {
                                loadTranslations(langSelect.value, searchInput.value, parseInt(btn.dataset.page), parseInt(perPageSelect.value));
                            });
                        });
                    }
                });
        }

        langSelect.addEventListener("change", () => loadTranslations(langSelect.value, searchInput.value, 1, parseInt(perPageSelect.value)));
        searchForm.addEventListener("submit", function(e) {
            e.preventDefault();
            loadTranslations(langSelect.value, searchInput.value, 1, parseInt(perPageSelect.value));
        });
        perPageSelect.addEventListener("change", () => {
            loadTranslations(langSelect.value, searchInput.value, 1, parseInt(perPageSelect.value));
        });

        document.getElementById("editForm").addEventListener("submit", function(e) {
            e.preventDefault();
            const formData = new FormData(this);
            formData.append("action", "cls_update_translation");

            fetch(ajaxurl, {
                method: 'POST',
                body: new URLSearchParams([...formData])
            })
            .then(res => res.json())
            .then(res => {
                if (res.success) {
                    const id = document.getElementById("edit_id").value;
                    const newText = document.getElementById("edit_translated_text").value;
                    const cell = document.querySelector(`tr[data-id='${id}'] .translated-cell`);
                    if (cell) cell.textContent = newText;
                    document.querySelector(`button.edit-btn[data-id='${id}']`).setAttribute("data-text", newText);
                    modal.style.display = "none";
                } else {
                    alert("Failed to update translation.");
                }
            });
        });

        closeEditModal.addEventListener("click", () => modal.style.display = "none");
    });
    </script>
<?php }

add_action('wp_ajax_cls_get_translations_by_lang', function() {
    global $wpdb;
    $lang = sanitize_text_field($_GET['lang']);
    $search = isset($_GET['search']) ? sanitize_text_field($_GET['search']) : '';
    $page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
    $per_page = isset($_GET['per_page']) ? max(1, intval($_GET['per_page'])) : 10;
    $offset = ($page - 1) * $per_page;
    $table = $wpdb->prefix . 'custom_translations';

    $query = "SELECT id, original_text, target_lang, translated_text FROM $table WHERE target_lang = %s";
    $params = [$lang];

    $total_query = "SELECT COUNT(*) FROM $table WHERE target_lang = %s";
    $total_params = [$lang];

    if (!empty($search)) {
        $query .= " AND (original_text LIKE %s OR translated_text LIKE %s)";
        $total_query .= " AND (original_text LIKE %s OR translated_text LIKE %s)";
        $like = '%' . $wpdb->esc_like($search) . '%';
        $params[] = $like;
        $params[] = $like;
        $total_params[] = $like;
        $total_params[] = $like;
    }

    $total = $wpdb->get_var($wpdb->prepare($total_query, ...$total_params));

    $query .= " LIMIT %d OFFSET %d";
    $params[] = $per_page;
    $params[] = $offset;

    $results = $wpdb->get_results($wpdb->prepare($query, ...$params));

    wp_send_json_success([
        'data' => $results,
        'total' => intval($total),
        'page' => $page,
        'per_page' => $per_page,
        'total_pages' => ceil($total / $per_page)
    ]);
});

add_action('wp_ajax_cls_update_translation', function() {
    global $wpdb;
    $id = intval($_POST['id']);
    $translated = sanitize_text_field($_POST['translated_text']);
    $table = $wpdb->prefix . 'custom_translations';
    $result = $wpdb->update($table, ['translated_text' => $translated, 'updated_at' => current_time('mysql')], ['id' => $id]);

    if ($result !== false) {
        cls_log_action('manual_update', "Updated translation ID $id to '$translated'");
    }

    wp_send_json_success(['updated' => $result]);
});

add_action('wp_ajax_cls_delete_translation', function() {
    global $wpdb;
    $id = intval($_POST['id']);
    $table = $wpdb->prefix . 'custom_translations';
    $result = $wpdb->delete($table, ['id' => $id]);

    if ($result !== false) {
        cls_log_action('manual_delete', "Deleted translation ID $id");
    }

    wp_send_json_success(['deleted' => $result]);
});

add_action('wp_ajax_cls_add_original_text', function() {
    global $wpdb;
    $original_text = sanitize_text_field($_POST['original_text']);
    $table = $wpdb->prefix . 'custom_translations';
    
    // Check if text already exists
    $exists = $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM $table WHERE original_text = %s",
        $original_text
    ));
    
    if ($exists) {
        wp_send_json_error(['message' => 'This text already exists in the database']);
        return;
    }
    
    // Calculate SHA256 hash
    $text_hash = hash('sha256', $original_text);
    
    // Insert new record
    $result = $wpdb->insert($table, [
        'text_hash' => $text_hash,
        'original_text' => $original_text,
        'source_lang' => 'en',
        'target_lang' => null,
        'translated_text' => null,
        'context' => NULL,
        'created_at' => current_time('mysql'),
        'updated_at' => current_time('mysql')
    ]);
    
    if ($result !== false) {
        cls_log_action('manual_add', "Added new original text: '$original_text'");
        wp_send_json_success(['inserted' => $result]);
    } else {
        wp_send_json_error(['message' => 'Database error']);
    }
});
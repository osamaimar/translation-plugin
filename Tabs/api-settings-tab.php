<?php
function cls_render_api_settings_tab() {
    global $wpdb;
    $table = $wpdb->prefix . 'api_integrations';

    // استرجاع السجل الحالي إن وجد
    $current_api = $wpdb->get_row("SELECT * FROM $table WHERE is_active = 1 LIMIT 1");
    $current_api_json = json_encode($current_api);

    ?>
    <div class="wrap">
        <h2>API Settings</h2>

        <form id="api-settings-form">
            <table class="form-table">
                <tr>
                    <th scope="row"><label for="tool">Select API</label></th>
                    <td>
                        <select name="tool" id="tool">
                            <option value="">-- Select API --</option>
                            <option value="openai" <?= $current_api && $current_api->tool === 'openai' ? 'selected' : '' ?>>OpenAI API</option>
                            <option value="google" <?= $current_api && $current_api->tool === 'google' ? 'selected' : '' ?>>Google Translate API</option>
                            <option value="deepl" <?= $current_api && $current_api->tool === 'deepl' ? 'selected' : '' ?>>DeepL API</option>
                            <option value="libretranslate" <?= $current_api && $current_api->tool === 'libretranslate' ? 'selected' : '' ?>>LibreTranslate API</option>
                        </select>
                    </td>
                </tr>

                <tbody id="dynamic-fields"></tbody>
            </table>

            <div id="loading-indicator" style="display:none; margin-top:10px;">
              <span>⏳ Please wait...</span>
            </div>

            <p>
                <button type="button" id="test-api" class="button">🔌 Test Connection</button>
                <button type="submit" class="button button-primary">💾 Save API</button>
            </p>

            <div id="api-message" style="margin-top: 15px;"></div>
        </form>
    </div>

    <script>
        const currentApi = <?= $current_api_json ?: 'null' ?>;

        const fieldTemplates = {
            openai: `
                <tr><th><label for="model">Model</label></th><td><input name="model" type="text" id="model" class="regular-text"></td></tr>
                <tr><th><label for="api_key">API Key</label></th><td><input name="api_key" type="text" id="api_key" class="regular-text"></td></tr>
                <tr><th><label for="api_url">API URL</label></th><td><input name="api_url" type="text" id="api_url" class="regular-text"></td></tr>
            `,
            google: `
                <tr><th><label for="api_key">API Key</label></th><td><input name="api_key" type="text" id="api_key" class="regular-text"></td></tr>
                <tr><th><label for="api_url">API URL</label></th><td><input name="api_url" type="text" id="api_url" class="regular-text"></td></tr>
            `,
            deepl: `
                <tr><th><label for="api_key">API Key</label></th><td><input name="api_key" type="text" id="api_key" class="regular-text"></td></tr>
                <tr><th><label for="api_url">API URL</label></th><td><input name="api_url" type="text" id="api_url" class="regular-text"></td></tr>
            `,
            libretranslate: `
                <tr><th><label for="api_url">API URL</label></th><td><input name="api_url" type="text" id="api_url" class="regular-text" placeholder="http://localhost:5001"></td></tr>
                <tr><th><label for="api_key">API Key (Optional)</label></th><td><input name="api_key" type="text" id="api_key" class="regular-text"></td></tr>
            `
        };

        const loading = document.getElementById('loading-indicator');
        const message = document.getElementById('api-message');
        const dynamicFields = document.getElementById('dynamic-fields');

        function fillFields(tool) {
            dynamicFields.innerHTML = fieldTemplates[tool] || '';

            // إذا كان نفس الـ API المخزن، املأ البيانات من قاعدة البيانات
            if (currentApi && currentApi.tool === tool) {
                if (document.getElementById('model') && currentApi.model)
                    document.getElementById('model').value = currentApi.model;
                if (document.getElementById('api_key'))
                    document.getElementById('api_key').value = currentApi.api_key;
                if (document.getElementById('api_url'))
                    document.getElementById('api_url').value = currentApi.api_url;
            } else {
                // ✅ استخدم القيم الافتراضية
                if (tool === 'openai') {
                    document.getElementById('model').value = 'gpt-3.5-turbo';
                    document.getElementById('api_url').value = 'https://api.openai.com/v1/chat/completions';
                } else if (tool === 'google') {
                    document.getElementById('api_url').value = 'https://translation.googleapis.com/language/translate/v2';
                } else if (tool === 'deepl') {
                    document.getElementById('api_url').value = 'https://api.deepl.com/v2/translate';
                } else if (tool === 'libretranslate') {
                    document.getElementById('api_url').value = 'http://localhost:5000';
                }
            }
        }

        document.getElementById('tool').addEventListener('change', function () {
            message.innerHTML = '';
            fillFields(this.value);
        });

        // إذا تم تحميل الصفحة وكان هناك API محدد
        document.addEventListener('DOMContentLoaded', () => {
            const selected = document.getElementById('tool').value;
            if (selected) {
                fillFields(selected);
            }
        });

        document.getElementById('test-api').addEventListener('click', async function () {
            loading.style.display = 'block';
            message.innerHTML = '';
            const data = Object.fromEntries(new FormData(document.getElementById('api-settings-form')));
            const response = await fetch(ajaxurl, {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: new URLSearchParams({ ...data, action: 'test_api_connection' })
            });
            const result = await response.text();
            message.innerHTML = result;
            loading.style.display = 'none';
        });

        document.getElementById('api-settings-form').addEventListener('submit', async function (e) {
            e.preventDefault();
            loading.style.display = 'block';
            message.innerHTML = '';
            const data = Object.fromEntries(new FormData(this));
            const response = await fetch(ajaxurl, {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: new URLSearchParams({ ...data, action: 'save_api_settings' })
            });
            const result = await response.text();
            message.innerHTML = result;
            loading.style.display = 'none';
        });
    </script>
    <?php
}
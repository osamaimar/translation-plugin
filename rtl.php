<?php
add_action('wp_enqueue_scripts', 'cls_enqueue_rtl_styles_if_needed');
function cls_enqueue_rtl_styles_if_needed() {
    global $wpdb;

    $site_lang = isset($_COOKIE['site_lang']) ? $_COOKIE['site_lang'] : 'en';

    // تحقق من اتجاه اللغة من قاعدة البيانات
    $lang_row = $wpdb->get_row($wpdb->prepare(
        "SELECT direction FROM {$wpdb->prefix}custom_languages WHERE code = %s LIMIT 1",
        $site_lang
    ));

    if ($lang_row && $lang_row->direction === 'rtl') {
        wp_enqueue_style('cls-rtl-style', plugin_dir_url(__FILE__) . 'css/rtl-style.css', array(), null);
    }
}

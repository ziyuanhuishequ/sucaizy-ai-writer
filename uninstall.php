<?php

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

$saw_options = array(
    'saw_api_key',
    'saw_api_base',
    'saw_model',
    'saw_api_format',
    'saw_web_search',
    'saw_temperature',
    'saw_title_count',
    'saw_feature_min',
    'saw_feature_max',
    'saw_cache_minutes',
    'saw_reference_search',
    'saw_request_timeout',
    'saw_disable_thinking',
    'saw_frontend_slug',
    'saw_title_selector',
    'saw_content_selector',
    'saw_api_tested_signature',
    'saw_writing_skill',
    'saw_plugin_version',
);
foreach ($saw_options as $saw_option) {
    delete_option($saw_option);
}

// 清掉资料缓存 transient，避免卸载后在 options 表里留垃圾。
global $wpdb;
if (isset($wpdb) && is_object($wpdb)) {
    $saw_like = $wpdb->esc_like('_transient_saw_src_') . '%';
    $saw_like_timeout = $wpdb->esc_like('_transient_timeout_saw_src_') . '%';
    $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s", $saw_like, $saw_like_timeout));
}

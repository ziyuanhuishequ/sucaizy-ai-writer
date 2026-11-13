<?php
/**
 * Plugin Name: 素材资源网 AI 文章写作
 * Plugin URI: https://www.sucaizy.com/
 * Description: 输入项目链接、软件名称或素材名称，按素材资源网写作规范自动生成中文文章并保存为草稿；识别到 GitHub 地址时可把 Release 安装包分片下载到指定目录。并发抓取资料与网上已有的同类介绍，标题 43-67 字，简介写成真人推荐口吻，标题与特点字数自动校验，并显示每次生成的 token 用量与估算费用。
 * Version: 1.8.0
 * Author: Sucaizy
 * Author URI: https://www.sucaizy.com/
 * Requires at least: 6.4
 * Requires PHP: 7.2
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: sucaizy-ai-writer
 */

if (!defined('ABSPATH')) {
    exit;
}

/*
 * Copyright (C) 2026 Sucaizy
 *
 * This program is free software; you can redistribute it and/or modify it
 * under the terms of the GNU General Public License as published by the Free
 * Software Foundation; either version 2 of the License, or (at your option)
 * any later version.
 *
 * This program is distributed in the hope that it will be useful, but WITHOUT
 * ANY WARRANTY; without even the implied warranty of MERCHANTABILITY or FITNESS
 * FOR A PARTICULAR PURPOSE. See the GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License along with
 * this program; if not, see <https://www.gnu.org/licenses/gpl-2.0.html>.
 */

/*
 * 重复安装保护。
 *
 * 上传插件时如果生成了嵌套目录（wp-content/plugins/sucaizy-ai-writer/sucaizy-ai-writer/），
 * WordPress 会把里面那份也识别成一个插件；两份同时启用就会重复声明函数和类，
 * 触发 “Cannot redeclare / Cannot declare class” 致命错误，导致整站打不开。
 *
 * 注意：顶层无条件的函数声明会在文件编译期就注册，写在它后面的 return 拦不住，
 * 所以本文件的函数声明全部包在 if 里，require 也加了 class_exists 判断。
 */
if (defined('SAW_VERSION')) {
    if (function_exists('add_action')) {
        if (!function_exists('saw_duplicate_install_notice')) {
            function saw_duplicate_install_notice() {
                if (function_exists('current_user_can') && !current_user_can('manage_options')) {
                    return;
                }
                echo '<div class="notice notice-error"><p><strong>素材资源网 AI 文章写作：检测到重复安装。</strong>'
                    . '插件目录被复制成了两份（常见于上传时生成嵌套目录），本次只加载了其中一份。'
                    . '请到 wp-content/plugins/ 检查并删除多余的目录，只保留一个 sucaizy-ai-writer，'
                    . '然后重新启用插件。</p></div>';
            }
        }
        add_action('admin_notices', 'saw_duplicate_install_notice');
    }
    return;
}

define('SAW_VERSION', '1.8.0');
define('SAW_FILE', __FILE__);
define('SAW_DIR', plugin_dir_path(__FILE__));
define('SAW_URL', plugin_dir_url(__FILE__));
define('SAW_OPTION_VERSION', 'saw_plugin_version');

/*
 * 载入顺序：类文件互相依赖，http -> quality -> generator -> admin -> frontend。
 * 每一处都先判断文件存在再 require：上传不完整（FTP 中断、宝塔解压失败）时
 * 缺一个文件就 require_once 会直接致命错误，让整站打不开；改成跳过并记一条
 * 后台提示，至少站点能正常访问。
 */
$saw_requires = array(
    'includes/class-saw-http.php' => 'SAW_HTTP',
    'includes/class-saw-quality.php' => 'SAW_Quality',
    'includes/class-saw-github.php' => 'SAW_GitHub',
    'includes/class-saw-github-ui.php' => 'SAW_GitHub_UI',
    'includes/class-saw-generator.php' => 'SAW_Generator',
    'includes/class-saw-admin.php' => 'SAW_Admin',
    'includes/class-saw-frontend.php' => 'SAW_Frontend',
);
$saw_missing = array();
foreach ($saw_requires as $saw_file => $saw_class) {
    if (class_exists($saw_class, false)) {
        continue;
    }
    $saw_path = SAW_DIR . $saw_file;
    if (!is_readable($saw_path)) {
        $saw_missing[] = $saw_file;
        continue;
    }
    require_once $saw_path;
}
unset($saw_requires, $saw_file, $saw_class, $saw_path);

if (!empty($saw_missing)) {
    define('SAW_MISSING_FILES', implode('、', $saw_missing));
    if (!function_exists('saw_missing_files_notice')) {
        function saw_missing_files_notice() {
            if (function_exists('current_user_can') && !current_user_can('manage_options')) {
                return;
            }
            $list = defined('SAW_MISSING_FILES') ? SAW_MISSING_FILES : '';
            $list = function_exists('esc_html') ? esc_html($list) : $list;
            echo '<div class="notice notice-error"><p><strong>素材资源网 AI 文章写作：安装文件不完整。</strong>'
                . '缺少以下文件，插件已暂停工作：' . $list
                . '。请重新上传完整安装包（建议用后台“上传插件”，不要用 FTP 分批覆盖）。</p></div>';
        }
    }
    add_action('admin_notices', 'saw_missing_files_notice');
    return;
}

if (!function_exists('saw_boot_plugin')) {
    function saw_boot_plugin() {
        if (!class_exists('SAW_Generator') || !class_exists('SAW_Admin') || !class_exists('SAW_Frontend')) {
            return;
        }
        SAW_Generator::maybe_upgrade();

        $generator = new SAW_Generator();
        new SAW_Admin($generator);
        new SAW_Frontend($generator);
    }
}
add_action('plugins_loaded', 'saw_boot_plugin');

/**
 * 激活时补齐默认值，避免升级后首次生成才发现缺设置。
 */
if (!function_exists('saw_activate_plugin')) {
    function saw_activate_plugin() {
        $defaults = array(
            'saw_title_count' => 5,
            'saw_feature_min' => 35,
            'saw_feature_max' => 45,
            'saw_cache_minutes' => 180,
            'saw_reference_search' => 1,
            'saw_request_timeout' => 75,
            'saw_disable_thinking' => 1,
            'saw_gh_parts' => 3,
            'saw_gh_max_mb' => 512,
            'saw_gh_timeout' => 45,
        );
        foreach ($defaults as $name => $value) {
            if (get_option($name, null) === null) {
                update_option($name, $value, false);
            }
        }
        if (class_exists('SAW_Generator')) {
            SAW_Generator::maybe_upgrade();
        }
        update_option(SAW_OPTION_VERSION, SAW_VERSION, false);
    }
}
register_activation_hook(__FILE__, 'saw_activate_plugin');

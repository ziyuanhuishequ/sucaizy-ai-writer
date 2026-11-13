<?php

if (!defined('ABSPATH')) {
    exit;
}

if (!class_exists('SAW_Admin', false)) {
    class SAW_Admin {
        private $generator;
        private $github;

        public function __construct($generator) {
            $this->generator = $generator;
            $this->github = class_exists('SAW_GitHub') ? new SAW_GitHub() : null;
            add_action('admin_menu', array($this, 'add_menu'));
            add_action('admin_init', array($this, 'register_settings'));
            add_action('admin_enqueue_scripts', array($this, 'enqueue_assets'));
            add_action('wp_ajax_saw_generate_article', array($this, 'ajax_generate'));
            add_action('wp_ajax_saw_regenerate_titles', array($this, 'ajax_regenerate_titles'));
            add_action('wp_ajax_saw_save_draft', array($this, 'ajax_save_draft'));
            add_action('wp_ajax_saw_test_api', array($this, 'ajax_test_api'));
            add_action('wp_ajax_saw_clear_cache', array($this, 'ajax_clear_cache'));
            // GitHub Release 下载：扫描 → 准备 → 分片 → 合并，四步都是独立请求，
            // 避免单个请求超时把大文件卡死。
            add_action('wp_ajax_saw_gh_scan', array($this, 'ajax_gh_scan'));
            add_action('wp_ajax_saw_gh_prepare', array($this, 'ajax_gh_prepare'));
            add_action('wp_ajax_saw_gh_part', array($this, 'ajax_gh_part'));
            add_action('wp_ajax_saw_gh_finish', array($this, 'ajax_gh_finish'));
            add_action('wp_ajax_saw_gh_status', array($this, 'ajax_gh_status'));
            add_action('wp_ajax_saw_gh_abort', array($this, 'ajax_gh_abort'));
            add_action('wp_ajax_saw_gh_files', array($this, 'ajax_gh_files'));
            // 把服务器上的文件读出来吐给浏览器：下载根目录在站外（没有公网链接）时，
            // 靠这个入口把文件取回本机。
            add_action('wp_ajax_saw_gh_file', array($this, 'ajax_gh_file'));
        }

        public function add_menu() {
            add_menu_page(
                '素材 AI 写作',
                '素材 AI 写作',
                'manage_options',
                'sucaizy-ai-writer',
                array($this, 'render_generator'),
                'dashicons-edit-page',
                25
            );
            add_submenu_page(
                'sucaizy-ai-writer',
                '接口设置',
                '接口设置',
                'manage_options',
                'sucaizy-ai-writer-settings',
                array($this, 'render_settings')
            );
            add_submenu_page(
                'sucaizy-ai-writer',
                '写作 Skill',
                '写作 Skill',
                'manage_options',
                'sucaizy-ai-writer-skill',
                array($this, 'render_skill_settings')
            );
        }

        public function register_settings() {
            register_setting('saw_settings', 'saw_api_key', array('sanitize_callback' => 'sanitize_text_field'));
            register_setting('saw_settings', 'saw_api_base', array('sanitize_callback' => 'esc_url_raw', 'default' => 'https://api.openai.com/v1'));
            register_setting('saw_settings', 'saw_model', array('sanitize_callback' => 'sanitize_text_field', 'default' => 'gpt-4.1-mini'));
            register_setting('saw_settings', 'saw_api_format', array('sanitize_callback' => array($this, 'sanitize_api_format'), 'default' => 'responses'));
            register_setting('saw_settings', 'saw_web_search', array('sanitize_callback' => array($this, 'sanitize_checkbox'), 'default' => 0));
            register_setting('saw_settings', 'saw_temperature', array('sanitize_callback' => array($this, 'sanitize_temperature'), 'default' => 0.5));
            register_setting('saw_settings', 'saw_title_count', array('sanitize_callback' => array($this, 'sanitize_title_count'), 'default' => 5));
            register_setting('saw_settings', 'saw_feature_min', array('sanitize_callback' => array($this, 'sanitize_feature_min'), 'default' => 35));
            register_setting('saw_settings', 'saw_feature_max', array('sanitize_callback' => array($this, 'sanitize_feature_max'), 'default' => 45));
            register_setting('saw_settings', 'saw_cache_minutes', array('sanitize_callback' => array($this, 'sanitize_cache_minutes'), 'default' => 180));
            register_setting('saw_settings', 'saw_reference_search', array('sanitize_callback' => array($this, 'sanitize_checkbox'), 'default' => 1));
            register_setting('saw_settings', 'saw_request_timeout', array('sanitize_callback' => array($this, 'sanitize_request_timeout'), 'default' => 75));
            register_setting('saw_settings', 'saw_disable_thinking', array('sanitize_callback' => array($this, 'sanitize_checkbox'), 'default' => 1));
            register_setting('saw_settings', 'saw_frontend_slug', array('sanitize_callback' => 'sanitize_title', 'default' => 'newposts-2'));
            register_setting('saw_settings', 'saw_title_selector', array('sanitize_callback' => array($this, 'sanitize_selector'), 'default' => ''));
            register_setting('saw_settings', 'saw_content_selector', array('sanitize_callback' => array($this, 'sanitize_selector'), 'default' => ''));
            register_setting('saw_settings', 'saw_gh_root', array('sanitize_callback' => array($this, 'sanitize_gh_root'), 'default' => ''));
            register_setting('saw_settings', 'saw_github_token', array('sanitize_callback' => array($this, 'sanitize_github_token'), 'default' => ''));
            register_setting('saw_settings', 'saw_github_routes', array('sanitize_callback' => array($this, 'sanitize_gh_routes'), 'default' => ''));
            register_setting('saw_settings', 'saw_gh_parts', array('sanitize_callback' => array($this, 'sanitize_gh_parts'), 'default' => 3));
            register_setting('saw_settings', 'saw_gh_max_mb', array('sanitize_callback' => array($this, 'sanitize_gh_max_mb'), 'default' => 512));
            register_setting('saw_settings', 'saw_gh_timeout', array('sanitize_callback' => array($this, 'sanitize_gh_timeout'), 'default' => 45));
            register_setting('saw_skill_settings', 'saw_writing_skill', array('sanitize_callback' => array($this, 'sanitize_writing_skill'), 'default' => ''));
        }

        public function sanitize_api_format($value) {
            return $value === 'chat' ? 'chat' : 'responses';
        }

        public function sanitize_checkbox($value) {
            return $value ? 1 : 0;
        }

        public function sanitize_temperature($value) {
            return min(1, max(0, (float) $value));
        }

        public function sanitize_title_count($value) {
            return max(3, min(10, (int) $value));
        }

        public function sanitize_feature_min($value) {
            return max(20, min(60, (int) $value));
        }

        public function sanitize_feature_max($value) {
            $min = max(20, min(60, (int) get_option('saw_feature_min', 35)));
            return max($min + 3, min(70, (int) $value));
        }

        public function sanitize_cache_minutes($value) {
            return max(0, min(1440, (int) $value));
        }

        public function sanitize_request_timeout($value) {
            return max(30, min(180, (int) $value));
        }

        public function sanitize_selector($value) {
            return preg_replace('/[\r\n\x00-\x1F\x7F]/', '', sanitize_text_field($value));
        }

        public function sanitize_writing_skill($value) {
            $value = str_replace(array("\r\n", "\r"), "\n", (string) wp_unslash($value));
            $value = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $value);
            $value = trim($value);
            if (function_exists('mb_substr')) {
                return mb_substr($value, 0, 30000, 'UTF-8');
            }
            return substr($value, 0, 60000);
        }

        /**
         * 下载根目录。
         *
         * 允许留空（用 wp-content/uploads/saw-github），允许绝对路径（/www/wwwroot/dl），
         * 也允许相对 WordPress 根目录的写法（downloads）。这里只做格式净化，
         * 真正能不能写要在保存页上提示，不能因为不可写就静默丢弃用户填的值。
         */
        public function sanitize_gh_root($value) {
            $value = str_replace('\\', '/', trim((string) wp_unslash($value)));
            $value = preg_replace('/[\x00-\x1F\x7F]/', '', $value);
            if (!is_string($value)) {
                return '';
            }
            $value = preg_replace('#/+#', '/', $value);
            if (!is_string($value) || $value === '') {
                return '';
            }

            $prefix = '';
            $rest = $value;
            if (preg_match('#^([A-Za-z]):/?#', $value, $matches)) {
                // Windows 盘符路径：保留 D:/ 前缀。
                $prefix = strtoupper($matches[1]) . ':/';
                $rest = ltrim(substr($value, strlen($matches[0])), '/');
            } elseif (strpos($value, '/') === 0) {
                $prefix = '/';
                $rest = ltrim($value, '/');
            }

            // 逐段去掉空段、. 和 ..，不允许跳出给定位置。
            $segments = array();
            foreach (explode('/', $rest) as $segment) {
                if ($segment === '' || $segment === '.') {
                    continue;
                }
                if ($segment === '..') {
                    continue;
                }
                $segments[] = $segment;
            }
            if (empty($segments)) {
                return $prefix === '/' ? '' : rtrim($prefix, '/');
            }
            return rtrim($prefix . implode('/', $segments), '/');
        }

        public function sanitize_github_token($value) {
            $value = trim((string) wp_unslash($value));
            return preg_replace('/[^A-Za-z0-9_\-.]/', '', $value);
        }

        /**
         * 下载线路。一行一条，支持 https://镜像/{url} 的写法；
         * 明文 http 一律丢弃（镜像能篡改内容，不能留这个口子）。
         */
        public function sanitize_gh_routes($value) {
            $value = str_replace(array("\r\n", "\r"), "\n", (string) wp_unslash($value));
            $lines = explode("\n", $value);
            $routes = array();
            foreach ($lines as $line) {
                $line = trim($line);
                if ($line === '' || strpos($line, '#') === 0) {
                    continue;
                }
                if (stripos($line, 'http://') === 0) {
                    continue;
                }
                if (!preg_match('#^https://#i', $line) && strpos($line, '{url}') !== false) {
                    $line = 'https://' . $line;
                }
                $routes[] = preg_replace('/[\x00-\x1F\x7F\s]/', '', $line);
                if (count($routes) >= 12) {
                    break;
                }
            }
            return implode("\n", array_values(array_unique($routes)));
        }

        public function sanitize_gh_parts($value) {
            return max(1, min(8, (int) $value));
        }

        public function sanitize_gh_max_mb($value) {
            return max(1, min(4096, (int) $value));
        }

        public function sanitize_gh_timeout($value) {
            return max(15, min(180, (int) $value));
        }

        public function enqueue_assets($hook) {
            if (strpos($hook, 'sucaizy-ai-writer') === false) {
                return;
            }
            wp_enqueue_style('saw-admin', SAW_URL . 'assets/admin.css', array(), SAW_VERSION);
            wp_enqueue_style('saw-github', SAW_URL . 'assets/github.css', array(), SAW_VERSION);
            wp_enqueue_script('saw-admin', SAW_URL . 'assets/admin.js', array('jquery'), SAW_VERSION, true);
            wp_enqueue_script('saw-github', SAW_URL . 'assets/github-download.js', array('jquery'), SAW_VERSION, true);
            wp_localize_script('saw-github', 'sawGh', array(
                'ajaxUrl' => admin_url('admin-ajax.php'),
                'nonce' => wp_create_nonce('saw_admin_nonce'),
                'gh' => SAW_GitHub_UI::config(),
            ));
            wp_localize_script('saw-admin', 'sawAdmin', array(
                'ajaxUrl' => admin_url('admin-ajax.php'),
                'nonce' => wp_create_nonce('saw_admin_nonce'),
                'apiReady' => $this->generator->is_configuration_tested(),
                'defaultWritingSkill' => SAW_Generator::default_writing_skill(),
                'titleCount' => max(3, min(10, (int) get_option('saw_title_count', 5))),
                'featureBand' => array(
                    'min' => max(20, min(60, (int) get_option('saw_feature_min', 35))),
                    'max' => max(24, min(70, (int) get_option('saw_feature_max', 45))),
                ),
            ));
        }

        public function render_generator() {
            if (!current_user_can('manage_options')) {
                return;
            }
            $categories = get_categories(array('hide_empty' => false));
            $feature_min = max(20, min(60, (int) get_option('saw_feature_min', 35)));
            $feature_max = max(24, min(70, (int) get_option('saw_feature_max', 45)));
            ?>
            <div class="wrap saw-wrap">
                <h1>素材资源网 AI 文章写作</h1>
                <p class="description">输入一个公开链接、GitHub 项目地址、软件名称或素材名称。生成结果会先显示在这里，由你检查后保存为草稿。</p>

                <?php if (!get_option('saw_api_key')) : ?>
                    <div class="notice notice-warning inline"><p>尚未配置 API Key。请先打开 <a href="<?php echo esc_url(admin_url('admin.php?page=sucaizy-ai-writer-settings')); ?>">接口设置</a>。</p></div>
                <?php elseif (!$this->generator->is_configuration_tested()) : ?>
                    <div class="notice notice-warning inline"><p>当前接口配置尚未测试通过。请先打开 <a href="<?php echo esc_url(admin_url('admin.php?page=sucaizy-ai-writer-settings')); ?>">接口设置</a>完成测试。</p></div>
                <?php endif; ?>

                <div class="saw-panel">
                    <div class="saw-field">
                        <label for="saw-input">项目链接或资源名称</label>
                        <textarea id="saw-input" rows="4" placeholder="例如：https://github.com/TNTcraftHIM/Piik 或 400首治愈系白噪音素材包"></textarea>
                    </div>
                    <div class="saw-grid">
                        <div class="saw-field">
                            <label for="saw-type">内容类型</label>
                            <select id="saw-type">
                                <option value="auto">自动判断</option>
                                <option value="software">软件 / GitHub 项目</option>
                                <option value="website">网站 / 在线服务</option>
                                <option value="material">数字素材</option>
                            </select>
                        </div>
                        <div class="saw-field">
                            <label for="saw-category">保存到分类</label>
                            <select id="saw-category">
                                <option value="0">未分类</option>
                                <?php foreach ($categories as $category) : ?>
                                    <option value="<?php echo intval($category->term_id); ?>"><?php echo esc_html($category->name); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <div class="saw-field">
                        <label for="saw-notes">补充说明（可选）</label>
                        <textarea id="saw-notes" rows="3" placeholder="例如：重点介绍离线功能；素材包含 400 个 WAV 文件；授权范围待核实"></textarea>
                    </div>
                    <div class="saw-actions">
                        <button type="button" class="button button-primary" id="saw-generate">生成文章</button>
                        <?php echo SAW_GitHub_UI::button('admin'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                        <span class="spinner" id="saw-spinner"></span>
                        <span class="saw-timer" id="saw-timer" hidden></span>
                    </div>
                    <?php echo SAW_GitHub_UI::hint('admin'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                    <p class="description">按当前设置生成 <?php echo intval(get_option('saw_title_count', 5)); ?> 个备选标题，软件特点每条控制在 <?php echo intval($feature_min); ?>-<?php echo intval($feature_max); ?> 字。</p>
                    <p id="saw-message" role="status"></p>
                </div>

                <div class="saw-panel" id="saw-result" hidden>
                    <h2>生成结果</h2>
                    <p class="saw-stats" id="saw-stats" hidden></p>
                    <div class="saw-notices" id="saw-warnings" hidden></div>
                    <div class="saw-field">
                        <label for="saw-title-options">备选标题</label>
                        <div class="saw-inline">
                            <select id="saw-title-options"></select>
                            <button type="button" class="button" id="saw-title-refresh">换一批标题</button>
                            <span class="spinner" id="saw-title-spinner"></span>
                        </div>
                        <p class="description" id="saw-title-hint"></p>
                    </div>
                    <div class="saw-field">
                        <label for="saw-title">文章标题</label>
                        <input type="text" id="saw-title" class="regular-text">
                    </div>
                    <div class="saw-field">
                        <label for="saw-content">文章正文</label>
                        <div id="saw-content" class="saw-rich-editor" contenteditable="true" role="textbox" aria-multiline="true"></div>
                    </div>
                    <details class="saw-preview-wrap">
                        <summary>预览排版</summary>
                        <iframe id="saw-preview" title="文章预览" sandbox=""></iframe>
                    </details>
                    <div class="saw-actions">
                        <button type="button" class="button button-primary" id="saw-save">保存为草稿</button>
                        <span class="spinner" id="saw-save-spinner"></span>
                    </div>
                    <p id="saw-save-message" role="status"></p>
                </div>

                <?php /* 下载弹窗：默认隐藏，输入框识别到仓库地址后由 github-download.js 打开 */ ?>
                <?php echo SAW_GitHub_UI::modal(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
            </div>
            <?php
        }

        /**
         * 运行环境自检。
         *
         * 升级 PHP 之后最常见的故障是扩展没跟着装（尤其是 mbstring、curl），
         * 或者执行时间被限制。直接在设置页列出来，比“点了没反应”好排查得多。
         */
        public function environment_report() {
            $rows = array();

            $php_ok = version_compare(PHP_VERSION, '7.2', '>=');
            $rows[] = array(
                'label' => 'PHP 版本',
                'value' => PHP_VERSION,
                'ok' => $php_ok,
                'hint' => $php_ok ? '满足插件要求（7.2 及以上）' : '插件要求 PHP 7.2 及以上',
            );

            $checks = array(
                'json' => array('JSON 解析', true, '缺少时无法读取 AI 返回内容'),
                'openssl' => array('HTTPS 请求', true, '缺少时无法访问 https 接口'),
                'mbstring' => array('中文按字符处理', false, '缺少时改用 iconv 或内置算法，功能不受影响'),
                'curl' => array('并发抓取资料', false, '缺少时退回逐条抓取，速度慢一些'),
                'dom' => array('解析网页 HTML', false, '缺少时只能读取纯文本'),
            );
            foreach ($checks as $extension => $meta) {
                $enabled = extension_loaded($extension);
                $rows[] = array(
                    'label' => $meta[0] . '（' . $extension . '）',
                    'value' => $enabled ? '已启用' : '未启用',
                    'ok' => $enabled || !$meta[1],
                    'hint' => $enabled ? '正常' : $meta[2],
                );
            }

            $max_execution = (int) ini_get('max_execution_time');
            $timeout = max(30, min(180, (int) get_option('saw_request_timeout', 75)));
            // 0 表示不限制；其余情况生成请求至少要比超时多留 20 秒余量。
            $time_ok = $max_execution === 0 || $max_execution >= $timeout + 20;
            $rows[] = array(
                'label' => '脚本超时（max_execution_time）',
                'value' => $max_execution === 0 ? '不限制' : $max_execution . ' 秒',
                'ok' => $time_ok,
                'hint' => $time_ok ? '够用' : '小于“请求超时 + 20 秒”，抓取较慢的站点可能被中断，建议调到 120 秒以上',
            );

            return $rows;
        }

        public function default_gh_root() {
            return str_replace('\\', '/', WP_CONTENT_DIR) . '/uploads/saw-github';
        }

        /**
         * 下载目录的可用状态。目录不存在不算错（第一次下载会自动建），
         * 但明确写出来，免得用户点下载才发现没权限。
         */
        public function gh_root_status() {
            $root = $this->github ? $this->github->root_dir() : $this->default_gh_root();
            if (!is_dir($root)) {
                return '<span class="saw-error">目录还不存在</span>：第一次下载时会自动创建。如果站点目录不可写，请先手动建好 <code>' . esc_html($root) . '</code> 并给写权限。';
            }
            if (!is_writable($root)) {
                return '<span class="saw-error">目录不可写</span>：<code>' . esc_html($root) . '</code>。请把属主改成运行 PHP 的用户，或给 755 以上权限。';
            }
            $text = '<span class="saw-success">目录可写</span>：<code>' . esc_html($root) . '</code>';
            $free = @disk_free_space($root);
            if ($free) {
                $free_text = class_exists('SAW_GitHub')
                    ? SAW_GitHub::human_size($free)
                    : (function_exists('size_format') ? size_format($free) : round($free / 1048576) . ' MB');
                $text .= '，剩余 ' . esc_html($free_text);
            }
            return $text;
        }

        public function render_settings() {
            if (!current_user_can('manage_options')) {
                return;
            }
            ?>
            <div class="wrap saw-wrap">
                <h1>素材 AI 写作接口设置</h1>
                <form method="post" action="options.php" class="saw-panel saw-settings">
                    <?php settings_fields('saw_settings'); ?>
                    <h2>接口</h2>
                    <table class="form-table" role="presentation">
                        <tr>
                            <th scope="row"><label for="saw_api_key">API Key</label></th>
                            <td><input type="password" id="saw_api_key" name="saw_api_key" value="<?php echo esc_attr(get_option('saw_api_key', '')); ?>" class="regular-text" autocomplete="new-password"><p class="description">仅管理员可查看设置页面。密钥保存在 WordPress 数据库中。</p></td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="saw_api_base">API Base</label></th>
                            <td><input type="url" id="saw_api_base" name="saw_api_base" value="<?php echo esc_attr(get_option('saw_api_base', 'https://api.openai.com/v1')); ?>" class="regular-text" placeholder="https://api.openai.com/v1"></td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="saw_model">模型</label></th>
                            <td><input type="text" id="saw_model" name="saw_model" value="<?php echo esc_attr(get_option('saw_model', 'gpt-4.1-mini')); ?>" class="regular-text" placeholder="gpt-4.1-mini"><p class="description">建议使用支持 JSON 输出的模型，结构化结果更稳定、速度也更快。</p></td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="saw_api_format">接口格式</label></th>
                            <td>
                                <select id="saw_api_format" name="saw_api_format">
                                    <option value="responses" <?php selected(get_option('saw_api_format', 'responses'), 'responses'); ?>>OpenAI Responses API</option>
                                    <option value="chat" <?php selected(get_option('saw_api_format', 'responses'), 'chat'); ?>>OpenAI 兼容 Chat Completions</option>
                                </select>
                                <p class="description">使用 api.sudocode.chat 等兼容接口时，通常应选择“OpenAI 兼容 Chat Completions”。</p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">联网搜索</th>
                            <td><label><input type="checkbox" name="saw_web_search" value="1" <?php checked(get_option('saw_web_search', 0), 1); ?>> 使用 Responses API 的 web_search_preview 工具</label><p class="description">只有支持该工具的模型和接口才能开启。开启后返回更慢，一般建议关闭。</p></td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="saw_temperature">创作温度</label></th>
                            <td><input type="number" id="saw_temperature" name="saw_temperature" min="0" max="1" step="0.1" value="<?php echo esc_attr(get_option('saw_temperature', 0.5)); ?>"><p class="description">0.4-0.6 之间语句更稳；过高容易编造细节。</p></td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="saw_request_timeout">请求超时（秒）</label></th>
                            <td><input type="number" id="saw_request_timeout" name="saw_request_timeout" min="30" max="180" step="5" value="<?php echo esc_attr(get_option('saw_request_timeout', 75)); ?>"><p class="description">AI 生成请求的最长等待时间。网络较慢时调高。</p></td>
                        </tr>
                        <tr>
                            <th scope="row">思考模式</th>
                            <td>
                                <label><input type="checkbox" name="saw_disable_thinking" value="1" <?php checked(get_option('saw_disable_thinking', 1), 1); ?>> 对 DeepSeek 接口关闭思考模式</label>
                                <p class="description">
                                    建议保持勾选。DeepSeek 默认开启思考模式，思维链按输出价计费，写结构化 JSON 这类任务用不上，关掉可省一半以上费用，并让“创作温度”真正生效。<br>
                                    只有 API Base 或模型名含 <code>deepseek</code> 时才会发送该参数，其他厂商接口不受影响；若接口仍不支持，会自动降级重试。
                                </p>
                            </td>
                        </tr>
                    </table>

                    <h2>生成质量</h2>
                    <table class="form-table" role="presentation">
                        <tr>
                            <th scope="row"><label for="saw_title_count">备选标题数量</label></th>
                            <td><input type="number" id="saw_title_count" name="saw_title_count" min="3" max="10" step="1" value="<?php echo esc_attr(get_option('saw_title_count', 5)); ?>"><p class="description">建议 5 个。程序会过滤超长、空洞、不含资源名的标题。</p></td>
                        </tr>
                        <tr>
                            <th scope="row">软件特点字数</th>
                            <td>
                                <input type="number" name="saw_feature_min" min="20" max="60" step="1" value="<?php echo esc_attr(get_option('saw_feature_min', 35)); ?>" class="small-text"> 至
                                <input type="number" name="saw_feature_max" min="24" max="70" step="1" value="<?php echo esc_attr(get_option('saw_feature_max', 45)); ?>" class="small-text"> 字
                                <p class="description">每条“标签：说明”合计字数。超出上限会自动在标点处收短，并在生成结果里提示。</p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">参考检索</th>
                            <td><label><input type="checkbox" name="saw_reference_search" value="1" <?php checked(get_option('saw_reference_search', 1), 1); ?>> 抓取 B 站视频和网页搜索里已有的同类介绍，参考其标题角度和文案说法</label><p class="description">只用于学习选题角度和读者关心的说法，不会照抄原文。关闭可进一步提速。</p></td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="saw_cache_minutes">资料缓存（分钟）</label></th>
                            <td><input type="number" id="saw_cache_minutes" name="saw_cache_minutes" min="0" max="1440" step="10" value="<?php echo esc_attr(get_option('saw_cache_minutes', 180)); ?>"><p class="description">同一链接在缓存期内再次生成会直接复用资料，速度最快；填 0 表示每次都重新抓取。</p></td>
                        </tr>
                    </table>

                    <h2>前端投稿页</h2>
                    <table class="form-table" role="presentation">
                        <tr>
                            <th scope="row"><label for="saw_frontend_slug">前端投稿页别名</label></th>
                            <td><input type="text" id="saw_frontend_slug" name="saw_frontend_slug" value="<?php echo esc_attr(get_option('saw_frontend_slug', 'newposts-2')); ?>" class="regular-text"><p class="description">默认在该页面自动显示生成器。当前设置对应 /<?php echo esc_html(get_option('saw_frontend_slug', 'newposts-2')); ?>。</p></td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="saw_title_selector">标题框选择器</label></th>
                            <td><input type="text" id="saw_title_selector" name="saw_title_selector" value="<?php echo esc_attr(get_option('saw_title_selector', '')); ?>" class="regular-text" placeholder="例如：#post_title"><p class="description">通常留空即可自动识别；识别失败时填写投稿表单标题框的 CSS 选择器。</p></td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="saw_content_selector">正文编辑器选择器</label></th>
                            <td><input type="text" id="saw_content_selector" name="saw_content_selector" value="<?php echo esc_attr(get_option('saw_content_selector', '')); ?>" class="regular-text" placeholder="例如：#post_content"><p class="description">支持 textarea、TinyMCE 和 contenteditable 编辑器。</p></td>
                        </tr>
                    </table>

                    <h2>GitHub 下载</h2>
                    <table class="form-table" role="presentation">
                        <tr>
                            <th scope="row"><label for="saw_gh_root">下载根目录</label></th>
                            <td>
                                <input type="text" id="saw_gh_root" name="saw_gh_root" value="<?php echo esc_attr(get_option('saw_gh_root', '')); ?>" class="regular-text code" placeholder="<?php echo esc_attr($this->default_gh_root()); ?>">
                                <p class="description">
                                    留空即用 <code><?php echo esc_html($this->default_gh_root()); ?></code>。<br>
                                    想换地方就填绝对路径，两种都行：
                                    <strong>在网站内容目录里</strong>（如 <code><?php echo esc_html(str_replace('\\', '/', WP_CONTENT_DIR)); ?>/downloads</code>）下载完直接给公网链接，能写进文章；
                                    <strong>在内容目录之外</strong>（如 <code>/www/wwwroot/downloads</code>）只显示服务器路径，不对外暴露，需要时用「下载到本机」取回。
                                </p>
                                <?php $root_status = $this->gh_root_status(); ?>
                                <p class="description"><?php echo wp_kses_post($root_status); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="saw_github_token">GitHub Token</label></th>
                            <td>
                                <input type="password" id="saw_github_token" name="saw_github_token" value="<?php echo esc_attr(get_option('saw_github_token', '')); ?>" class="regular-text" autocomplete="new-password">
                                <p class="description">
                                    可选。不填也能用，但 GitHub 接口匿名额度只有每小时 60 次，扫描几个仓库就会用完。
                                    填一个只勾选 <code>public_repo</code>（或 Fine-grained 的 Public repositories 只读）的 Token 即可提到每小时 5000 次。
                                    不需要任何写权限，插件只用它读 Release 列表。
                                </p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="saw_github_routes">下载线路</label></th>
                            <td>
                                <textarea id="saw_github_routes" name="saw_github_routes" rows="6" class="large-text code" placeholder="留空使用内置线路"><?php echo esc_textarea(get_option('saw_github_routes', '')); ?></textarea>
                                <p class="description">
                                    一行一条，<code>{url}</code> 会被替换成真实的 GitHub 下载地址，例如 <code>https://ghfast.top/{url}</code>。
                                    留空时依次尝试：直连、ghfast.top、gh-proxy.com、ghproxy.net、gh.llkk.cc。
                                    哪条先返回就用哪条，中途失败会自动换下一条，不需要手动干预。只接受 https。
                                </p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">下载参数</th>
                            <td>
                                <label>分片数 <input type="number" name="saw_gh_parts" min="1" max="8" step="1" value="<?php echo esc_attr(get_option('saw_gh_parts', 3)); ?>" class="small-text"></label>
                                &nbsp;&nbsp;
                                <label>单文件上限 <input type="number" name="saw_gh_max_mb" min="1" max="4096" step="1" value="<?php echo esc_attr(get_option('saw_gh_max_mb', 512)); ?>" class="small-text"> MB</label>
                                &nbsp;&nbsp;
                                <label>单片超时 <input type="number" name="saw_gh_timeout" min="15" max="180" step="5" value="<?php echo esc_attr(get_option('saw_gh_timeout', 45)); ?>" class="small-text"> 秒</label>
                                <p class="description">
                                    分片数越多越快，但要求主机的 PHP 能同时处理多个请求。共享主机建议 2-3，独立服务器可以 4-6。
                                    小于 8 MB 的文件会自动按单连接下载。
                                </p>
                            </td>
                        </tr>
                    </table>

                    <p class="submit saw-api-actions">
                        <button type="button" class="button button-secondary" id="saw-test-api">测试 API 连接</button>
                        <button type="submit" class="button button-primary">保存设置</button>
                        <button type="button" class="button button-link" id="saw-clear-cache">清空资料缓存</button>
                        <span class="spinner" id="saw-test-spinner"></span>
                    </p>
                    <p id="saw-test-message" role="status"></p>
                    <p id="saw-cache-message" role="status"></p>
                </form>

                <div class="saw-panel saw-env-panel">
                    <h2>运行环境自检</h2>
                    <p class="description">升级 PHP 之后如果插件突然用不了，先看这一栏：红色项目就是需要处理的。</p>
                    <table class="widefat striped saw-env-table">
                        <thead>
                            <tr><th style="width:34%">项目</th><th style="width:18%">当前</th><th>说明</th></tr>
                        </thead>
                        <tbody>
                            <?php foreach ($this->environment_report() as $row) : ?>
                                <tr>
                                    <td><strong><?php echo esc_html($row['label']); ?></strong></td>
                                    <td><span class="<?php echo $row['ok'] ? 'saw-success' : 'saw-error'; ?>"><?php echo esc_html($row['value']); ?></span></td>
                                    <td><?php echo esc_html($row['hint']); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <?php
        }

        public function render_skill_settings() {
            if (!current_user_can('manage_options')) {
                return;
            }
            $saved_skill = trim((string) get_option('saw_writing_skill', ''));
            $writing_skill = $saved_skill !== '' ? $saved_skill : SAW_Generator::default_writing_skill();
            ?>
            <div class="wrap saw-wrap">
                <h1>素材资源网写作 Skill</h1>
                <p class="description">这里的规则会直接加入每次生成请求。修改后保存，下一篇文章立即使用新规则，不需要更新插件或重新测试 API。</p>
                <?php if ($saved_skill === '') : ?>
                    <p class="description"><strong>当前使用插件默认规则（已与 CodeX 的“素材资源网文章生成”Skill 对齐）。</strong></p>
                <?php endif; ?>
                <form method="post" action="options.php" class="saw-panel saw-skill-settings">
                    <?php settings_fields('saw_skill_settings'); ?>
                    <div class="saw-field">
                        <label for="saw_writing_skill">文章写作规则</label>
                        <textarea id="saw_writing_skill" name="saw_writing_skill" rows="32" spellcheck="false"><?php echo esc_textarea($writing_skill); ?></textarea>
                        <p class="description">可以修改标题数量和长度、文章语气、简介字数、栏目顺序、特点条数及不同资源类型的模板。最多保存 30000 个字符。留空并保存即恢复内置默认规则。</p>
                    </div>
                    <p class="submit saw-api-actions">
                        <button type="submit" class="button button-primary">保存写作 Skill</button>
                        <button type="button" class="button button-secondary" id="saw-reset-skill">恢复插件默认规则</button>
                    </p>
                    <p class="description">恢复默认规则后仍需点击“保存写作 Skill”才会生效。</p>
                </form>
            </div>
            <?php
        }

        /* ---------------------------------------------------------------------
         * AJAX：所有响应都走 json_ok / json_fail
         *
         * PHP 8 会在运行时抛出不少新的 deprecation / warning，主机开着 display_errors 时
         * 这些文字会被直接打印在 JSON 前面，前端解析失败，表现就是“点生成没反应”。
         * 这里先把意外输出收进缓冲，发送 JSON 前丢弃并写入 error_log。
         * ------------------------------------------------------------------ */

        /** 已加入的输出缓冲层数，避免把 WordPress 自己的缓冲也关掉。 */
        private $json_ob_level = 0;

        private function begin_json() {
            $this->json_ob_level = ob_get_level();
            ob_start();
        }

        private function end_json() {
            while (ob_get_level() > $this->json_ob_level) {
                $stray = ob_get_clean();
                if (is_string($stray) && trim($stray) !== '' && function_exists('error_log')) {
                    error_log('[sucaizy-ai-writer] 已丢弃意外输出：' . substr(trim($stray), 0, 500));
                }
            }
        }

        private function json_ok($data) {
            $this->end_json();
            wp_send_json_success($data);
        }

        private function json_fail($message, $status = 400) {
            $this->end_json();
            wp_send_json_error(array('message' => $message), $status);
        }

        public function ajax_generate() {
            $this->begin_json();
            check_ajax_referer('saw_admin_nonce', 'nonce');
            if (!current_user_can('manage_options')) {
                $this->json_fail('没有生成文章的权限。', 403);
            }

            $input = isset($_POST['input']) ? sanitize_textarea_field(wp_unslash($_POST['input'])) : '';
            $type = isset($_POST['type']) ? sanitize_key($_POST['type']) : 'auto';
            $notes = isset($_POST['notes']) ? sanitize_textarea_field(wp_unslash($_POST['notes'])) : '';
            $result = $this->generator->generate($input, $type, $notes);
            if (is_wp_error($result)) {
                $this->json_fail($result->get_error_message());
            }
            $this->json_ok($result);
        }

        public function ajax_regenerate_titles() {
            $this->begin_json();
            check_ajax_referer('saw_admin_nonce', 'nonce');
            if (!current_user_can('manage_options')) {
                $this->json_fail('没有生成标题的权限。', 403);
            }

            $input = isset($_POST['input']) ? sanitize_textarea_field(wp_unslash($_POST['input'])) : '';
            $type = isset($_POST['type']) ? sanitize_key($_POST['type']) : 'auto';
            $notes = isset($_POST['notes']) ? sanitize_textarea_field(wp_unslash($_POST['notes'])) : '';
            $count = isset($_POST['count']) ? absint($_POST['count']) : 5;
            $result = $this->generator->regenerate_titles($input, $type, $notes, $count);
            if (is_wp_error($result)) {
                $this->json_fail($result->get_error_message());
            }
            $this->json_ok($result);
        }

        public function ajax_clear_cache() {
            $this->begin_json();
            check_ajax_referer('saw_admin_nonce', 'nonce');
            if (!current_user_can('manage_options')) {
                $this->json_fail('没有清理缓存的权限。', 403);
            }
            $deleted = $this->generator->clear_cache();
            $this->json_ok(array('message' => '已清空资料缓存（' . intval($deleted) . ' 条记录）。'));
        }

        public function ajax_test_api() {
            $this->begin_json();
            check_ajax_referer('saw_admin_nonce', 'nonce');
            if (!current_user_can('manage_options')) {
                $this->json_fail('只有管理员可以测试接口。', 403);
            }

            $api_base = isset($_POST['api_base']) ? esc_url_raw(wp_unslash($_POST['api_base'])) : '';
            $api_key = isset($_POST['api_key']) ? sanitize_text_field(wp_unslash($_POST['api_key'])) : '';
            $model = isset($_POST['model']) ? sanitize_text_field(wp_unslash($_POST['model'])) : '';
            $api_format = isset($_POST['api_format']) ? sanitize_key($_POST['api_format']) : 'responses';
            $result = $this->generator->test_connection($api_base, $api_key, $model, $api_format);
            if (is_wp_error($result)) {
                $this->json_fail($result->get_error_message());
            }
            $this->json_ok($result);
        }

        public function ajax_save_draft() {
            $this->begin_json();
            check_ajax_referer('saw_admin_nonce', 'nonce');
            if (!current_user_can('manage_options')) {
                $this->json_fail('没有保存草稿的权限。', 403);
            }

            $title = isset($_POST['title']) ? sanitize_text_field(wp_unslash($_POST['title'])) : '';
            $content = isset($_POST['content']) ? wp_kses_post(wp_unslash($_POST['content'])) : '';
            $source_url = isset($_POST['source_url']) ? esc_url_raw(wp_unslash($_POST['source_url'])) : '';
            $category = isset($_POST['category']) ? absint($_POST['category']) : 0;
            if ($title === '' || $content === '') {
                $this->json_fail('标题和正文不能为空。');
            }

            $postarr = array(
                'post_title' => $title,
                'post_content' => $content,
                'post_status' => 'draft',
                'post_type' => 'post',
            );
            if ($category > 0) {
                $postarr['post_category'] = array($category);
            }
            $post_id = wp_insert_post($postarr, true);
            if (is_wp_error($post_id)) {
                $this->json_fail($post_id->get_error_message(), 500);
            }
            if ($source_url !== '') {
                update_post_meta($post_id, '_saw_source_url', $source_url);
            }
            update_post_meta($post_id, '_saw_generated_at', current_time('mysql'));

            $this->json_ok(array(
                'message' => '草稿已保存。',
                'edit_url' => get_edit_post_link($post_id, 'raw'),
                'post_id' => $post_id,
            ));
        }

        /* ---------------------------------------------------------------------
         * AJAX：GitHub Release 下载
         *
         * 分四步而不是一步：扫描（列表）→ 准备（探测线路+建目录）→ 分片（反复调用）
         * → 合并（校验大小）。每一步都是独立的小请求，任何一步失败都能单独重试，
         * 不会出现“下到 80% 因为一个请求超时全部白干”。
         * ------------------------------------------------------------------ */

        private function require_github() {
            if (!$this->github || !class_exists('SAW_GitHub')) {
                $this->json_fail('GitHub 下载模块没有加载成功。请重新上传完整安装包（缺少 includes/class-saw-github.php）。', 500);
            }
            return $this->github;
        }

        private function load_owned_job($job_id) {
            $job = $this->github->load_job($job_id);
            if ($job === null) {
                $this->json_fail('找不到这个下载任务，可能已经过期或被清理了。', 404);
            }
            // 只允许操作自己创建的任务，避免多管理员之间互相干扰。
            if (isset($job['user']) && (int) $job['user'] !== (int) get_current_user_id()) {
                $this->json_fail('这个下载任务不属于当前账号。', 403);
            }
            return $job;
        }

        /**
         * 扫描仓库的 Release 列表。
         */
        public function ajax_gh_scan() {
            $this->begin_json();
            check_ajax_referer('saw_admin_nonce', 'nonce');
            if (!current_user_can('manage_options')) {
                $this->json_fail('没有下载文件的权限。', 403);
            }
            $github = $this->require_github();

            // 扫描是低频操作，顺手清理超过一天没下完的任务和几天前已完成的任务记录，
            // 免得 .saw-jobs 目录一直堆着。
            $github->cleanup_stale();

            $input = isset($_POST['input']) ? sanitize_textarea_field(wp_unslash($_POST['input'])) : '';
            $target = SAW_GitHub::parse_repo($input);
            if (is_wp_error($target)) {
                $this->json_fail($target->get_error_message());
            }

            $cache_key = 'saw_gh_scan_' . md5($target['owner'] . '/' . $target['repo']);
            $cached = get_transient($cache_key);
            if (is_array($cached) && !empty($cached['releases'])) {
                $result = $cached;
                $result['cached'] = true;
            } else {
                $result = $github->list_releases($target['owner'], $target['repo'], 12);
                if (is_wp_error($result)) {
                    $this->json_fail($result->get_error_message());
                }
                $result['cached'] = false;
                // 缓存 5 分钟，避免反复点按钮把 GitHub 匿名额度耗光。
                set_transient($cache_key, $result, 300);
            }

            $root = $github->root_dir();
            $this->json_ok(array(
                'repo' => $result['full_name'],
                'owner' => $result['owner'],
                'repo_name' => $result['repo'],
                'releases' => $result['releases'],
                'target_tag' => $target['tag'],
                'target_asset' => $target['asset'],
                'root' => $root,
                'root_ready' => is_dir($root) && is_writable($root),
                'default_root' => $this->default_gh_root(),
                'max_mb' => (int) floor($github->max_bytes() / 1048576),
                'cached' => !empty($result['cached']),
            ));
        }

        /**
         * 准备一个文件的下载任务。
         */
        public function ajax_gh_prepare() {
            $this->begin_json();
            check_ajax_referer('saw_admin_nonce', 'nonce');
            if (!current_user_can('manage_options')) {
                $this->json_fail('没有下载文件的权限。', 403);
            }
            $github = $this->require_github();

            $url = isset($_POST['url']) ? esc_url_raw(wp_unslash($_POST['url'])) : '';
            $filename = isset($_POST['filename']) ? sanitize_file_name(wp_unslash($_POST['filename'])) : '';
            $subdir = isset($_POST['subdir']) ? sanitize_text_field(wp_unslash($_POST['subdir'])) : '';
            $size = isset($_POST['size']) ? absint($_POST['size']) : 0;
            if ($url === '' || $filename === '') {
                $this->json_fail('缺少文件地址或文件名。');
            }

            $result = $github->prepare($url, $filename, $size, $subdir, 0);
            if (is_wp_error($result)) {
                $this->json_fail($result->get_error_message());
            }

            $job = $result['job'];
            $this->json_ok(array(
                'job_id' => $job['id'],
                'filename' => $job['filename'],
                'size' => $job['size'],
                'size_text' => SAW_GitHub::human_size($job['size']),
                'parts' => $job['parts'],
                'range' => $job['range'],
                'route' => $job['route_label'],
                'dir' => $result['dir'],
                'url' => $result['url'],
                'root' => $result['root'],
            ));
        }

        /**
         * 下载一个分片。前端会反复调用，直到所有分片都到齐。
         */
        public function ajax_gh_part() {
            $this->begin_json();
            check_ajax_referer('saw_admin_nonce', 'nonce');
            if (!current_user_can('manage_options')) {
                $this->json_fail('没有下载文件的权限。', 403);
            }
            $github = $this->require_github();

            $job = $this->load_owned_job(isset($_POST['job_id']) ? sanitize_key($_POST['job_id']) : '');
            $index = isset($_POST['index']) ? absint($_POST['index']) : 0;

            $result = $github->fetch_part($job, $index);
            if (is_wp_error($result)) {
                $this->json_fail($result->get_error_message());
            }
            $progress = $github->job_progress($job);
            $this->json_ok(array(
                'index' => $result['index'],
                'received' => $result['received'],
                'length' => $result['length'],
                'done' => !empty($result['done']),
                'speed' => isset($result['speed']) ? (int) $result['speed'] : 0,
                'route' => isset($result['route']) ? $result['route'] : '',
                'progress' => $progress,
            ));
        }

        /**
         * 合并分片，落成最终文件。
         */
        public function ajax_gh_finish() {
            $this->begin_json();
            check_ajax_referer('saw_admin_nonce', 'nonce');
            if (!current_user_can('manage_options')) {
                $this->json_fail('没有下载文件的权限。', 403);
            }
            $github = $this->require_github();

            $job = $this->load_owned_job(isset($_POST['job_id']) ? sanitize_key($_POST['job_id']) : '');
            $result = $github->merge($job);
            if (is_wp_error($result)) {
                $this->json_fail($result->get_error_message());
            }
            $this->json_ok($result);
        }

        /**
         * 查询进度（断点续传、页面刷新后恢复用）。
         */
        public function ajax_gh_status() {
            $this->begin_json();
            check_ajax_referer('saw_admin_nonce', 'nonce');
            if (!current_user_can('manage_options')) {
                $this->json_fail('没有下载文件的权限。', 403);
            }
            $github = $this->require_github();

            $job_id = isset($_POST['job_id']) ? sanitize_key($_POST['job_id']) : '';
            if ($job_id !== '') {
                $job = $this->load_owned_job($job_id);
                $this->json_ok(array(
                    'job' => array(
                        'id' => $job['id'],
                        'filename' => $job['filename'],
                        'status' => $job['status'],
                        'dir' => $job['dir'],
                        'final' => $job['final'],
                        'url' => $github->public_url($job['final']),
                    ),
                    'progress' => $github->job_progress($job),
                ));
            }

            $jobs = array();
            foreach ($github->all_jobs() as $job) {
                if (isset($job['user']) && (int) $job['user'] !== (int) get_current_user_id()) {
                    continue;
                }
                $jobs[] = array(
                    'id' => $job['id'],
                    'filename' => $job['filename'],
                    'status' => $job['status'],
                    'dir' => $job['dir'],
                    'progress' => $job['progress'],
                );
            }
            $this->json_ok(array('jobs' => $jobs));
        }

        public function ajax_gh_abort() {
            $this->begin_json();
            check_ajax_referer('saw_admin_nonce', 'nonce');
            if (!current_user_can('manage_options')) {
                $this->json_fail('没有下载文件的权限。', 403);
            }
            $github = $this->require_github();
            $job = $this->load_owned_job(isset($_POST['job_id']) ? sanitize_key($_POST['job_id']) : '');
            $github->abort($job);
            $this->json_ok(array('message' => '已取消并删除未完成的分片。'));
        }

        /**
         * 列出下载目录里已有的文件。
         */
        public function ajax_gh_files() {
            $this->begin_json();
            check_ajax_referer('saw_admin_nonce', 'nonce');
            if (!current_user_can('manage_options')) {
                $this->json_fail('没有查看文件的权限。', 403);
            }
            $github = $this->require_github();
            $dir = isset($_POST['dir']) ? sanitize_text_field(wp_unslash($_POST['dir'])) : '';
            if ($dir === '') {
                $dir = $github->root_dir();
            }
            $this->json_ok(array(
                'dir' => $dir,
                'files' => $github->list_dir($dir),
            ));
        }

        /**
         * 把下载好的文件读出来发给浏览器。
         *
         * 下载根目录在网站内容目录之外时没有公网地址，「下载到本机」就走这里：
         * 服务器按 1 MB 一块读、边读边输出，内存占用跟文件大小无关。
         *
         * 这里不能用 json_ok / json_fail（会把二进制流包成 JSON），所以权限和
         * 失败都用 wp_die 直接结束。
         */
        public function ajax_gh_file() {
            check_ajax_referer('saw_admin_nonce', 'nonce');
            if (!current_user_can('manage_options')) {
                wp_die('没有下载文件的权限。', '无权访问', array('response' => 403));
            }
            if (!$this->github || !class_exists('SAW_GitHub')) {
                wp_die('GitHub 下载模块没有加载成功。', '模块缺失', array('response' => 500));
            }

            $job_id = isset($_REQUEST['job_id']) ? sanitize_key(wp_unslash($_REQUEST['job_id'])) : '';
            $job = $this->github->load_job($job_id);
            if ($job === null) {
                wp_die('找不到这个下载任务，可能已经过期或被清理了。', '任务不存在', array('response' => 404));
            }
            if (isset($job['user']) && (int) $job['user'] !== (int) get_current_user_id()) {
                wp_die('这个下载任务不属于当前账号。', '无权访问', array('response' => 403));
            }

            $path = isset($job['final']) ? (string) $job['final'] : '';
            if ($path === '' || !is_file($path)) {
                wp_die('文件还没下载完，或者已经被移动／删除了。', '文件不存在', array('response' => 404));
            }

            // 兜底：只允许读下载根目录里面的文件，任务数据被改过也读不到别处。
            $root = realpath($this->github->root_dir());
            $real = realpath($path);
            if ($root === false || $real === false || strpos($real, rtrim($root, '/\\') . DIRECTORY_SEPARATOR) !== 0) {
                wp_die('文件不在下载目录里，已拒绝。', '路径不合法', array('response' => 400));
            }

            $name = isset($_REQUEST['filename']) ? sanitize_file_name(wp_unslash($_REQUEST['filename'])) : '';
            if ($name === '') {
                $name = basename($real);
            }

            $size = @filesize($real);
            nocache_headers();
            while (ob_get_level() > 0) {
                @ob_end_clean();
            }
            @set_time_limit(0);
            @ini_set('zlib.output_compression', 'Off');

            header('Content-Type: application/octet-stream');
            header('Content-Disposition: attachment; filename="' . str_replace('"', '', $name) . '"; filename*=UTF-8\'\'' . rawurlencode($name));
            header('X-Content-Type-Options: nosniff');
            header('Content-Transfer-Encoding: binary');
            if ($size !== false) {
                header('Content-Length: ' . (int) $size);
            }

            $handle = @fopen($real, 'rb');
            if (!$handle) {
                wp_die('文件打不开，请检查目录权限。', '读取失败', array('response' => 500));
            }
            while (!feof($handle)) {
                $chunk = fread($handle, 1048576);
                if ($chunk === false) {
                    break;
                }
                echo $chunk; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
                flush();
                if (connection_aborted()) {
                    break;
                }
            }
            fclose($handle);
            exit;
        }
    }
}


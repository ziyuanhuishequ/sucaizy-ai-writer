<?php

if (!defined('ABSPATH')) {
    exit;
}

if (!class_exists('SAW_Frontend', false)) {
    class SAW_Frontend {
        /** 正文短代码是否已经输出过（避免同一页面重复出现两个编辑器）。 */
        private $rendered_in_content = false;
        /** 页脚悬浮入口是否已经输出过。 */
        private $rendered_in_footer = false;
        /** 下载弹窗是否已经输出过（一页只输出一个）。 */
        private $modal_printed = false;
        private $generator;

        public function __construct($generator) {
            $this->generator = $generator;
            add_shortcode('sucaizy_ai_writer', array($this, 'render_shortcode'));
            add_action('wp_enqueue_scripts', array($this, 'enqueue_target_assets'));
            add_action('wp_footer', array($this, 'render_footer_generator'), 5);
            // 弹窗放在页脚、紧跟在悬浮入口之后：固定定位不受主题容器影响，
            // 短代码在正文中间时也不会被 overflow 裁掉。
            add_action('wp_footer', array($this, 'render_download_modal'), 6);
        }

        private function is_target_page() {
            $slug = get_option('saw_frontend_slug', 'newposts-2');
            return $slug !== '' && is_page($slug);
        }

        /**
         * 判断当前页面正文里是否用了本插件短代码。
         * 短代码页也要在 wp_head 之前就把样式排进队列，否则样式会被丢弃、界面没有样式。
         */
        private function is_shortcode_page() {
            if (!function_exists('is_singular') || !is_singular()) {
                return false;
            }
            if (!function_exists('get_post') || !function_exists('has_shortcode')) {
                return false;
            }
            $post = get_post();
            if (!is_object($post) || empty($post->post_content)) {
                return false;
            }
            return has_shortcode($post->post_content, 'sucaizy_ai_writer');
        }

        public function enqueue_target_assets() {
            if (!is_user_logged_in() || !current_user_can('manage_options')) {
                return;
            }
            if ($this->is_target_page() || $this->is_shortcode_page()) {
                $this->enqueue_assets();
            }
        }

        private function enqueue_assets() {
            wp_enqueue_style('saw-frontend', SAW_URL . 'assets/frontend.css', array(), saw_asset_ver('assets/frontend.css'));
            wp_enqueue_style('saw-github', SAW_URL . 'assets/github.css', array(), saw_asset_ver('assets/github.css'));
            wp_enqueue_script('saw-frontend', SAW_URL . 'assets/frontend.js', array('jquery'), saw_asset_ver('assets/frontend.js'), true);
            wp_enqueue_script('saw-github', SAW_URL . 'assets/github-download.js', array('jquery'), saw_asset_ver('assets/github-download.js'), true);
            wp_localize_script('saw-frontend', 'sawFrontend', array(
                'ajaxUrl' => admin_url('admin-ajax.php'),
                'nonce' => wp_create_nonce('saw_admin_nonce'),
                'titleSelector' => get_option('saw_title_selector', ''),
                'contentSelector' => get_option('saw_content_selector', ''),
                'apiReady' => $this->generator->is_configuration_tested(),
                'titleCount' => max(3, min(10, (int) get_option('saw_title_count', 5))),
            ));
            // GitHub 下载弹窗与后台共用同一套脚本，配置单独注入，互不干扰。
            if (class_exists('SAW_GitHub_UI')) {
                wp_localize_script('saw-github', 'sawGh', array(
                    'ajaxUrl' => admin_url('admin-ajax.php'),
                    'nonce' => wp_create_nonce('saw_admin_nonce'),
                    'gh' => SAW_GitHub_UI::config(),
                ));
            }
        }

        public function render_footer_generator() {
            if ($this->rendered_in_footer || $this->rendered_in_content) {
                return;
            }
            if (!$this->is_target_page() || !is_user_logged_in() || !current_user_can('manage_options')) {
                return;
            }
            $generator = $this->generator_markup();
            if ($generator === '') {
                return;
            }
            $this->rendered_in_footer = true;
            ?>
            <button type="button" class="saw-front-launcher" id="saw-front-launcher" aria-expanded="false" aria-controls="saw-front-dock">AI 写文章</button>
            <div class="saw-front-dock" id="saw-front-dock" hidden>
                <button type="button" class="saw-front-close" id="saw-front-close" aria-label="关闭 AI 写作" title="关闭">&times;</button>
                <?php echo $generator; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
            </div>
            <?php
        }

        /**
         * 下载弹窗。只在本次请求真的输出过写作面板时才输出，避免每个页面都带一份。
         */
        public function render_download_modal() {
            if ($this->modal_printed) {
                return;
            }
            if (!$this->rendered_in_content && !$this->rendered_in_footer) {
                return;
            }
            if (!class_exists('SAW_GitHub_UI')) {
                return;
            }
            $this->modal_printed = true;
            echo SAW_GitHub_UI::modal(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        }

        /**
         * 短代码入口。
         *
         * 正文里放短代码时必须在原位置输出，所以这里只检查权限，不再用
         * “已经渲染过”的一次性标志拦自己；是否要让位给页脚悬浮入口，
         * 由 render_footer_generator() 根据 rendered_in_content 判断。
         */
        public function render_shortcode() {
            if (!is_user_logged_in() || !current_user_can('manage_options')) {
                return '';
            }
            if ($this->rendered_in_footer || $this->rendered_in_content) {
                return '';
            }
            $markup = $this->generator_markup();
            if ($markup !== '') {
                $this->rendered_in_content = true;
            }
            return $markup;
        }

        /**
         * 生成一次编辑器表单的 HTML。两个入口共用，但各自维护自己的已渲染标志。
         */
        private function generator_markup() {
            $this->enqueue_assets();

            ob_start();
            ?>
            <section class="saw-front" aria-labelledby="saw-front-heading">
                <h2 id="saw-front-heading">AI 文章写作</h2>
                <div class="saw-front-field">
                    <label for="saw-front-input">项目链接或资源名称</label>
                    <textarea id="saw-front-input" rows="3" placeholder="输入公开链接、GitHub 项目、软件或素材名称"></textarea>
                </div>
                <div class="saw-front-row">
                    <div class="saw-front-field">
                        <label for="saw-front-type">内容类型</label>
                        <select id="saw-front-type">
                            <option value="auto">自动判断</option>
                            <option value="software">软件 / GitHub 项目</option>
                            <option value="website">网站 / 在线服务</option>
                            <option value="material">数字素材</option>
                        </select>
                    </div>
                    <div class="saw-front-field saw-front-grow">
                        <label for="saw-front-notes">补充说明（可选）</label>
                        <input type="text" id="saw-front-notes" placeholder="例如：重点介绍离线功能">
                    </div>
                </div>
                <div class="saw-front-generate-row">
                    <button type="button" class="saw-front-button" id="saw-front-generate"<?php disabled(!$this->generator->is_configuration_tested()); ?>>生成文章</button>
                    <?php
                    if (class_exists('SAW_GitHub_UI')) {
                        echo SAW_GitHub_UI::button('front'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
                    }
                    ?>
                    <span class="saw-front-loading" id="saw-front-loading" hidden>正在读取资料并生成<em id="saw-front-timer"></em></span>
                </div>
                <?php
                if (class_exists('SAW_GitHub_UI')) {
                    echo SAW_GitHub_UI::hint('front'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
                }
                ?>
                <p class="saw-front-message" id="saw-front-message" role="status"></p>
                <?php if (!$this->generator->is_configuration_tested()) : ?>
                    <p class="saw-front-message is-error">API 尚未测试通过，请先在后台<a href="<?php echo esc_url(admin_url('admin.php?page=sucaizy-ai-writer-settings')); ?>">接口设置</a>中完成测试。</p>
                <?php endif; ?>

                <div class="saw-front-result" id="saw-front-result" hidden>
                    <p class="saw-front-stats" id="saw-front-stats" hidden></p>
                    <p class="saw-front-notices" id="saw-front-warnings" hidden></p>
                    <div class="saw-front-field">
                        <label for="saw-front-titles">备选标题</label>
                        <div class="saw-front-row">
                            <select id="saw-front-titles" class="saw-front-grow"></select>
                            <button type="button" class="saw-front-button saw-front-secondary" id="saw-front-title-refresh">换一批</button>
                        </div>
                    </div>
                    <div class="saw-front-field">
                        <label for="saw-front-title">文章标题</label>
                        <input type="text" id="saw-front-title">
                    </div>
                    <div class="saw-front-field">
                        <label for="saw-front-content">文章正文</label>
                        <div id="saw-front-content" class="saw-front-editor" contenteditable="true" role="textbox" aria-multiline="true"></div>
                    </div>
                    <div class="saw-front-actions">
                        <button type="button" class="saw-front-button" id="saw-front-fill">填入投稿编辑器</button>
                        <button type="button" class="saw-front-button saw-front-secondary" id="saw-front-preview-toggle" aria-expanded="false">预览排版</button>
                    </div>
                    <div class="saw-front-preview" id="saw-front-preview" hidden></div>
                    <p class="saw-front-message" id="saw-front-fill-message" role="status"></p>
                </div>
            </section>
            <?php
            return ob_get_clean();
        }
    }
}


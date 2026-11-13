<?php

if (!defined('ABSPATH')) {
    exit;
}

if (!class_exists('SAW_GitHub_UI', false)) {
    /**
     * GitHub 下载入口的界面片段。
     *
     * 后台写作页与前台投稿页用的是同一套按钮、弹窗和脚本，标记只写一份，
     * 免得两边改着改着长得不一样。
     */
    class SAW_GitHub_UI {
        /**
         * 传给前端脚本的配置。
         *
         * inContent 决定下载完给不给公网链接：只有落在网站内容目录里的文件
         * 才有 URL，站外目录只给服务器路径。
         */
        public static function config() {
            $github = class_exists('SAW_GitHub') ? new SAW_GitHub() : null;
            $root = $github ? $github->root_dir() : '';
            $max_mb = 512;
            if ($github && method_exists($github, 'max_bytes')) {
                $bytes = (int) $github->max_bytes();
                if ($bytes > 0) {
                    $max_mb = max(1, (int) floor($bytes / 1048576));
                }
            }
            $content = str_replace('\\', '/', WP_CONTENT_DIR);
            $normalized = str_replace('\\', '/', $root);

            return array(
                'root' => $root,
                'rootWritable' => $root !== '' && is_dir($root) && is_writable($root),
                'inContent' => $normalized !== '' && strpos($normalized . '/', $content . '/') === 0,
                'maxMb' => $max_mb,
                'byOs' => 1,
                'parts' => max(1, min(8, (int) get_option('saw_gh_parts', 3))),
            );
        }

        /**
         * 下载按钮。
         *
         * @param string $variant admin（后台 .button）或 front（前台 .saw-front-button）。
         */
        public static function button($variant = 'admin') {
            $classes = $variant === 'front'
                ? 'saw-front-button saw-front-secondary saw-gh-open'
                : 'button saw-gh-open';
            return '<button type="button" class="' . esc_attr($classes) . '" id="saw-gh-open" hidden>下载 GitHub 内容</button>';
        }

        /** 识别到仓库地址后的提示条。 */
        public static function hint($variant = 'admin') {
            $classes = $variant === 'front' ? 'saw-gh-hint is-front' : 'description saw-gh-hint';
            return '<p class="' . esc_attr($classes) . '" id="saw-gh-hint" hidden></p>';
        }

        /** 下载弹窗。一页只放一个，所以 id 用固定值。 */
        public static function modal() {
            ob_start();
            ?>
            <div class="saw-modal" id="saw-gh-modal" hidden>
                <div class="saw-modal-mask" data-saw-close="1"></div>
                <div class="saw-modal-box" role="dialog" aria-modal="true" aria-labelledby="saw-gh-title">
                    <div class="saw-modal-head">
                        <h2 id="saw-gh-title">下载 GitHub 内容</h2>
                        <button type="button" class="button-link saw-modal-close" data-saw-close="1" aria-label="关闭">×</button>
                    </div>
                    <div class="saw-modal-body">
                        <p class="saw-gh-repo" id="saw-gh-repo"></p>

                        <div class="saw-inline saw-gh-toolbar">
                            <label for="saw-gh-release">版本</label>
                            <select id="saw-gh-release"></select>
                            <label class="saw-gh-check"><input type="checkbox" id="saw-gh-latest-only" checked> 只看最新版</label>
                            <button type="button" class="button button-small" id="saw-gh-select-all">全选</button>
                            <button type="button" class="button button-small" id="saw-gh-select-none">清空</button>
                        </div>

                        <div class="saw-gh-assets" id="saw-gh-assets"></div>

                        <div class="saw-gh-target" id="saw-gh-target">
                            <p class="saw-gh-target-title">下载到</p>
                            <div class="saw-gh-target-options">
                                <label class="saw-gh-target-option is-active" data-target="local">
                                    <input type="radio" name="saw-gh-target" value="local" checked>
                                    <span class="saw-gh-target-text">
                                        <span class="saw-gh-target-label">本机电脑</span>
                                        <span class="saw-gh-target-note">直接下到浏览器的下载文件夹，服务器不存文件</span>
                                    </span>
                                </label>
                                <label class="saw-gh-target-option" data-target="server">
                                    <input type="radio" name="saw-gh-target" value="server">
                                    <span class="saw-gh-target-text">
                                        <span class="saw-gh-target-label">服务器目录</span>
                                        <span class="saw-gh-target-note">存到网站目录，能拿到站内链接写进文章</span>
                                    </span>
                                </label>
                            </div>
                            <p class="description" id="saw-gh-target-note"></p>
                        </div>

                        <div class="saw-gh-route" id="saw-gh-route-box">
                            <label for="saw-gh-route">下载线路</label>
                            <select id="saw-gh-route">
                                <option value="direct">直连 GitHub</option>
                                <option value="mirror">镜像加速（直连卡住时用）</option>
                            </select>
                            <p class="description" id="saw-gh-route-note">直连下不动就换镜像：会先让服务器探一条能用的地址，再交给浏览器。</p>
                        </div>

                        <div class="saw-gh-path" id="saw-gh-path">
                            <div class="saw-gh-path-row">
                                <label for="saw-gh-subdir">保存到</label>
                                <div class="saw-gh-path-input">
                                    <span class="saw-gh-root" id="saw-gh-root"></span>
                                    <input type="text" id="saw-gh-subdir" placeholder="仓库名/版本号">
                                </div>
                            </div>
                            <label class="saw-gh-check"><input type="checkbox" id="saw-gh-by-os" checked> 按系统分子文件夹（Windows / macOS / Linux）</label>
                            <p class="description saw-gh-root-note" id="saw-gh-root-note"></p>
                        </div>

                        <!-- 保存位置预览放在路径块外面：本机模式下路径块是隐藏的，
                             提示还得留着，不然用户不知道文件会落到哪。 -->
                        <p class="description saw-gh-abs" id="saw-gh-abs"></p>

                        <div class="saw-gh-progress" id="saw-gh-progress" hidden></div>
                        <p id="saw-gh-message" role="status"></p>
                    </div>
                    <div class="saw-modal-foot">
                        <button type="button" class="button button-primary" id="saw-gh-start" disabled>开始下载</button>
                        <button type="button" class="button" data-saw-close="1">取消</button>
                        <span class="spinner" id="saw-gh-spinner"></span>
                        <button type="button" class="button-link saw-gh-refresh" id="saw-gh-refresh" hidden>重新扫描</button>
                    </div>
                </div>
            </div>
            <?php
            return ob_get_clean();
        }
    }
}

<?php
/**
 * GitHub Release 下载器（服务端实现）。
 *
 * 这类工作原本要装一个桌面工具（扫描 Release、勾选资产、按系统归档、多线路下载、
 * 断点续传）。放在插件里做，好处是下载完就能直接拿到站内链接写进文章，
 * 坏处是受 PHP 的执行时间和内存限制，所以实现上必须绕开两个坑：
 *
 * 1. 不能用“一个请求下完整个文件”。PHP 默认 30-60 秒就断，几百 MB 的包一定失败。
 *    这里把文件切成若干分片，前端逐片请求，每片只有几 MB，单个请求几十秒内返回。
 * 2. 不能把文件读进内存。分片写入用追加模式，合并用 stream_copy_to_stream，
 *    无论文件多大，内存占用都是常量。
 *
 * 断点续传靠文件系统本身：分片文件就在磁盘上，中断后按现有大小继续请求剩下的字节，
 * 不需要额外的状态表，刷新页面、换浏览器都不会丢进度。
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!class_exists('SAW_GitHub', false)) {
    class SAW_GitHub {
        const API_BASE = 'https://api.github.com';
        const UA = 'Sucaizy-GitHub-Fetcher/1.0 (+https://www.sucaizy.com/)';
        const JOB_DIR = '.saw-jobs';
        const PART_MIN = 262144;          // 单个分片不小于 256 KB
        const PART_MAX_BYTES = 4194304;   // 单个分片不超过 4 MB
        const MAX_PARTS = 8;
        const RETRY_PER_PART = 3;         // 每个分片最多换 3 条线路

        /* ------------------------------------------------------------------
         * 一、地址解析
         * --------------------------------------------------------------- */

        /**
         * 从用户输入里认出 GitHub 仓库。
         *
         * 支持：完整仓库地址、带 /releases 或 /releases/tag/v1.0 的地址、
         * releases/download 直链、以及手写的 owner/repo。也允许一段多行文本
         * （用户可能直接把 README 里的链接粘进来），取第一个能认出来的。
         *
         * @return array|WP_Error array('owner','repo','tag','asset')
         */
        public static function parse_repo($input) {
            $input = trim((string) $input);
            if ($input === '') {
                return new WP_Error('saw_gh_empty', '请先填写 GitHub 项目地址。');
            }
            $lines = preg_split('/[\r\n]+/', $input);
            if (!is_array($lines)) {
                $lines = array($input);
            }
            foreach ($lines as $line) {
                $line = trim($line);
                if ($line === '') {
                    continue;
                }
                $parsed = self::parse_one($line);
                if ($parsed !== null) {
                    return $parsed;
                }
            }
            return new WP_Error('saw_gh_bad_repo', '没有识别出 GitHub 仓库地址。支持 https://github.com/用户名/仓库名、用户名/仓库名，或某个 Release 文件的直链。');
        }

        private static function parse_one($line) {
            // 手写的 owner/repo
            if (preg_match('#^([A-Za-z0-9][A-Za-z0-9_.-]{0,38})/([A-Za-z0-9_.-]{1,100})$#', $line, $matches)) {
                return self::build_target($matches[1], $matches[2], '', '');
            }
            // 地址形式：允许带协议、不带协议、带 www
            if (!preg_match('#(?:^|//|@)(?:www\.)?github\.com/([A-Za-z0-9][A-Za-z0-9_.-]{0,38})/([A-Za-z0-9_.-]{1,100})#i', $line, $matches)) {
                return null;
            }
            $owner = $matches[1];
            $repo = preg_replace('/\.git$/i', '', $matches[2]);
            $tag = '';
            $asset = '';
            // 定界符用 ~，不能再用 #，否则 [^\s/?#] 里的 # 会被当成结束符。
            if (preg_match('~/releases/tag/([^\s/?#]+)~i', $line, $tag_match)) {
                $tag = urldecode($tag_match[1]);
            }
            if (preg_match('~/releases/download/[^\s/?#]+/([^\s/?#]+)~i', $line, $asset_match)) {
                $asset = urldecode($asset_match[1]);
            }
            return self::build_target($owner, $repo, $tag, $asset);
        }

        private static function build_target($owner, $repo, $tag, $asset) {
            $owner = preg_replace('/[^A-Za-z0-9_.-]/', '', (string) $owner);
            $repo = preg_replace('/[^A-Za-z0-9_.-]/', '', (string) $repo);
            if ($owner === '' || $repo === '') {
                return null;
            }
            return array(
                'owner' => $owner,
                'repo' => $repo,
                'tag' => (string) $tag,
                'asset' => (string) $asset,
            );
        }

        /* ------------------------------------------------------------------
         * 二、Release 扫描
         * --------------------------------------------------------------- */

        /**
         * 拉取仓库的 Release 列表。
         *
         * @param string $owner
         * @param string $repo
         * @param int    $limit 最多返回几个版本
         * @return array|WP_Error
         */
        public function list_releases($owner, $repo, $limit = 10) {
            $limit = max(1, min(20, (int) $limit));
            $owner = rawurlencode($owner);
            $repo = rawurlencode($repo);
            $path = '/repos/' . $owner . '/' . $repo . '/releases?per_page=' . $limit;

            $response = $this->api_get($path);
            if (is_wp_error($response)) {
                return $response;
            }

            $releases = array();
            foreach ($response as $item) {
                if (!is_array($item) || empty($item['tag_name'])) {
                    continue;
                }
                $assets = array();
                $files = isset($item['assets']) && is_array($item['assets']) ? $item['assets'] : array();
                foreach ($files as $file) {
                    if (!is_array($file) || empty($file['name']) || empty($file['browser_download_url'])) {
                        continue;
                    }
                    $name = (string) $file['name'];
                    // 只接受 http(s) 直链，防止上游返回奇怪协议。
                    if (!preg_match('#^https?://#i', (string) $file['browser_download_url'])) {
                        continue;
                    }
                    $kind = self::classify($name);
                    $assets[] = array(
                        'name' => $name,
                        'size' => isset($file['size']) ? (int) $file['size'] : 0,
                        'size_text' => self::human_size(isset($file['size']) ? (int) $file['size'] : 0),
                        'downloads' => isset($file['download_count']) ? (int) $file['download_count'] : 0,
                        'url' => (string) $file['browser_download_url'],
                        'os' => $kind['os'],
                        'os_label' => $kind['os_label'],
                        'arch' => $kind['arch'],
                        'arch_label' => $kind['arch_label'],
                    );
                }
                $releases[] = array(
                    'tag' => (string) $item['tag_name'],
                    'name' => isset($item['name']) ? (string) $item['name'] : '',
                    'published_at' => isset($item['published_at']) ? (string) $item['published_at'] : '',
                    'prerelease' => !empty($item['prerelease']),
                    'is_latest' => !empty($item['tag_name']) && isset($response[0]['tag_name']) && $item['tag_name'] === $response[0]['tag_name'],
                    'assets' => $assets,
                );
            }

            if (empty($releases)) {
                return new WP_Error('saw_gh_no_release', '这个仓库还没有发布 Release，没有可下载的安装包。如果你需要源码，可以到仓库首页点 “Code → Download ZIP”。');
            }

            return array(
                'owner' => $owner,
                'repo' => $repo,
                'full_name' => $owner . '/' . $repo,
                'releases' => $releases,
            );
        }

        /**
         * 调用 GitHub API。带 Token 时限额从 60 次/小时升到 5000 次/小时。
         */
        private function api_get($path) {
            $url = self::API_BASE . $path;
            $args = array(
                'timeout' => 20,
                'redirection' => 3,
                'headers' => array(
                    'Accept' => 'application/vnd.github+json',
                    'X-GitHub-Api-Version' => '2022-11-28',
                    'User-Agent' => self::UA,
                ),
            );
            $token = $this->token();
            if ($token !== '') {
                $args['headers']['Authorization'] = 'Bearer ' . $token;
            }

            $response = wp_remote_get($url, $args);
            if (is_wp_error($response)) {
                return new WP_Error('saw_gh_api_error', 'GitHub 接口连接失败：' . $response->get_error_message());
            }
            $code = (int) wp_remote_retrieve_response_code($response);
            $body = (string) wp_remote_retrieve_body($response);

            if ($code === 403 || $code === 429) {
                $remaining = wp_remote_retrieve_header($response, 'x-ratelimit-remaining');
                if ((string) $remaining === '0') {
                    return new WP_Error('saw_gh_rate_limit', 'GitHub 接口的匿名额度用完了（每小时 60 次）。到“接口设置 → GitHub 下载”里填一个 Token 就能提到每小时 5000 次。');
                }
                return new WP_Error('saw_gh_forbidden', 'GitHub 拒绝了这次请求（HTTP 403）。如果是私有仓库，需要在设置里填写有仓库读取权限的 Token。');
            }
            if ($code === 404) {
                return new WP_Error('saw_gh_not_found', '找不到这个仓库，请检查用户名和仓库名是否写对，以及仓库是否为公开状态。');
            }
            if ($code < 200 || $code >= 300) {
                return new WP_Error('saw_gh_http', 'GitHub 接口返回 HTTP ' . $code . '。');
            }

            $data = json_decode($body, true);
            if (!is_array($data)) {
                return new WP_Error('saw_gh_bad_json', 'GitHub 接口返回的内容无法解析，请稍后重试。');
            }
            return $data;
        }

        private function token() {
            $token = trim((string) get_option('saw_github_token', ''));
            // Token 只允许安全字符，避免被写进请求头时注入换行。
            return preg_replace('/[^A-Za-z0-9_\-.]/', '', $token);
        }

        /* ------------------------------------------------------------------
         * 三、资产分类（按文件名判断系统与架构）
         * --------------------------------------------------------------- */

        /**
         * 判断一个资产属于哪个系统、哪个架构。
         *
         * 判断顺序很重要：先看扩展名（.dmg 一定是 macOS），再看关键词。
         * 反例：`myapp-windows-x64.zip` 和 `myapp-source.zip` 都是 zip，
         * 只看扩展名会把源码包误判成 Windows 安装包。
         *
         * @return array
         */
        public static function classify($name) {
            $lower = strtolower((string) $name);
            // 去掉扩展名，避免 .zip 里的 “zip” 干扰关键词匹配。
            $stem = preg_replace('/\.(zip|tar\.gz|tgz|tar|gz|7z|exe|msi|dmg|pkg|deb|rpm|apk|ipa|appimage)$/i', '', $lower);
            if (!is_string($stem)) {
                $stem = $lower;
            }

            $os = '';
            $os_label = '';

            if (preg_match('/\.(apk)$/i', $lower)) {
                $os = 'android';
                $os_label = 'Android';
            } elseif (preg_match('/\.(ipa)$/i', $lower)) {
                $os = 'ios';
                $os_label = 'iOS';
            } elseif (preg_match('/\.(dmg|pkg)$/i', $lower)) {
                $os = 'macos';
                $os_label = 'macOS';
            } elseif (preg_match('/\.(msi|exe)$/i', $lower)) {
                $os = 'windows';
                $os_label = 'Windows';
            } elseif (preg_match('/\.(deb|rpm|appimage)$/i', $lower)) {
                $os = 'linux';
                $os_label = 'Linux';
            } elseif (preg_match('/(source|src)[-_]?code|^source|[-_.]source$|source\.(zip|tar)/i', $stem)) {
                $os = 'source';
                $os_label = '源码';
            } elseif (preg_match('/windows|win32|win64|[-_.]win([-_.]|$)/i', $stem)) {
                $os = 'windows';
                $os_label = 'Windows';
            } elseif (preg_match('/macos|mac_os|osx|darwin|apple[-_]?silicon|universal2?/i', $stem)) {
                $os = 'macos';
                $os_label = 'macOS';
            } elseif (preg_match('/linux|ubuntu|debian|fedora|centos|appimage/i', $stem)) {
                $os = 'linux';
                $os_label = 'Linux';
            } elseif (preg_match('/android/i', $stem)) {
                $os = 'android';
                $os_label = 'Android';
            } else {
                $os = 'other';
                $os_label = '通用';
            }

            $arch = '';
            $arch_label = '';
            if (preg_match('/aarch64|arm64|arm[-_]?64|apple[-_]?silicon|m1|m2|m3|m4/i', $stem)) {
                $arch = 'arm64';
                $arch_label = 'ARM64';
            } elseif (preg_match('/armv7|armhf|arm32|[-_.]arm([-_.]|$)/i', $stem)) {
                $arch = 'arm';
                $arch_label = 'ARM';
            } elseif (preg_match('/x86_64|amd64|x64|win64|64[-_]?bit/i', $stem)) {
                $arch = 'x64';
                $arch_label = 'x64';
            } elseif (preg_match('/i386|i686|x86|win32|32[-_]?bit/i', $stem)) {
                $arch = 'x86';
                $arch_label = 'x86';
            } elseif (preg_match('/universal/i', $stem)) {
                $arch = 'universal';
                $arch_label = 'Universal';
            } else {
                $arch = 'any';
                $arch_label = '';
            }

            return array(
                'os' => $os,
                'os_label' => $os_label,
                'arch' => $arch,
                'arch_label' => $arch_label,
            );
        }

        public static function human_size($bytes) {
            $bytes = (float) $bytes;
            if ($bytes <= 0) {
                return '—';
            }
            $units = array('B', 'KB', 'MB', 'GB', 'TB');
            $index = 0;
            while ($bytes >= 1024 && $index < count($units) - 1) {
                $bytes /= 1024;
                $index++;
            }
            if ($index === 0) {
                return number_format($bytes, 0) . ' B';
            }
            return number_format($bytes, 1) . ' ' . $units[$index];
        }

        /* ------------------------------------------------------------------
         * 四、镜像线路
         * --------------------------------------------------------------- */

        /**
         * 内置线路。{url} 是占位符，会被替换成真实的 GitHub 下载地址。
         *
         * 空字符串代表直连。镜像站随时可能停服，所以顺序上直连放第一位，
         * 探测时谁先返回就用谁；失败会自动往下一跳，不需要用户手动改配置。
         */
        public static function default_routes() {
            return array(
                '',
                'https://ghfast.top/{url}',
                'https://gh-proxy.com/{url}',
                'https://ghproxy.net/{url}',
                'https://gh.llkk.cc/{url}',
            );
        }

        /**
         * 用户配置的线路 + 内置线路，去重后返回。
         */
        public function routes() {
            $custom = trim((string) get_option('saw_github_routes', ''));
            $routes = array();
            if ($custom !== '') {
                $lines = preg_split('/[\r\n]+/', $custom);
                if (is_array($lines)) {
                    foreach ($lines as $line) {
                        $line = trim($line);
                        if ($line === '' || strpos($line, '//') === 0) {
                            continue;
                        }
                        // 允许直接写一个裸域名。
                        if (!preg_match('#^https?://#i', $line) && strpos($line, '{url}') === false) {
                            $line = 'https://' . ltrim($line, '/');
                        }
                        if (stripos($line, 'http://') === 0) {
                            // 明文 http 会被中间人替换内容，不接受。
                            continue;
                        }
                        $routes[] = $line;
                    }
                }
            }
            // 用户没填就内置；填了就只用自己的（用户可能在公司内网有专线）。
            if (empty($routes)) {
                $routes = self::default_routes();
            }
            $unique = array();
            foreach ($routes as $route) {
                if (!in_array($route, $unique, true)) {
                    $unique[] = $route;
                }
            }
            if (count($unique) > 12) {
                $unique = array_slice($unique, 0, 12);
            }
            return $unique;
        }

        public function build_url($url, $route) {
            $route = trim((string) $route);
            if ($route === '') {
                return $url;
            }
            if (strpos($route, '{url}') !== false) {
                return str_replace('{url}', $url, $route);
            }
            return rtrim($route, '/') . '/' . $url;
        }

        /* ------------------------------------------------------------------
         * 五、HTTP 请求（支持 Range）
         * --------------------------------------------------------------- */

        /**
         * 发一个 GET 请求，可选 Range。
         *
         * 不用 wp_remote_get 的原因：需要精确控制 Range、跟随跨域重定向、
         * 以及拿到 Content-Range 来判断服务端是否真的支持分片。
         *
         * @return array('ok','status','headers','body','error','time')
         */
        private function fetch($url, $start = null, $end = null, $timeout = 45, $max_bytes = 0) {
            $started = microtime(true);
            $blocked = defined('WP_HTTP_BLOCK_EXTERNAL') && WP_HTTP_BLOCK_EXTERNAL;
            if ($blocked) {
                return array(
                    'ok' => false, 'status' => 0, 'headers' => array(), 'body' => '',
                    'error' => '站点在 wp-config.php 里禁用了外部请求（WP_HTTP_BLOCK_EXTERNAL），无法下载。',
                    'time' => 0,
                );
            }

            $headers = array('Accept: */*', 'Accept-Encoding: identity');
            if ($start !== null) {
                $range_end = ($end === null) ? '' : (string) (int) $end;
                $headers[] = 'Range: bytes=' . (int) $start . '-' . $range_end;
            }
            $token = $this->token();

            if (function_exists('curl_init')) {
                $handle = curl_init($url);
                if ($handle === false) {
                    return array(
                        'ok' => false, 'status' => 0, 'headers' => array(), 'body' => '',
                        'error' => '无法初始化下载连接。', 'time' => 0,
                    );
                }
                $raw_headers = array();
                $options = array(
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_HEADER => false,
                    CURLOPT_FOLLOWLOCATION => true,
                    CURLOPT_MAXREDIRS => 6,
                    CURLOPT_CONNECTTIMEOUT => min(15, max(5, (int) $timeout)),
                    CURLOPT_TIMEOUT => max(5, (int) $timeout),
                    CURLOPT_SSL_VERIFYPEER => true,
                    CURLOPT_SSL_VERIFYHOST => 2,
                    CURLOPT_USERAGENT => self::UA,
                    CURLOPT_HTTPHEADER => $headers,
                );
                // 只有访问 GitHub 自己的域名才带 Token，避免凭据被带到镜像站。
                if ($token !== '' && strpos($url, 'github.com') !== false) {
                    $options[CURLOPT_HTTPHEADER][] = 'Authorization: Bearer ' . $token;
                }
                if (defined('WP_PROXY_HOST') && WP_PROXY_HOST) {
                    $proxy = WP_PROXY_HOST;
                    if (defined('WP_PROXY_PORT') && WP_PROXY_PORT) {
                        $proxy .= ':' . WP_PROXY_PORT;
                    }
                    $options[CURLOPT_PROXY] = $proxy;
                }
                curl_setopt_array($handle, $options);
                curl_setopt($handle, CURLOPT_HEADERFUNCTION, function ($ch, $line) use (&$raw_headers) {
                    $length = strlen($line);
                    $piece = explode(':', $line, 2);
                    if (count($piece) === 2) {
                        $raw_headers[strtolower(trim($piece[0]))] = trim($piece[1]);
                    } elseif (stripos($line, 'HTTP/') === 0) {
                        // 跟随重定向时会收到多段响应头，只保留最后一段。
                        $raw_headers = array();
                    }
                    return $length;
                });
                $body = curl_exec($handle);
                $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
                $errno = curl_errno($handle);
                $error = $errno ? curl_error($handle) : '';
                if (PHP_VERSION_ID < 80500) {
                    curl_close($handle);
                }
                if ($errno) {
                    return array(
                        'ok' => false, 'status' => $status, 'headers' => $raw_headers, 'body' => '',
                        'error' => $error !== '' ? $error : '下载连接失败',
                        'time' => microtime(true) - $started,
                    );
                }
                if (!is_string($body)) {
                    $body = '';
                }
                if ($max_bytes > 0 && strlen($body) > $max_bytes) {
                    $body = substr($body, 0, $max_bytes);
                }
                return array(
                    'ok' => ($status >= 200 && $status < 400),
                    'status' => $status,
                    'headers' => $raw_headers,
                    'body' => $body,
                    'error' => ($status >= 200 && $status < 400) ? '' : 'HTTP ' . $status,
                    'time' => microtime(true) - $started,
                );
            }

            // 没有 curl：退回 WordPress 的请求实现（不支持精确 Range 时也能整包下载）。
            $args = array(
                'timeout' => max(5, (int) $timeout),
                'redirection' => 6,
                'headers' => array('User-Agent' => self::UA),
            );
            if ($start !== null) {
                $args['headers']['Range'] = 'bytes=' . (int) $start . '-' . ($end === null ? '' : (int) $end);
            }
            if ($max_bytes > 0) {
                $args['limit_response_size'] = $max_bytes;
            }
            $response = wp_remote_get($url, $args);
            if (is_wp_error($response)) {
                return array(
                    'ok' => false, 'status' => 0, 'headers' => array(), 'body' => '',
                    'error' => $response->get_error_message(), 'time' => microtime(true) - $started,
                );
            }
            $code = (int) wp_remote_retrieve_response_code($response);
            $response_headers = array();
            foreach ((array) wp_remote_retrieve_headers($response) as $key => $value) {
                $response_headers[strtolower((string) $key)] = is_array($value) ? implode(',', $value) : (string) $value;
            }
            return array(
                'ok' => ($code >= 200 && $code < 400),
                'status' => $code,
                'headers' => $response_headers,
                'body' => (string) wp_remote_retrieve_body($response),
                'error' => ($code >= 200 && $code < 400) ? '' : 'HTTP ' . $code,
                'time' => microtime(true) - $started,
            );
        }

        /**
         * 探测下载地址：确认能不能下、支不支持分片、文件多大、哪条线路最快。
         *
         * 只请求 2 个字节，代价极小，但能省掉“下到一半才发现不支持 Range”的返工。
         *
         * @return array|WP_Error array('url','route','route_index','range','size','time')
         */
        public function probe($url) {
            $url = $this->validate_asset_url($url);
            if (is_wp_error($url)) {
                return $url;
            }
            $routes = $this->routes();
            $errors = array();
            $best = null;
            // 探测本身也要有上限：5 条线路各等 20 秒会把“点一下”变成“等两分钟”。
            $deadline = microtime(true) + 15;

            foreach ($routes as $index => $route) {
                if ($best !== null && microtime(true) > $deadline) {
                    break;
                }
                $target = $this->build_url($url, $route);
                $result = $this->fetch($target, 0, 1, 20);
                if (!$result['ok']) {
                    $errors[] = ($route === '' ? '直连' : $route) . '：' . $result['error'];
                    continue;
                }
                // 200 说明服务端忽略了 Range（整包返回），206 才是真正支持分片。
                $range_ok = ($result['status'] === 206);
                if ($result['status'] !== 200 && $result['status'] !== 206) {
                    $errors[] = ($route === '' ? '直连' : $route) . '：HTTP ' . $result['status'];
                    continue;
                }
                // 镜像站有时会把错误页当 200 返回，Content-Type 能识别出来。
                $type = isset($result['headers']['content-type']) ? strtolower($result['headers']['content-type']) : '';
                if (strpos($type, 'text/html') !== false) {
                    $errors[] = ($route === '' ? '直连' : $route) . '：返回的是网页而不是文件（镜像可能已失效）';
                    continue;
                }

                $size = 0;
                if (isset($result['headers']['content-range'])
                    && preg_match('#bytes\s+\d+-\d+/(\d+)#i', $result['headers']['content-range'], $matches)) {
                    $size = (int) $matches[1];
                } elseif (isset($result['headers']['content-length'])) {
                    $content_length = (int) $result['headers']['content-length'];
                    // Range 请求的 content-length 是区间长度，只有 200 时才是总大小。
                    if ($result['status'] === 200) {
                        $size = $content_length;
                    }
                }

                $candidate = array(
                    'url' => $url,
                    'route' => $route,
                    'route_index' => $index,
                    'range' => $range_ok,
                    'size' => $size,
                    'time' => round($result['time'], 2),
                    'label' => $route === '' ? '直连' : $route,
                );
                // 直连又快又支持分片就不用再试镜像；直连要 3 秒以上才拿到 2 个字节，
                // 说明下载大概率也很慢，这时候值得花几秒测一下镜像。
                if ($route === '' && $result['status'] === 206 && $candidate['time'] < 1.5) {
                    return $candidate;
                }
                if ($best === null || $candidate['time'] < $best['time']) {
                    $best = $candidate;
                }
            }

            if ($best !== null) {
                return $best;
            }
            return new WP_Error(
                'saw_gh_unreachable',
                '所有下载线路都失败了，服务器可能访问不了 GitHub。' . "\n" . implode("\n", array_slice($errors, 0, 5))
            );
        }

        /**
         * 只允许从 GitHub 下载，防止这个接口被当成通用代理（SSRF）。
         */
        private function validate_asset_url($url) {
            $url = trim((string) $url);
            if ($url === '' || !preg_match('#^https://#i', $url)) {
                return new WP_Error('saw_gh_bad_url', '下载地址无效。');
            }
            $host = parse_url($url, PHP_URL_HOST);
            $host = $host ? strtolower($host) : '';
            $allowed = array(
                'github.com',
                'www.github.com',
                'objects.githubusercontent.com',
                'github-releases.githubusercontent.com',
                'release-assets.githubusercontent.com',
                'codeload.github.com',
            );
            if (!in_array($host, $allowed, true)) {
                return new WP_Error('saw_gh_bad_host', '只支持从 github.com 下载 Release 文件。');
            }
            return $url;
        }

        /* ------------------------------------------------------------------
         * 六、保存路径
         * --------------------------------------------------------------- */

        public function root_dir() {
            $root = trim((string) get_option('saw_gh_root', ''));
            if ($root === '') {
                $root = WP_CONTENT_DIR . '/uploads/saw-github';
            }
            $root = str_replace('\\', '/', $root);
            $root = rtrim($root, '/');
            // 相对路径按 WordPress 根目录解析，避免解析成 PHP 进程的当前目录。
            if (!preg_match('#^([A-Za-z]:)?/#', $root)) {
                $root = rtrim(str_replace('\\', '/', ABSPATH), '/') . '/' . ltrim($root, '/');
            }
            return $root;
        }

        /**
         * 净化子目录：逐段过滤，拒绝 ..，保留中文和常见符号。
         */
        public static function safe_subdir($subdir) {
            $subdir = str_replace('\\', '/', (string) $subdir);
            if (function_exists('SAW_Quality')) {
                $subdir = SAW_Quality::utf8_clean($subdir);
            }
            $segments = array();
            foreach (explode('/', $subdir) as $segment) {
                $segment = trim($segment);
                if ($segment === '' || $segment === '.' || $segment === '..') {
                    continue;
                }
                $segment = preg_replace('/[^\p{L}\p{N}._\- ]/u', '', $segment);
                if (!is_string($segment)) {
                    continue;
                }
                $segment = trim($segment, ". \t");
                $segment = preg_replace('/\s+/', ' ', $segment);
                if ($segment === '' || $segment === '.' || $segment === '..') {
                    continue;
                }
                if (function_exists('mb_substr')) {
                    $segment = mb_substr($segment, 0, 80, 'UTF-8');
                } else {
                    $segment = substr($segment, 0, 120);
                }
                $segments[] = $segment;
                if (count($segments) >= 6) {
                    break;
                }
            }
            return implode('/', $segments);
        }

        /**
         * 资产文件名净化：只留基名，去掉控制字符和路径分隔符。
         */
        public static function safe_filename($name) {
            // 先取基名：'../../etc/passwd' 这种带路径的输入只留最后一段。
            $name = str_replace('\\', '/', (string) $name);
            $slash = strrpos($name, '/');
            if ($slash !== false) {
                $name = substr($name, $slash + 1);
            }
            if (function_exists('SAW_Quality')) {
                $name = SAW_Quality::utf8_clean($name);
            }
            $name = preg_replace('/[\x00-\x1F\x7F]/', '', $name);
            if (!is_string($name)) {
                $name = '';
            }
            $name = preg_replace('/[<>:"|?*]/', '_', $name);
            if (!is_string($name)) {
                $name = '';
            }
            $name = trim($name);
            // 去掉开头的点，避免生成 '.htaccess' 这类隐藏文件覆盖站点配置。
            $name = ltrim($name, '. ');
            if (function_exists('mb_substr')) {
                $name = mb_substr($name, 0, 160, 'UTF-8');
            } else {
                $name = substr($name, 0, 200);
            }
            $name = trim($name);
            return $name === '' ? 'download.bin' : $name;
        }

        /**
         * 把绝对路径转成可访问的 URL；纯服务器路径（站外）返回空。
         */
        public function public_url($path) {
            $content = str_replace('\\', '/', WP_CONTENT_DIR);
            $path = str_replace('\\', '/', (string) $path);
            if (strpos($path, $content . '/') !== 0) {
                return '';
            }
            $relative = substr($path, strlen($content) + 1);
            $segments = array_map('rawurlencode', explode('/', $relative));
            return content_url(implode('/', $segments));
        }

        /* ------------------------------------------------------------------
         * 七、下载任务
         * --------------------------------------------------------------- */

        /**
         * 创建下载任务（准备阶段）。
         *
         * 只做三件事：探测线路、建目录、算出分片大小。真正的字节下载在 fetch_part()。
         *
         * @return array|WP_Error
         */
        public function prepare($url, $filename, $size_hint, $subdir, $part_count = 0) {
            $probe = $this->probe($url);
            if (is_wp_error($probe)) {
                return $probe;
            }

            $size = (int) $probe['size'];
            if ($size <= 0) {
                $size = (int) $size_hint;
            }
            $max_bytes = $this->max_bytes();
            if ($size > 0 && $size > $max_bytes) {
                return new WP_Error(
                    'saw_gh_too_large',
                    '这个文件有 ' . self::human_size($size) . '，超过单文件上限 ' . self::human_size($max_bytes)
                    . '。可以在“接口设置 → GitHub 下载”里调高上限。'
                );
            }

            $filename = self::safe_filename($filename);
            $subdir = self::safe_subdir($subdir);
            $root = $this->root_dir();
            $dir = $subdir === '' ? $root : $root . '/' . $subdir;

            if (!$this->ensure_dir($dir)) {
                return new WP_Error('saw_gh_mkdir', '无法创建保存目录：' . $dir . '。请检查这个路径是否可写。');
            }
            if (!is_writable($dir)) {
                return new WP_Error('saw_gh_unwritable', '保存目录不可写：' . $dir . '。请到服务器上给这个目录写权限（一般 755，属主为运行 PHP 的用户）。');
            }

            // 同名文件已存在时加序号，不覆盖用户之前下的东西。
            $final = $dir . '/' . $filename;
            $suffix = 1;
            while (file_exists($final) && !$this->is_resumable_job_for($final)) {
                $dot = strrpos($filename, '.');
                $base = $dot === false ? $filename : substr($filename, 0, $dot);
                $ext = $dot === false ? '' : substr($filename, $dot);
                $final = $dir . '/' . $base . '-' . $suffix . $ext;
                $suffix++;
                if ($suffix > 50) {
                    break;
                }
            }

            $parts = $this->decide_parts($size, $probe['range'], $part_count);
            $part_size = $parts > 1 ? (int) ceil($size / $parts) : 0;

            $job_id = $this->new_job_id();
            $job = array(
                'id' => $job_id,
                'url' => $probe['url'],
                'route' => $probe['route'],
                'route_index' => isset($probe['route_index']) ? (int) $probe['route_index'] : 0,
                'route_label' => $probe['label'],
                'filename' => basename($final),
                'final' => $final,
                'dir' => $dir,
                'subdir' => $subdir,
                'size' => $size,
                'parts' => $parts,
                'part_size' => $part_size,
                'range' => $probe['range'],
                'status' => 'pending',
                'created' => time(),
                'user' => get_current_user_id(),
            );
            if (!$this->save_job($job)) {
                return new WP_Error('saw_gh_job_write', '无法写入下载任务文件，请检查 .saw-jobs 目录的写权限。');
            }

            // 预创建分片文件，让前端一上来就能看到 0 字节的进度，而不是“什么都没发生”。
            $this->part_paths($job);

            // 把探测时选中的线路记下来，所有分片直接从这条线开始，
            // 避免每个分片都先撞一次慢的直连。
            if (isset($probe['route_index'])) {
                $this->remember_route($job, (int) $probe['route_index']);
            }

            return array(
                'job' => $job,
                'url' => $this->public_url($final),
                'dir' => $dir,
                'root' => $root,
            );
        }

        private function is_resumable_job_for($final) {
            $jobs = $this->all_jobs();
            foreach ($jobs as $job) {
                if (isset($job['final']) && $job['final'] === $final && $job['status'] !== 'done') {
                    return true;
                }
            }
            return false;
        }

        private function decide_parts($size, $range_ok, $wanted) {
            if (!$range_ok || $size <= 0) {
                return 1;
            }
            $wanted = (int) $wanted;
            if ($wanted > 0) {
                return max(1, min(self::MAX_PARTS, $wanted));
            }
            $configured = (int) get_option('saw_gh_parts', 3);
            $configured = max(1, min(self::MAX_PARTS, $configured));
            // 小文件切成多片反而更慢（每片都有握手开销）。
            if ($size < 8 * 1048576) {
                return 1;
            }
            $parts = $configured;
            // 保证每片不小于 256 KB，也不大于 4 MB。
            while ($parts > 1 && ($size / $parts) < self::PART_MIN) {
                $parts--;
            }
            if (($size / $parts) > self::PART_MAX_BYTES) {
                $parts = (int) ceil($size / self::PART_MAX_BYTES);
                $parts = min($parts, self::MAX_PARTS);
            }
            return max(1, $parts);
        }

        public function max_bytes() {
            $mb = (int) get_option('saw_gh_max_mb', 512);
            $mb = max(1, min(4096, $mb));
            return $mb * 1048576;
        }

        private function new_job_id() {
            if (function_exists('wp_generate_password')) {
                return substr(preg_replace('/[^a-z0-9]/i', '', wp_generate_password(20, false)), 0, 16);
            }
            return substr(md5(uniqid('', true)), 0, 16);
        }

        private function job_dir_path() {
            return $this->root_dir() . '/' . self::JOB_DIR;
        }

        private function job_path($job_id) {
            $job_id = preg_replace('/[^a-z0-9]/i', '', (string) $job_id);
            if ($job_id === '') {
                return '';
            }
            return $this->job_dir_path() . '/' . $job_id . '.json';
        }

        public function save_job($job) {
            $dir = $this->job_dir_path();
            if (!$this->ensure_dir($dir)) {
                return false;
            }
            $path = $this->job_path(isset($job['id']) ? $job['id'] : '');
            if ($path === '') {
                return false;
            }
            $json = wp_json_encode($job);
            if (!is_string($json)) {
                return false;
            }
            return file_put_contents($path, $json, LOCK_EX) !== false;
        }

        public function load_job($job_id) {
            $path = $this->job_path($job_id);
            if ($path === '' || !is_readable($path)) {
                return null;
            }
            $raw = file_get_contents($path);
            if (!is_string($raw) || $raw === '') {
                return null;
            }
            $job = json_decode($raw, true);
            if (!is_array($job) || empty($job['id'])) {
                return null;
            }
            return $job;
        }

        /**
         * 读取所有未完成任务，用于“上次没下完，继续”的提示。
         */
        public function all_jobs() {
            $dir = $this->job_dir_path();
            if (!is_dir($dir)) {
                return array();
            }
            $jobs = array();
            $entries = @scandir($dir);
            if (!is_array($entries)) {
                return array();
            }
            foreach ($entries as $entry) {
                if (substr($entry, -5) !== '.json') {
                    continue;
                }
                $job = $this->load_job(substr($entry, 0, -5));
                if ($job === null) {
                    continue;
                }
                $job['progress'] = $this->job_progress($job);
                $jobs[] = $job;
            }
            usort($jobs, function ($a, $b) {
                return (int) $b['created'] - (int) $a['created'];
            });
            return $jobs;
        }

        public function part_paths($job) {
            $paths = array();
            $count = max(1, (int) $job['parts']);
            for ($i = 0; $i < $count; $i++) {
                $paths[] = $job['dir'] . '/.' . $job['filename'] . '.sawpart' . $i;
            }
            // 顺手建出来，这样进度查询能立刻返回 0，而不是“文件不存在”。
            foreach ($paths as $path) {
                if (!file_exists($path)) {
                    @touch($path);
                }
            }
            return $paths;
        }

        /**
         * 计算每个分片的字节区间：array(array('start','end','length'), ...)
         */
        public function part_ranges($job) {
            $ranges = array();
            $total = (int) $job['size'];
            $parts = max(1, (int) $job['parts']);
            if ($parts === 1 || $total <= 0) {
                $ranges[] = array('start' => 0, 'end' => $total > 0 ? $total - 1 : null, 'length' => $total);
                return $ranges;
            }
            $chunk = (int) ceil($total / $parts);
            for ($i = 0; $i < $parts; $i++) {
                $start = $i * $chunk;
                if ($start >= $total) {
                    break;
                }
                $end = min($start + $chunk - 1, $total - 1);
                $ranges[] = array('start' => $start, 'end' => $end, 'length' => $end - $start + 1);
            }
            return $ranges;
        }

        /**
         * 下载一个分片。可以重复调用：已下载的部分会跳过，实现断点续传。
         *
         * @return array|WP_Error array('index','received','length','done','speed')
         */
        public function fetch_part($job, $index) {
            $index = (int) $index;
            $ranges = $this->part_ranges($job);
            if (!isset($ranges[$index])) {
                return new WP_Error('saw_gh_bad_part', '分片编号超出范围。');
            }
            $range = $ranges[$index];
            $paths = $this->part_paths($job);
            $path = $paths[$index];

            $have = 0;
            if (file_exists($path)) {
                clearstatcache(true, $path);
                $have = (int) filesize($path);
            }
            $length = (int) $range['length'];
            if ($length > 0 && $have >= $length) {
                return array(
                    'index' => $index,
                    'received' => $have,
                    'length' => $length,
                    'done' => true,
                    'speed' => 0,
                );
            }

            // 单分片模式且服务端不支持 Range：一次整包写入。
            if ($job['parts'] === 1 && !$job['range']) {
                return $this->fetch_whole($job);
            }

            $start = (int) $range['start'] + $have;
            $end = $range['end'];
            $timeout = max(15, min(180, (int) get_option('saw_gh_timeout', 45)));
            $routes = $this->routes();
            $preferred = $this->preferred_route_index($job, $routes);

            $last_error = '';
            for ($attempt = 0; $attempt < self::RETRY_PER_PART; $attempt++) {
                $route_index = ($preferred + $attempt) % max(1, count($routes));
                $route = isset($routes[$route_index]) ? $routes[$route_index] : '';
                $target = $this->build_url($job['url'], $route);
                $result = $this->fetch($target, $start, $end, $timeout);

                if (!$result['ok']) {
                    $last_error = ($route === '' ? '直连' : $route) . '：' . $result['error'];
                    $this->mark_route_failed($job, $route_index);
                    continue;
                }
                $status = (int) $result['status'];
                if ($status === 416) {
                    // 请求的区间超出文件大小：文件可能比探测时短，按已完整处理。
                    return array('index' => $index, 'received' => $have, 'length' => $length, 'done' => true, 'speed' => 0);
                }
                if ($status !== 206 && $status !== 200) {
                    $last_error = ($route === '' ? '直连' : $route) . '：HTTP ' . $status;
                    $this->mark_route_failed($job, $route_index);
                    continue;
                }
                $type = isset($result['headers']['content-type']) ? strtolower($result['headers']['content-type']) : '';
                if (strpos($type, 'text/html') !== false) {
                    $last_error = ($route === '' ? '直连' : $route) . '：返回了网页而不是文件';
                    $this->mark_route_failed($job, $route_index);
                    continue;
                }
                if ($status === 200 && $start > 0) {
                    // 中途不认 Range 了，不能把整包当成分片写进去，否则文件会错位。
                    $last_error = ($route === '' ? '直连' : $route) . '：不支持断点续传';
                    $this->mark_route_failed($job, $route_index);
                    continue;
                }

                $written = $this->append_bytes($path, $result['body']);
                if ($written === false) {
                    return new WP_Error('saw_gh_write', '写入文件失败，请检查保存目录的写权限和磁盘空间。');
                }
                $this->remember_route($job, $route_index);

                $received = min($length, $have + $written);
                $speed = $result['time'] > 0 ? ($written / $result['time']) : 0;
                return array(
                    'index' => $index,
                    'received' => $received,
                    'length' => $length,
                    'done' => ($length <= 0) ? false : ($received >= $length),
                    'speed' => (int) $speed,
                    'route' => $route === '' ? '直连' : $route,
                );
            }

            return new WP_Error('saw_gh_part_failed', '第 ' . ($index + 1) . ' 片下载失败：' . $last_error);
        }

        /**
         * 整包下载（服务端不支持 Range 时）。
         *
         * 这条路径会把整个文件读进内存，所以必须先卡住大小：
         * 主机常见的 memory_limit 是 128-256MB，读一个 300MB 的包会直接白屏。
         */
        private function fetch_whole($job) {
            $path = $job['dir'] . '/.' . $job['filename'] . '.sawpart0';
            $size = (int) $job['size'];
            $memory_safe = 64 * 1048576;
            if ($size > $memory_safe) {
                return new WP_Error(
                    'saw_gh_no_range',
                    '这条线路不支持断点续传，而文件有 ' . self::human_size($size)
                    . '，整包读取会撑爆 PHP 内存。请换一条线路重试，或在设置里调大分片数。'
                );
            }
            if ($size <= 0) {
                // 大小未知时不能盲目整包读取，宁可报错也不冒白屏的风险。
                return new WP_Error('saw_gh_no_size', '无法确认文件大小，这条线路又只支持整包下载，已停止。请换一条线路重试。');
            }

            $routes = $this->routes();
            $timeout = max(30, min(300, (int) get_option('saw_gh_timeout', 45) * 4));
            $last_error = '';
            foreach ($routes as $index => $route) {
                $target = $this->build_url($job['url'], $route);
                $result = $this->fetch($target, null, null, $timeout, $memory_safe + 1);
                if (!$result['ok']) {
                    $last_error = ($route === '' ? '直连' : $route) . '：' . $result['error'];
                    continue;
                }
                $written = $this->append_bytes($path, $result['body'], true);
                if ($written === false) {
                    return new WP_Error('saw_gh_write', '写入文件失败，请检查保存目录的写权限和磁盘空间。');
                }
                return array(
                    'index' => 0,
                    'received' => $written,
                    'length' => $written,
                    'done' => true,
                    'speed' => $result['time'] > 0 ? (int) ($written / $result['time']) : 0,
                    'route' => $route === '' ? '直连' : $route,
                );
            }
            return new WP_Error('saw_gh_whole_failed', '下载失败：' . $last_error);
        }

        /**
         * 追加写入。$truncate 为真时先清空（整包模式重下时用）。
         */
        private function append_bytes($path, $bytes, $truncate = false) {
            if (!is_string($bytes)) {
                return false;
            }
            if ($bytes === '') {
                return 0;
            }
            $handle = @fopen($path, $truncate ? 'wb' : 'ab');
            if ($handle === false) {
                return false;
            }
            $length = strlen($bytes);
            $offset = 0;
            while ($offset < $length) {
                $written = fwrite($handle, substr($bytes, $offset));
                if ($written === false || $written === 0) {
                    fclose($handle);
                    return false;
                }
                $offset += $written;
            }
            fflush($handle);
            fclose($handle);
            clearstatcache(true, $path);
            return $offset;
        }

        /**
         * 线路偏好记录：所有分片共享，省掉每片都先撞一次坏线路。
         */
        private function route_hint_path() {
            return $this->job_dir_path() . '/.route-hint';
        }

        private function preferred_route_index($job, $routes) {
            $path = $this->route_hint_path();
            $index = 0;
            if (is_readable($path)) {
                $raw = trim((string) file_get_contents($path));
                if ($raw !== '' && ctype_digit($raw)) {
                    $index = (int) $raw;
                }
            }
            if ($index < 0 || $index >= count($routes)) {
                $index = 0;
            }
            return $index;
        }

        private function remember_route($job, $index) {
            $this->ensure_dir($this->job_dir_path());
            @file_put_contents($this->route_hint_path(), (string) (int) $index, LOCK_EX);
        }

        private function mark_route_failed($job, $index) {
            $routes = $this->routes();
            $next = ((int) $index + 1) % max(1, count($routes));
            $this->ensure_dir($this->job_dir_path());
            @file_put_contents($this->route_hint_path(), (string) $next, LOCK_EX);
        }

        /**
         * 计算进度：直接读分片文件的实际大小，不依赖任何状态变量。
         */
        public function job_progress($job) {
            $total = (int) $job['size'];
            $received = 0;
            $paths = $this->part_paths($job);
            foreach ($paths as $path) {
                if (file_exists($path)) {
                    clearstatcache(true, $path);
                    $received += (int) filesize($path);
                }
            }
            // 已经合并完成的，直接按最终文件算。
            if (isset($job['final']) && file_exists($job['final'])) {
                clearstatcache(true, $job['final']);
                $final_size = (int) filesize($job['final']);
                if ($final_size > 0) {
                    $received = $final_size;
                    if ($total <= 0) {
                        $total = $final_size;
                    }
                }
            }
            if ($total > 0 && $received > $total) {
                $received = $total;
            }
            $percent = $total > 0 ? min(100, (int) floor($received * 100 / $total)) : 0;
            return array(
                'received' => $received,
                'total' => $total,
                'percent' => $percent,
                'received_text' => self::human_size($received),
                'total_text' => self::human_size($total),
                'parts_done' => $this->count_done_parts($job),
                'parts' => max(1, (int) $job['parts']),
            );
        }

        private function count_done_parts($job) {
            $ranges = $this->part_ranges($job);
            $paths = $this->part_paths($job);
            $done = 0;
            foreach ($ranges as $index => $range) {
                $length = (int) $range['length'];
                if ($length <= 0) {
                    continue;
                }
                if (!isset($paths[$index]) || !file_exists($paths[$index])) {
                    continue;
                }
                clearstatcache(true, $paths[$index]);
                if ((int) filesize($paths[$index]) >= $length) {
                    $done++;
                }
            }
            return $done;
        }

        public function job_complete($job) {
            $progress = $this->job_progress($job);
            if ($progress['total'] <= 0) {
                return false;
            }
            return $progress['received'] >= $progress['total'];
        }

        /**
         * 合并分片成最终文件。
         *
         * @return array|WP_Error
         */
        public function merge($job) {
            $ranges = $this->part_ranges($job);
            $paths = $this->part_paths($job);

            // 先核对每个分片是否都下满了，避免把残片合进成品。
            foreach ($ranges as $index => $range) {
                $length = (int) $range['length'];
                if ($length <= 0) {
                    continue;
                }
                $path = $paths[$index];
                clearstatcache(true, $path);
                $have = file_exists($path) ? (int) filesize($path) : 0;
                if ($have < $length) {
                    return new WP_Error(
                        'saw_gh_incomplete',
                        '第 ' . ($index + 1) . ' 片还没下完（' . self::human_size($have) . ' / ' . self::human_size($length) . '），请稍后重试。'
                    );
                }
            }

            $final = $job['final'];
            $temp = $final . '.sawtmp';
            $out = @fopen($temp, 'wb');
            if ($out === false) {
                return new WP_Error('saw_gh_merge_open', '无法创建目标文件：' . $temp);
            }
            foreach ($paths as $index => $path) {
                if (!file_exists($path)) {
                    continue;
                }
                $in = @fopen($path, 'rb');
                if ($in === false) {
                    fclose($out);
                    @unlink($temp);
                    return new WP_Error('saw_gh_merge_read', '读取分片文件失败：' . $path);
                }
                $copied = stream_copy_to_stream($in, $out);
                fclose($in);
                if ($copied === false) {
                    fclose($out);
                    @unlink($temp);
                    return new WP_Error('saw_gh_merge_copy', '合并文件时出错，可能是磁盘空间不足。');
                }
            }
            fclose($out);

            // 单分片整包下载时，个别镜像会忽略 Range 把整个文件重复写进来。
            // 这种情况按探测到的大小截断；分片模式下一律不允许长度不符，宁可报错也不交付坏文件。
            $expected = (int) $job['size'];
            clearstatcache(true, $temp);
            $actual = (int) filesize($temp);
            if ($expected > 0 && $actual > $expected && (int) $job['parts'] === 1) {
                $handle = @fopen($temp, 'r+b');
                if ($handle !== false) {
                    ftruncate($handle, $expected);
                    fclose($handle);
                    clearstatcache(true, $temp);
                    $actual = (int) filesize($temp);
                }
            }
            if ($expected > 0 && $actual !== $expected) {
                @unlink($temp);
                return new WP_Error(
                    'saw_gh_size_mismatch',
                    '合并后的大小不对（期望 ' . self::human_size($expected) . '，实际 ' . self::human_size($actual) . '），已删除临时文件。请重新下载。'
                );
            }

            if (file_exists($final)) {
                @unlink($final);
            }
            if (!@rename($temp, $final)) {
                @unlink($temp);
                return new WP_Error('saw_gh_rename', '无法把文件移动到最终位置，请检查目录权限。');
            }

            foreach ($paths as $path) {
                @unlink($path);
            }
            clearstatcache(true, $final);

            $job['status'] = 'done';
            $job['finished'] = time();
            $this->save_job($job);

            return array(
                'job_id' => $job['id'],
                'file' => $final,
                'filename' => $job['filename'],
                'dir' => $job['dir'],
                'size' => $actual,
                'size_text' => self::human_size($actual),
                'url' => $this->public_url($final),
                'relative' => $this->relative_to_root($final),
            );
        }

        private function relative_to_root($path) {
            $root = $this->root_dir() . '/';
            $path = str_replace('\\', '/', (string) $path);
            if (strpos($path, $root) === 0) {
                return substr($path, strlen($root));
            }
            return $path;
        }

        /**
         * 放弃任务：删掉分片和任务文件，已合并的成品保留。
         */
        public function abort($job) {
            $paths = $this->part_paths($job);
            foreach ($paths as $path) {
                @unlink($path);
            }
            $job_path = $this->job_path($job['id']);
            if ($job_path !== '') {
                @unlink($job_path);
            }
            return true;
        }

        /**
         * 清理超过 24 小时的未完成任务（分片文件和任务记录）。
         */
        public function cleanup_stale() {
            $jobs = $this->all_jobs();
            $removed = 0;
            foreach ($jobs as $job) {
                if ($job['status'] === 'done') {
                    // 已完成的任务记录只留 3 天，避免 .saw-jobs 越堆越多。
                    if (isset($job['finished']) && (time() - (int) $job['finished']) > 259200) {
                        $path = $this->job_path($job['id']);
                        if ($path !== '' && @unlink($path)) {
                            $removed++;
                        }
                    }
                    continue;
                }
                if ((time() - (int) $job['created']) > 86400) {
                    $this->abort($job);
                    $removed++;
                }
            }
            return $removed;
        }

        /* ------------------------------------------------------------------
         * 八、辅助
         * --------------------------------------------------------------- */

        public function ensure_dir($dir) {
            if (is_dir($dir)) {
                return true;
            }
            if (!function_exists('wp_mkdir_p')) {
                return @mkdir($dir, 0755, true);
            }
            return (bool) wp_mkdir_p($dir);
        }

        /**
         * 目录里已经下载好的文件（用于弹窗底部展示）。
         */
        public function list_dir($dir, $limit = 40) {
            $dir = str_replace('\\', '/', (string) $dir);
            $root = $this->root_dir();
            // 只允许看下载根目录以内的内容。
            if (strpos($dir . '/', $root . '/') !== 0) {
                return array();
            }
            if (!is_dir($dir)) {
                return array();
            }
            $entries = @scandir($dir);
            if (!is_array($entries)) {
                return array();
            }
            $files = array();
            foreach ($entries as $entry) {
                if ($entry === '.' || $entry === '..') {
                    continue;
                }
                $path = $dir . '/' . $entry;
                if (!is_file($path)) {
                    continue;
                }
                clearstatcache(true, $path);
                $files[] = array(
                    'name' => $entry,
                    'size' => (int) filesize($path),
                    'size_text' => self::human_size((int) filesize($path)),
                    'time' => (int) filemtime($path),
                    'url' => $this->public_url($path),
                );
                if (count($files) >= $limit) {
                    break;
                }
            }
            usort($files, function ($a, $b) {
                return $b['time'] - $a['time'];
            });
            return $files;
        }

        /**
         * 默认保存子目录：仓库名/版本号，可选再按系统分子目录。
         */
        public static function suggest_subdir($repo, $tag, $os, $by_os) {
            $segments = array();
            if ($repo !== '') {
                $segments[] = $repo;
            }
            if ($tag !== '') {
                $segments[] = $tag;
            }
            if ($by_os && $os !== '' && $os !== 'other') {
                $labels = array(
                    'windows' => 'Windows',
                    'macos' => 'macOS',
                    'linux' => 'Linux',
                    'android' => 'Android',
                    'ios' => 'iOS',
                    'source' => '源码',
                );
                if (isset($labels[$os])) {
                    $segments[] = $labels[$os];
                }
            }
            return self::safe_subdir(implode('/', $segments));
        }
    }
}

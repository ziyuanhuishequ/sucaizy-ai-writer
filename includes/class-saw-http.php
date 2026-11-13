<?php
/**
 * 并发 HTTP 请求工具：把网页抓取、GitHub API、参考检索合并成一轮并发请求，
 * 避免原先串行等待（最坏情况要等 50 秒以上）造成的“生成很慢”。
 *
 * 优先使用 curl_multi；服务器没有 curl 时自动退回 WordPress 的串行请求。
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!class_exists('SAW_HTTP', false)) {
    class SAW_HTTP {
        const DEFAULT_UA = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/124.0 Safari/537.36';

        /**
         * 并发执行一组请求。
         *
         * @param array $specs key => array(
         *     'url'     => 必填,
         *     'method'  => 'GET' | 'POST',
         *     'headers' => array,
         *     'body'    => POST 内容,
         *     'timeout' => 秒,
         *     'limit'   => 响应体最大字节数,
         * )
         * @return array key => array('ok'=>bool,'code'=>int,'body'=>string,'error'=>string)
         */
        public static function request_many($specs) {
            $results = array();
            if (empty($specs) || !is_array($specs)) {
                return $results;
            }
            // 站点禁用外部请求时，仍然交给 WordPress 判断（保持原有安全策略）。
            $blocked = defined('WP_HTTP_BLOCK_EXTERNAL') && WP_HTTP_BLOCK_EXTERNAL;
            if (!$blocked && function_exists('curl_multi_init') && function_exists('curl_init')) {
                $results = self::curl_many($specs);
            } else {
                foreach ($specs as $key => $spec) {
                    $results[$key] = self::single($spec);
                }
                return $results;
            }
            // curl 在个别服务器上缺少 CA 或走不了代理时，退回 WordPress 的请求实现。
            foreach ($results as $key => $result) {
                if (!empty($result['ok']) || !self::is_transport_error($result)) {
                    continue;
                }
                $retry = self::single($specs[$key]);
                if (!empty($retry['ok'])) {
                    $results[$key] = $retry;
                }
            }
            return $results;
        }

        /**
         * 单次请求（WordPress 内置实现）。
         */
        public static function single($raw) {
            $spec = self::normalize($raw);
            if ($spec['url'] === '' || !wp_http_validate_url($spec['url'])) {
                return self::failure('无效的请求地址');
            }
            $headers = $spec['headers'];
            if (!self::has_header($headers, 'user-agent')) {
                $headers['User-Agent'] = self::DEFAULT_UA;
            }
            $args = array(
                'timeout' => $spec['timeout'],
                'redirection' => 3,
                'limit_response_size' => $spec['limit'],
                'headers' => $headers,
                'method' => $spec['method'],
            );
            if ($spec['method'] === 'POST') {
                $args['body'] = $spec['body'];
                $args['data_format'] = 'body';
            }
            $response = wp_safe_remote_request($spec['url'], $args);
            if (is_wp_error($response)) {
                return self::failure($response->get_error_message());
            }
            $code = (int) wp_remote_retrieve_response_code($response);
            $body = (string) wp_remote_retrieve_body($response);
            if ($code < 200 || $code >= 300) {
                return array(
                    'ok' => false,
                    'code' => $code,
                    'body' => '',
                    'error' => 'HTTP ' . $code,
                );
            }
            return array(
                'ok' => true,
                'code' => $code,
                'body' => self::trim_body($body, $spec['limit']),
                'error' => '',
            );
        }

        private static function curl_many($specs) {
            $multi = curl_multi_init();
            $handles = array();
            $results = array();
            if ($multi === false) {
                // 极少数主机编译时禁用了 curl_multi，退回串行请求，别让整个生成流程中断。
                foreach ($specs as $key => $spec) {
                    $results[$key] = self::single($spec);
                }
                return $results;
            }

            foreach ($specs as $key => $raw) {
                $spec = self::normalize($raw);
                $results[$key] = self::failure('');
                if ($spec['url'] === '' || !wp_http_validate_url($spec['url'])) {
                    $results[$key]['error'] = '无效的请求地址';
                    continue;
                }
                $handle = curl_init();
                if ($handle === false) {
                    $results[$key]['error'] = '无法初始化请求';
                    continue;
                }
                $headers = array();
                foreach ($spec['headers'] as $name => $value) {
                    $headers[] = $name . ': ' . $value;
                }
                $options = array(
                    CURLOPT_URL => $spec['url'],
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_HEADER => false,
                    CURLOPT_FOLLOWLOCATION => true,
                    CURLOPT_MAXREDIRS => 3,
                    CURLOPT_CONNECTTIMEOUT => min(10, $spec['timeout']),
                    CURLOPT_TIMEOUT => $spec['timeout'],
                    CURLOPT_SSL_VERIFYPEER => true,
                    CURLOPT_SSL_VERIFYHOST => 2,
                    CURLOPT_ENCODING => '',
                    CURLOPT_HTTPHEADER => $headers,
                );
                if (!self::has_header($spec['headers'], 'user-agent')) {
                    $options[CURLOPT_USERAGENT] = self::DEFAULT_UA;
                }
                if (defined('WP_PROXY_HOST') && WP_PROXY_HOST) {
                    $proxy = WP_PROXY_HOST;
                    if (defined('WP_PROXY_PORT') && WP_PROXY_PORT) {
                        $proxy .= ':' . WP_PROXY_PORT;
                    }
                    $options[CURLOPT_PROXY] = $proxy;
                    if (defined('WP_PROXY_USERNAME') && WP_PROXY_USERNAME) {
                        $options[CURLOPT_PROXYUSERPWD] = WP_PROXY_USERNAME . ':' . (defined('WP_PROXY_PASSWORD') ? WP_PROXY_PASSWORD : '');
                    }
                }
                if ($spec['method'] === 'POST') {
                    $options[CURLOPT_POST] = true;
                    $options[CURLOPT_POSTFIELDS] = $spec['body'];
                }
                curl_setopt_array($handle, $options);
                curl_multi_add_handle($multi, $handle);
                $handles[$key] = $handle;
            }

            if (empty($handles)) {
                curl_multi_close($multi);
                return $results;
            }

            $started = microtime(true);
            $hard_limit = 0;
            foreach ($specs as $spec) {
                $normalized = self::normalize($spec);
                $hard_limit = max($hard_limit, $normalized['timeout']);
            }
            $hard_limit += 5;

            while (true) {
                $spin = 0;
                do {
                    $status = curl_multi_exec($multi, $active);
                    $spin++;
                    // 个别 libcurl 版本会一直返回 CALL_MULTI_PERFORM，加个上限避免死循环。
                    if ($spin > 1000) {
                        break;
                    }
                } while ($status === CURLM_CALL_MULTI_PERFORM);

                if ($status !== CURLM_OK || $active <= 0) {
                    break;
                }
                // 兜底：个别主机的 DNS 卡住时 curl_multi_select 可能长时间不返回。
                if (microtime(true) - $started > $hard_limit) {
                    break;
                }
                $selected = curl_multi_select($multi, 1.0);
                if ($selected === -1) {
                    // 没有可等待的 fd，短暂休眠避免空转占满 CPU。
                    usleep(200000);
                }
            }

            foreach ($handles as $key => $handle) {
                $spec = self::normalize($specs[$key]);
                $body = (string) curl_multi_getcontent($handle);
                $code = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
                $errno = curl_errno($handle);
                $error = $errno ? curl_error($handle) : '';
                if ($errno) {
                    $results[$key] = array(
                        'ok' => false,
                        'code' => $code,
                        'body' => '',
                        'error' => $error !== '' ? $error : '网络请求失败',
                    );
                } elseif ($code < 200 || $code >= 300) {
                    $results[$key] = array(
                        'ok' => false,
                        'code' => $code,
                        'body' => '',
                        'error' => 'HTTP ' . $code,
                    );
                } else {
                    $results[$key] = array(
                        'ok' => true,
                        'code' => $code,
                        'body' => self::trim_body($body, $spec['limit']),
                        'error' => '',
                    );
                }
                curl_multi_remove_handle($multi, $handle);
                // PHP 8.5 起 curl_close 已废弃，早期版本仍需显式释放。
                if (PHP_VERSION_ID < 80500) {
                    curl_close($handle);
                }
            }
            curl_multi_close($multi);
            return $results;
        }

        private static function normalize($spec) {
            $headers = (isset($spec['headers']) && is_array($spec['headers'])) ? $spec['headers'] : array();
            $method = (isset($spec['method']) && strtoupper((string) $spec['method']) === 'POST') ? 'POST' : 'GET';
            $timeout = isset($spec['timeout']) ? (int) $spec['timeout'] : 15;
            $limit = isset($spec['limit']) ? (int) $spec['limit'] : 1048576;
            return array(
                'url' => isset($spec['url']) ? (string) $spec['url'] : '',
                'method' => $method,
                'headers' => $headers,
                'body' => isset($spec['body']) ? (string) $spec['body'] : '',
                'timeout' => max(1, min(120, $timeout)),
                'limit' => max(4096, $limit),
            );
        }

        private static function has_header($headers, $wanted) {
            foreach ($headers as $name => $value) {
                if (strtolower((string) $name) === $wanted) {
                    return true;
                }
            }
            return false;
        }

        private static function failure($message) {
            return array(
                'ok' => false,
                'code' => 0,
                'body' => '',
                'error' => $message,
            );
        }

        private static function is_transport_error($result) {
            $error = isset($result['error']) ? strtolower((string) $result['error']) : '';
            if ($error === '') {
                return false;
            }
            foreach (array('ssl', 'certificate', 'ca ', 'proxy', 'could not resolve', 'failed to connect', 'connection refused', 'timed out', 'timeout') as $needle) {
                if (strpos($error, $needle) !== false) {
                    return true;
                }
            }
            return false;
        }

        private static function trim_body($body, $limit) {
            $body = (string) $body;
            if ($limit > 0 && strlen($body) > $limit) {
                return substr($body, 0, $limit);
            }
            return $body;
        }
    }
}


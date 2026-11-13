<?php
/**
 * 质量校验与渲染层。
 *
 * 这一层负责把 AI 返回的结构化内容变成可发布文章，同时用代码硬性执行写作 Skill
 * 中的关键约束，避免“模型自己数不清字数”导致标题过长、软件特点写成一大段：
 *  - 标题：数量、长度带（约 43-67 字）、中文可读性、资源名收尾、禁用空洞夸张
 *  - 简介：100-180 字
 *  - 软件特点：5-6 条，每条含标签 35-45 字，超出按标点安全截断
 *  - 栏目顺序、三级标题、截图占位、下载地址
 *  - 事实校准提醒：缺少资料支持的平台、数字、免费/开源等表述
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!class_exists('SAW_Quality', false)) {
    class SAW_Quality {
        // 硬性边界：只拦截明显不合格的标题，避免“全被过滤”导致无标题可用。
        // 1.6.5 起标题整体加长：理想 43-67 字（多数 50-60 字），硬上限放到 78。
        const TITLE_HARD_MIN = 24;
        const TITLE_HARD_MAX = 78;
        // Skill 里的推荐区间，用于排序而不是硬性拦截。
        const TITLE_IDEAL_MIN = 43;
        const TITLE_IDEAL_MAX = 67;
        // 兼容旧常量名。
        const TITLE_MIN = 24;
        const TITLE_MAX = 78;
        const INTRO_MIN = 100;
        const INTRO_MAX = 180;
        const LABEL_MAX = 6;
        // Skill: 特点条目最低 30 字，35-45 是理想区间，45 是常规上限。
        const SKILL_FLOOR = 30;

        /* ---------------------------------------------------------------------
         * 基础文本工具
         * ------------------------------------------------------------------ */

        /**
         * 按字符数计数。优先 mbstring，服务器没装 mbstring 时用 PCRE 兜底，
         * 绝不退回 strlen（否则中文会按字节算成三倍长度）。
         */
        public static function len($text) {
            $text = self::scalar($text);
            if ($text === '') {
                return 0;
            }
            if (function_exists('mb_strlen')) {
                $count = mb_strlen($text, 'UTF-8');
                if ($count !== false) {
                    return (int) $count;
                }
            }
            $count = preg_match_all('/./us', $text, $matches);
            if ($count !== false) {
                return (int) $count;
            }
            // 非法 UTF-8 时 PCRE 直接失败，先清洗再数，别退回 strlen。
            $count = preg_match_all('/./us', self::utf8_clean($text), $matches);
            return $count === false ? strlen($text) : (int) $count;
        }

        /**
         * 按字符截断。mbstring 缺失、或用 PCRE 也失败时，按 UTF-8 字符边界逐字拼接，
         * 绝不使用裸 substr（那会把中文从半个字符处切断，也就是常说的乱码）。
         */
        public static function cut($text, $length) {
            $text = self::utf8_clean($text);
            $length = (int) $length;
            if ($length <= 0 || $text === '') {
                return '';
            }
            if (function_exists('mb_substr')) {
                $cut = mb_substr($text, 0, $length, 'UTF-8');
                if (is_string($cut)) {
                    return $cut;
                }
            }
            if (preg_match('/^.{0,' . $length . '}/us', $text, $matches)) {
                return (string) $matches[0];
            }
            return self::byte_safe_cut($text, $length);
        }

        /**
         * 不依赖 mbstring 的字符截断：按 UTF-8 首字节判断字符长度，逐字追加。
         */
        private static function byte_safe_cut($text, $chars) {
            $text = (string) $text;
            $chars = (int) $chars;
            $out = '';
            $count = 0;
            $length = strlen($text);
            $i = 0;
            while ($i < $length && $count < $chars) {
                $byte = ord($text[$i]);
                if ($byte < 0x80) {
                    $size = 1;
                } elseif ($byte >= 0xC2 && $byte <= 0xDF) {
                    $size = 2;
                } elseif ($byte >= 0xE0 && $byte <= 0xEF) {
                    $size = 3;
                } elseif ($byte >= 0xF0 && $byte <= 0xF4) {
                    $size = 4;
                } else {
                    $i++;
                    continue;
                }
                if ($i + $size > $length) {
                    break;
                }
                $out .= substr($text, $i, $size);
                $i += $size;
                $count++;
            }
            return $out;
        }

        /**
         * 统一把外部数据（模型返回、抓取的网页、数据库里的旧设置）清洗成合法 UTF-8。
         *
         * PHP 8 起，把非法 UTF-8 传给 preg_* / trim / str_* 会得到 null 并抛 deprecation；
         * 主机开着 display_errors 时这些提示会直接插进 AJAX 返回的 JSON，导致整个请求“无响应”。
         * 抓到 GBK 网页、模型输出被截断成半个字时都会走到这里，所以入口统一清洗一遍。
         */
        public static function utf8_clean($text) {
            $text = self::scalar($text);
            if ($text === '') {
                return '';
            }
            if (preg_match('//u', $text) === 1) {
                return $text;
            }
            if (function_exists('mb_convert_encoding')) {
                $converted = @mb_convert_encoding($text, 'UTF-8', 'UTF-8');
                if (is_string($converted) && preg_match('//u', $converted) === 1) {
                    return $converted;
                }
            }
            if (function_exists('iconv')) {
                $converted = @iconv('UTF-8', 'UTF-8//IGNORE', $text);
                if (is_string($converted) && preg_match('//u', $converted) === 1) {
                    return $converted;
                }
            }
            return self::rebuild_utf8($text);
        }

        /**
         * 逐字节重建合法 UTF-8：丢掉坏字节，保留能识别的字符。
         * 这是没有任何扩展可用时的最后一道兜底，保证输出永远是合法 UTF-8。
         */
        private static function rebuild_utf8($text) {
            $text = (string) $text;
            $out = '';
            $length = strlen($text);
            $i = 0;
            while ($i < $length) {
                $byte = ord($text[$i]);
                if ($byte < 0x80) {
                    $out .= $text[$i];
                    $i++;
                    continue;
                }
                if ($byte >= 0xC2 && $byte <= 0xDF) {
                    $need = 1;
                } elseif ($byte >= 0xE0 && $byte <= 0xEF) {
                    $need = 2;
                } elseif ($byte >= 0xF0 && $byte <= 0xF4) {
                    $need = 3;
                } else {
                    $i++;
                    continue;
                }
                if ($i + $need >= $length) {
                    break;
                }
                $valid = true;
                for ($k = 1; $k <= $need; $k++) {
                    $next = ord($text[$i + $k]);
                    if ($next < 0x80 || $next > 0xBF) {
                        $valid = false;
                        break;
                    }
                }
                if ($valid) {
                    $chunk = substr($text, $i, $need + 1);
                    if (preg_match('//u', $chunk) === 1) {
                        $out .= $chunk;
                    }
                }
                $i += $need + 1;
            }
            return $out;
        }

        private static function cjk_count($text) {
            return preg_match_all('/[\x{4e00}-\x{9fa5}]/u', (string) $text, $matches);
        }

        /**
         * 去掉结尾的指定字符（按字符，不按字节）。
         * PHP 自带 rtrim 的 charlist 是字节级的，传中文标点会把 UTF-8 字符截断成乱码，
         * 所以这里统一用字符级实现。
         */
        public static function strip_tail($text, $chars) {
            $text = self::utf8_clean($text);
            if ($text === '' || (string) $chars === '') {
                return $text;
            }
            $set = preg_split('//u', (string) $chars, -1, PREG_SPLIT_NO_EMPTY);
            $parts = preg_split('//u', $text, -1, PREG_SPLIT_NO_EMPTY);
            if (!is_array($set) || !is_array($parts)) {
                return $text;
            }
            while (!empty($parts) && in_array(end($parts), $set, true)) {
                array_pop($parts);
            }
            return implode('', $parts);
        }

        /**
         * 去掉开头的指定字符（按字符）。
         */
        public static function strip_head($text, $chars) {
            $text = self::utf8_clean($text);
            if ($text === '' || (string) $chars === '') {
                return $text;
            }
            $set = preg_split('//u', (string) $chars, -1, PREG_SPLIT_NO_EMPTY);
            $parts = preg_split('//u', $text, -1, PREG_SPLIT_NO_EMPTY);
            if (!is_array($set) || !is_array($parts)) {
                return $text;
            }
            while (!empty($parts) && in_array($parts[0], $set, true)) {
                array_shift($parts);
            }
            return implode('', $parts);
        }

        private static function latin_words($text) {
            preg_match_all('/[A-Za-z][A-Za-z0-9.+#-]{1,}/', (string) $text, $matches);
            return count($matches[0]);
        }

        /**
         * 清理中文排版：统一全角标点、去掉中文标点两侧空格、合并空白。
         * 文本里的链接原样保留，不会被改写成全角标点。
         */
        public static function normalize_text($text) {
            $text = trim(self::utf8_clean($text));
            if ($text === '') {
                return '';
            }
            $urls = array();
            $text = preg_replace_callback('~https?://[^\s<>"\']+~iu', function ($matches) use (&$urls) {
                $key = "\x01" . count($urls) . "\x02";
                $urls[$key] = $matches[0];
                return $key;
            }, $text);
            if (!is_string($text)) {
                // 极少数非法 UTF-8 会让 preg 返回 null，退回清洗后的原文，别把整段丢掉。
                $text = trim(self::utf8_clean($text));
            }

            $text = preg_replace('/\s+/u', ' ', $text);
            if (!is_string($text)) {
                $text = '';
            }
            // 数字之间的 , 和 : 是千分位和时刻（1,000 / 10:30），换成全角会变成“1，000”，
            // 读起来像排版乱了，所以只在非数字语境下转全角。
            $text = preg_replace('/(?<![0-9]),(?![0-9])/u', '，', $text);
            $text = preg_replace('/(?<![0-9]):(?![0-9])/u', '：', $text);
            if (!is_string($text)) {
                $text = '';
            }
            $text = str_replace(array(';', '?', '!'), array('；', '？', '！'), $text);
            $text = preg_replace('/\s*([，。；：！？、])\s*/u', '$1', $text);
            $text = preg_replace('/\s*([（【《])\s*/u', '$1', $text);
            $text = preg_replace('/\s*([）】》])\s*/u', '$1', $text);
            if (!is_string($text)) {
                $text = '';
            }

            if (!empty($urls)) {
                $text = strtr($text, $urls);
            }
            return trim($text);
        }

        /**
         * 把模型返回的任意字段安全地转成字符串。
         *
         * 模型偶尔会把本该是字符串的字段返回成数组（例如 "intro": ["第一段","第二段"]），
         * 直接 (string) 强转会得到字面量 "Array" 并写进正文，还会触发
         * "Array to string conversion" 警告污染 AJAX 的 JSON 返回。
         */
        public static function scalar($value) {
            if (is_scalar($value)) {
                return (string) $value;
            }
            if (is_array($value)) {
                // 数组就取第一个标量元素，尽量保留内容而不是丢掉整段。
                foreach ($value as $item) {
                    if (is_scalar($item) && trim((string) $item) !== '') {
                        return (string) $item;
                    }
                }
            }
            return '';
        }

        /* ---------------------------------------------------------------------
         * 标题
         * ------------------------------------------------------------------ */

        public static function risky_words($facts) {
            $open_source = !empty($facts['open_source']);
            $words = array('最好', '最强', '第一名', '神器', '必装', '必备神器', '震惊', '爆款', '无敌', '史上', '100%', '绝对', '全网', '永久免费');
            if (!$open_source) {
                $words[] = '开源';
            }
            if (empty($facts['price_verified']) || empty($facts['is_free'])) {
                $words[] = '免费';
            }
            return $words;
        }

        /**
         * 返回标题问题列表，空数组表示合格。
         *
         * 只做硬性拦截（空标题、严重过短/过长、不是中文、完全不提资源名）；
         * 43-67 字的理想区间由 score_title 排序体现，不在这里淘汰，
         * 否则模型偶尔写得短一点就会“一个标题都不剩”。
         */
        public static function title_issues($title, $product_name, $facts, $is_recommended = false) {
            $issues = array();
            $title = trim(self::scalar($title));
            if ($title === '') {
                return array('标题为空');
            }
            $length = self::len($title);
            if ($length > self::TITLE_HARD_MAX) {
                $issues[] = '超过 ' . self::TITLE_HARD_MAX . ' 字（当前 ' . $length . '）';
            } elseif ($length < self::TITLE_HARD_MIN) {
                $issues[] = '少于 ' . self::TITLE_HARD_MIN . ' 字（当前 ' . $length . '）';
            }
            $cjk = self::cjk_count($title);
            if ($cjk < 10) {
                $issues[] = '中文内容过少';
            } elseif (self::latin_words($title) > 8 && $cjk / max(1, $length) < 0.5) {
                $issues[] = '像英文标题，不是自然中文标题';
            }
            $name = trim(self::scalar($product_name));
            if ($name !== '') {
                if (stripos($title, $name) === false) {
                    $issues[] = '没有包含资源名称';
                } elseif (self::len($title) > self::len($name) + 4) {
                    // strripos 返回的是字节位置，标题长度是字符数，中文下必须换算，
                    // 否则这条“资源名不在结尾附近”的判断几乎永远不会触发。
                    $byte_pos = strripos($title, $name);
                    $char_pos = $byte_pos === false ? 0 : self::len(substr($title, 0, $byte_pos));
                    if ($char_pos < self::len($title) - self::len($name) - 20) {
                        $issues[] = '资源名称不在结尾附近';
                    }
                }
            }
            $turns = preg_match_all('/[！？]/u', $title, $unused);
            if ($turns > 3) {
                $issues[] = '感叹/问句超过 3 个，读起来太吵';
            }
            if (preg_match('/[。；]$/u', $title)) {
                $issues[] = '标题末尾有多余标点';
            }
            foreach (self::risky_words($facts) as $word) {
                if (strpos($title, $word) !== false) {
                    $issues[] = '缺少资料支持的说法“' . $word . '”';
                    break;
                }
            }
            if ($is_recommended && $name !== '' && stripos($title, $name) === false) {
                $issues[] = '推荐标题应包含资源名称';
            }
            return $issues;
        }

        /**
         * 标题质量分：越接近 Skill 理想区间、越像自然中文推荐，分数越高。
         */
        public static function score_title($title, $product_name, $facts) {
            $length = self::len($title);
            $score = 0;
            if ($length >= self::TITLE_IDEAL_MIN && $length <= self::TITLE_IDEAL_MAX) {
                $score += 40;
            } elseif ($length >= 34 && $length < self::TITLE_IDEAL_MIN) {
                $score += 20;
            } elseif ($length > self::TITLE_IDEAL_MAX && $length <= self::TITLE_HARD_MAX) {
                $score += 12;
            }
            $turns = preg_match_all('/[！？，：]/u', (string) $title, $unused);
            if ($turns >= 1 && $turns <= 2) {
                $score += 12;
            } elseif ($turns === 3) {
                $score += 6;
            }
            $name = trim(self::scalar($product_name));
            if ($name !== '' && stripos($title, $name) !== false) {
                $score += 20;
            }
            // 带具体场景词、收益词的标题更像真人推荐。
            if (preg_match('/用|把|让|省|不用|再也不用|一次|一键|自动|适合|整理|发现/u', $title)) {
                $score += 10;
            }
            // 空洞词扣分。
            if (preg_match('/神器|最强|必装|爆款|震惊|无敌/u', $title)) {
                $score -= 30;
            }
            return $score;
        }

        /**
         * 把标题裁进允许的长度，优先在标点处断开。
         */
        public static function fit_title($title, $product_name) {
            $title = self::normalize_text($title);
            $title = self::strip_tail($title, '，。；：、');
            $length = self::len($title);
            if ($length <= self::TITLE_HARD_MAX) {
                return $title;
            }
            $budget = self::TITLE_HARD_MAX - 1;
            $head = self::cut($title, $budget);
            $head = self::shorten_to_band($head, 20, $budget);
            $head = self::strip_tail($head, '，。；：、！？');
            if ($head === '') {
                $head = self::cut($title, $budget);
            }
            return $head;
        }

        /**
         * 在保留长度内尽量从标点处断开。
         */
        public static function trim_at_boundary($text, $floor) {
            $text = (string) $text;
            if (preg_match('/^(.*[，。；：、！？])[^，。；：、！？]*$/u', $text, $matches)) {
                if (self::len($matches[1]) >= $floor) {
                    return $matches[1];
                }
            }
            return $text;
        }

        /**
         * 选择最终展示的标题：去重、过滤、排序，返回 array('titles'=>[], 'recommended'=>'', 'warnings'=>[])
         */
        public static function select_titles($candidates, $recommended, $count, $product_name, $facts) {
            $count = max(3, min(10, (int) $count));
            $warnings = array();
            $valid = array();
            $seen = array();
            $recommended = self::normalize_text($recommended);

            foreach ($candidates as $candidate) {
                if (is_array($candidate)) {
                    // 模型偶尔会把标题包成对象，兼容一层。
                    $candidate = isset($candidate['title']) ? $candidate['title'] : (isset($candidate['text']) ? $candidate['text'] : '');
                }
                $title = is_scalar($candidate) ? self::normalize_text($candidate) : '';
                if ($title === '') {
                    continue;
                }
                $title = self::fit_title($title, $product_name);
                if ($title === '') {
                    continue;
                }
                $dedupe_key = preg_replace('/\s+/u', '', self::cut($title, 14));
                // 非法 UTF-8 时 preg_replace 会返回 null，isset($seen[null]) 等于
                // isset($seen[''])，会把后面所有正常标题都当成重复丢掉。
                if (!is_string($dedupe_key)) {
                    $dedupe_key = self::cut($title, 14);
                }
                if (isset($seen[$dedupe_key])) {
                    continue;
                }
                $seen[$dedupe_key] = true;
                $issues = self::title_issues($title, $product_name, $facts);
                if (!empty($issues)) {
                    $warnings[] = '已过滤标题“' . $title . '”：' . implode('、', $issues);
                    continue;
                }
                $valid[] = $title;
            }

            // 按质量分排序，同时把推荐标题提到最前。
            $scored = array();
            foreach ($valid as $title) {
                $scored[] = array(
                    'title' => $title,
                    'score' => self::score_title($title, $product_name, $facts) + ($title === $recommended ? 1000 : 0),
                );
            }
            usort($scored, array(__CLASS__, 'compare_scored'));

            $chosen = array();
            foreach ($scored as $item) {
                if (count($chosen) >= $count) {
                    break;
                }
                $chosen[] = $item['title'];
            }

            if (count($chosen) < $count) {
                $warnings[] = '这次只得到 ' . count($chosen) . ' 个合格标题，可以点“换一批标题”再挑选。';
            }
            if (count($chosen) < 2) {
                $warnings[] = '合格标题太少，建议在“写作 Skill”里放宽标题长度要求，或补充说明资源特点。';
            }

            $final_recommended = '';
            if ($recommended !== '' && in_array($recommended, $chosen, true)) {
                $final_recommended = $recommended;
            } elseif (!empty($chosen)) {
                $final_recommended = $chosen[0];
            }

            // 推荐标题如果没有包含资源名，优先换成第一个满足条件的候选。
            $name = trim(self::scalar($product_name));
            if ($final_recommended !== '' && $name !== '' && stripos($final_recommended, $name) === false) {
                foreach ($chosen as $title) {
                    if (stripos($title, $name) !== false) {
                        $final_recommended = $title;
                        break;
                    }
                }
            }

            return array(
                'titles' => $chosen,
                'recommended' => $final_recommended,
                'warnings' => $warnings,
            );
        }

        private static function compare_scored($a, $b) {
            if ($a['score'] === $b['score']) {
                return 0;
            }
            return $a['score'] > $b['score'] ? -1 : 1;
        }

        /* ---------------------------------------------------------------------
         * 软件特点：字数带执行
         * ------------------------------------------------------------------ */

        /**
         * 把一条“标签：正文”裁进字数带。返回 array('label','text','full','length','ok','trimmed')
         *
         * $allow_short 用于“支持系统”这类天然可以很短的条目（Skill 允许平台清单条更短/更长）。
         */
        public static function fit_feature($label, $text, $min, $max, $allow_short = false) {
            $label = trim(self::scalar($label));
            $text = trim(self::scalar($text));
            $label = self::strip_tail($label, '：:');
            $text = preg_replace('/^[：:\-\s]+/u', '', $text);
            $text = self::normalize_text($text);
            $text = self::strip_tail($text, '，、；：');
            // 平台 / 格式清单（“支持系统：Windows、macOS”）是名词罗列，不该补句号，
            // 否则正文里会出现“支持系统：Windows。”这种读起来别扭的排版。
            if (!$allow_short && $text !== '' && !preg_match('/[。！？]$/u', $text)) {
                $text = $text . '。';
            }

            // 平台/格式清单允许触及 Skill 的 50 字上限。
            $ceiling = $allow_short ? max($max, min(50, $max + 5)) : $max;

            $full = $label === '' ? $text : $label . '：' . $text;
            $trimmed = false;
            $length = self::len($full);
            if ($length > $ceiling) {
                $trimmed = true;
                $full = self::shorten_to_band($full, $min, $ceiling, true);
                $length = self::len($full);
            }
            if ($label !== '' && strpos($full, '：') === false) {
                $full = $label . '：' . $full;
                $length = self::len($full);
                // 重新补上标签后长度可能又超上限，再收一次。
                if ($length > $ceiling) {
                    $full = self::shorten_to_band($full, $min, $ceiling, true);
                    $length = self::len($full);
                }
            }
            $ok = ($length <= $ceiling) && ($allow_short || $length >= $min || ($trimmed && $length >= self::SKILL_FLOOR));
            return array(
                'label' => $label,
                'text' => $text,
                'full' => $full,
                'length' => $length,
                'ok' => $ok,
                'trimmed' => $trimmed,
                'allow_short' => $allow_short,
            );
        }

        /**
         * 把超长文本收进 [min, ceiling]。
         *
         * 优先在标点处断开，保证是完整句子；只有在整段上限内没有任何句读时，
         * 才按上限硬截断，并用省略号收尾表示“这里被裁过”，避免读成半句话。
         */
        private static function shorten_to_band($text, $min, $ceiling, $mark_truncated = false) {
            $text = trim(self::scalar($text));
            if ($text === '' || self::len($text) <= $ceiling) {
                return $text;
            }

            $chars = preg_split('//u', $text, -1, PREG_SPLIT_NO_EMPTY);
            if (!is_array($chars)) {
                // preg_split 在非法 UTF-8 上会失败，按字符安全截断，绝不按字节切。
                return self::cut($text, max(1, $ceiling - 1)) . ($mark_truncated ? '…' : '。');
            }

            // 第一遍：找区间内最长的标点边界（优先 [min, ceiling]，其次 Skill 的 30 字底线，再次任何完整句）。
            $buffer = '';
            $in_band = '';
            $soft_band = '';
            $any_sentence = '';
            $count = count($chars);
            for ($i = 0; $i < $count; $i++) {
                $buffer .= $chars[$i];
                if (!preg_match('/[，。；：！？、]/u', $chars[$i])) {
                    continue;
                }
                if (self::len($buffer) > $ceiling) {
                    break;
                }
                $candidate = $buffer;
                if (!preg_match('/[。！？]$/u', $candidate)) {
                    $candidate = self::strip_tail($candidate, '，；：、') . '。';
                }
                $candidate_length = self::len($candidate);
                if ($candidate_length > $ceiling) {
                    continue;
                }
                if ($candidate_length >= $min && ($in_band === '' || $candidate_length > self::len($in_band))) {
                    $in_band = $candidate;
                }
                if ($candidate_length >= self::SKILL_FLOOR && ($soft_band === '' || $candidate_length > self::len($soft_band))) {
                    $soft_band = $candidate;
                }
                if ($candidate_length >= 20 && ($any_sentence === '' || $candidate_length > self::len($any_sentence))) {
                    $any_sentence = $candidate;
                }
            }
            if ($in_band !== '') {
                return $in_band;
            }
            if ($soft_band !== '') {
                return $soft_band;
            }
            if ($any_sentence !== '') {
                // 完整短句永远比“处理手头。”这种半句可读。
                return $any_sentence;
            }

            // 第二遍：整段在上限之内连一个标点都没有（多是模型没加标点），只能按上限截断。
            // 去掉被截断后残留的连接词，避免出现“换一台电脑打开网址就能接着处理手头。”这种半句。
            $head = self::strip_tail(self::cut($text, max(1, $ceiling - 1)), '，。；：、');
            $head = self::strip_dangling_tail($head);
            if ($head === '') {
                return '';
            }
            // 正文条目被硬截断时用省略号收尾，读者一眼就知道这里被裁过；
            // 用句号会让人以为“教程时特。”本来就是一句话。
            return $head . ($mark_truncated ? '…' : '。');
        }

        /**
         * 去掉硬截断后挂在结尾、明显没说完的字词。
         */
        private static function strip_dangling_tail($text) {
            $text = (string) $text;
            $dangling = array(
                '以及', '并且', '而且', '或者', '还是', '然后', '接着', '顺便',
                '就能', '可以', '能够', '需要', '不用', '不会', '不再', '就能把',
                '的', '和', '与', '或', '及', '并', '而', '把', '将', '在', '对', '为',
                '让', '被', '给', '从', '向', '往', '就', '也', '还', '都', '很', '更', '最',
            );
            $changed = true;
            $guard = 0;
            while ($changed && $guard < 40) {
                $changed = false;
                $guard++;
                foreach ($dangling as $word) {
                    $length = self::len($text);
                    $word_length = self::len($word);
                    if ($length <= $word_length) {
                        continue;
                    }
                    // 用字符级比较判断结尾，substr(-strlen($word)) 是字节切片，
                    // 中文词会从半个字符处切开，永远匹配不上。
                    if ($word_length > 0 && self::cut($text, $length - $word_length) . $word === $text) {
                        $text = self::cut($text, $length - $word_length);
                        $changed = true;
                        break;
                    }
                }
            }
            return self::strip_tail($text, '，。；：、 ');
        }

        /* ---------------------------------------------------------------------
         * 事实校准：可疑表述
         * ------------------------------------------------------------------ */

        public static function scan_claims($items, $facts, $plain_text) {
            $warnings = array();
            $haystack = (string) $plain_text;
            $facts_text = isset($facts['fact_text']) ? (string) $facts['fact_text'] : '';
            $platform_words = array('Windows', 'macOS', 'Mac', 'Linux', 'Android', '安卓', 'iOS', 'iPhone', 'iPad', '鸿蒙', 'HarmonyOS');
            $found_platforms = array();
            foreach ($platform_words as $word) {
                if (strpos($haystack, $word) !== false) {
                    $found_platforms[$word] = true;
                }
            }
            foreach (array_keys($found_platforms) as $word) {
                if ($facts_text !== '' && stripos($facts_text, $word) === false) {
                    $warnings[] = '提到“' . $word . '”，但资料中没有确认该平台支持';
                }
            }

            $risky = array('永久', '无广告', '无水印', '破解', '不限次数', '无限', '全网', '最好', '第一', '最强', '完美支持');
            foreach ($risky as $word) {
                if (strpos($haystack, $word) !== false && ($facts_text === '' || stripos($facts_text, $word) === false)) {
                    $warnings[] = '“' . $word . '”缺少资料支持，发布前请核实';
                }
            }

            if (preg_match_all('/\d+(?:\.\d+)?\s*(?:个|种|款|套|张|首|条|GB|MB|K|万|年|天|小时|分钟|格式|语言|平台)/u', $haystack, $matches)) {
                $missing = array();
                foreach (array_unique($matches[0]) as $number) {
                    $digits = preg_replace('/[^0-9.]/', '', $number);
                    if ($digits !== '' && strpos($facts_text, $digits) === false) {
                        $missing[] = trim($number);
                    }
                }
                if (!empty($missing)) {
                    $warnings[] = '数字缺少资料支持：' . implode('、', array_slice($missing, 0, 5));
                }
            }
            return array_values(array_unique($warnings));
        }

        /* ---------------------------------------------------------------------
         * 结构化内容 → HTML
         * ------------------------------------------------------------------ */

        public static function render($payload, $type, $facts, $settings) {
            $min = isset($settings['feature_min']) ? (int) $settings['feature_min'] : 35;
            $max = isset($settings['feature_max']) ? (int) $settings['feature_max'] : 45;
            $min = max(20, min(80, $min));
            $max = max($min + 3, min(90, $max));
            $warnings = array();
            $stats = array();

            $type = self::resolve_type($type, $payload, $facts);
            $intro = isset($payload['intro']) ? self::normalize_text($payload['intro']) : '';
            if ($intro === '' && !empty($payload['summary'])) {
                $intro = self::normalize_text($payload['summary']);
            }
            $intro_len = self::len($intro);
            if ($intro_len > self::INTRO_MAX) {
                $intro = self::cut($intro, self::INTRO_MAX);
                $intro = self::trim_at_boundary($intro, self::INTRO_MIN);
                // 句末标点也要一并去掉，否则下面补句号时会变成“！。”或“。。”。
                $intro = self::strip_tail($intro, '，、；：。！？!?,;:');
                $intro_len = self::len($intro);
                if ($intro_len > 0) {
                    $intro .= '。';
                }
                $warnings[] = '简介超过 ' . self::INTRO_MAX . ' 字，已自动收短到 ' . self::len($intro) . ' 字';
            } elseif ($intro_len > 0 && $intro_len < self::INTRO_MIN) {
                $warnings[] = '简介只有 ' . $intro_len . ' 字，建议补充场景或收益';
            }

            $sections = self::collect_sections($payload, $type, $facts);
            $features = $sections['features'];
            $note = $sections['note'];
            $links = $sections['links'];

            // ---- 特点条目 ----
            $rendered_features = array();
            if (empty($features)) {
                $warnings[] = '未获得“' . $sections['feature_heading'] . '”条目，请手动补充';
            }
            foreach ($features as $index => $item) {
                if (!is_array($item)) {
                    $item = array('text' => self::scalar($item));
                }
                $label = self::scalar(isset($item['label']) ? $item['label'] : '');
                $text = self::scalar(isset($item['text']) ? $item['text'] : '');
                if (trim($text) === '' && isset($item['value'])) {
                    $text = self::scalar($item['value']);
                }
                if (trim($label) === '' && strpos($text, '：') !== false) {
                    $parts = explode('：', $text, 2);
                    $label = $parts[0];
                    $text = isset($parts[1]) ? $parts[1] : '';
                }
                $label = self::normalize_text($label);
                $label_len = self::len($label);
                if ($label_len > self::LABEL_MAX) {
                    $warnings[] = '标签“' . $label . '”超过 ' . self::LABEL_MAX . ' 字，建议改短';
                }
                $is_platform = self::is_platform_feature($label, $text);
                $fitted = self::fit_feature($label, $text, $min, $max, $is_platform);
                // 模型偶尔返回空条目，直接跳过，不要输出空的 <li>。
                if (self::len(self::strip_tail($fitted['full'], '：。；，、 ')) < 2) {
                    continue;
                }
                if ($fitted['trimmed']) {
                    $warnings[] = '特点“' . $label . '”超出 ' . ($is_platform ? '50' : $max) . ' 字，已自动收短到 ' . $fitted['length'] . ' 字';
                } elseif (!$fitted['ok'] && !$is_platform) {
                    $warnings[] = '特点“' . $label . '”只有 ' . $fitted['length'] . ' 字，建议补到 ' . $min . '-' . $max . ' 字';
                }
                $rendered_features[] = $fitted['full'];
                $stats[] = array(
                    'label' => $label,
                    'length' => $fitted['length'],
                    'ok' => $fitted['ok'],
                    'text' => $fitted['full'],
                    'is_platform' => $is_platform,
                );
            }

            // ---- 条数限制：软件 5-6 条；其它类型最多 6 条 ----
            $limit = 6;
            if (count($rendered_features) > $limit) {
                $rendered_features = array_slice($rendered_features, 0, $limit);
                $stats = array_slice($stats, 0, $limit);
                $warnings[] = '特点超过 ' . $limit . ' 条，已保留前 ' . $limit . ' 条';
            }
            if ($type === 'software' && count($rendered_features) < 5) {
                $warnings[] = '软件特点只有 ' . count($rendered_features) . ' 条，Skill 要求 5 到 6 条';
            }
            if (count($rendered_features) === 0) {
                $warnings[] = '正文缺少可用的“' . $sections['feature_heading'] . '”，建议点“换一批标题”重试或手动补充';
            }

            // ---- 软件类必须有独立的“支持系统”条目 ----
            if ($type === 'software') {
                $platform_index = -1;
                foreach ($stats as $index => $stat) {
                    if (!empty($stat['is_platform'])) {
                        $platform_index = $index;
                        break;
                    }
                }
                $platform = isset($facts['platforms']) ? trim(self::scalar(isset($facts['platforms']) ? $facts['platforms'] : '')) : '';
                if ($platform_index === -1) {
                    // 模型没写：用已确认资料补一条，资料也没有就写“待核实”。
                    $line = self::fit_feature(
                        '支持系统',
                        $platform !== '' ? $platform : '待核实',
                        $min,
                        $max,
                        true
                    );
                    if (count($rendered_features) >= $limit) {
                        array_pop($rendered_features);
                        array_pop($stats);
                    }
                    $rendered_features[] = $line['full'];
                    $stats[] = array(
                        'label' => '支持系统',
                        'length' => $line['length'],
                        'ok' => $line['ok'],
                        'text' => $line['full'],
                        'is_platform' => true,
                    );
                    if ($platform === '') {
                        $warnings[] = '资料未说明支持系统，已按 Skill 写入“支持系统：待核实”，发布前请补充';
                    }
                } elseif ($platform === '' && strpos($stats[$platform_index]['text'], '待核实') === false) {
                    $warnings[] = '“支持系统”未使用已验证资料（' . $stats[$platform_index]['text'] . '），发布前请核实';
                }
            }

            // ---- 下载 / 官网地址 ----
            $source_url = isset($payload['_source_url']) ? $payload['_source_url'] : '';
            $links = self::complete_links($links, $type, $facts, $source_url);
            if (empty($links)) {
                $warnings[] = '没有可用的' . $sections['links_heading'] . '，请手动补充';
            }

            $heading_tag = 'h3';
            $html = '';
            if ($intro !== '') {
                $html .= '<p>' . esc_html($intro) . '</p>';
            }
            $html .= "\n<" . $heading_tag . '>' . esc_html($sections['feature_heading']) . '</' . $heading_tag . ">\n";
            if (!empty($rendered_features)) {
                $html .= "<ul>\n";
                foreach ($rendered_features as $line) {
                    $html .= '<li>' . self::render_feature_line($line) . "</li>\n";
                }
                $html .= "</ul>\n";
            }
            $html .= "\n<" . $heading_tag . '>' . esc_html($sections['shot_heading']) . '</' . $heading_tag . ">\n";
            $html .= '<p>' . esc_html($note !== '' ? $note : $sections['default_note']) . "</p>\n";
            $html .= "\n<" . $heading_tag . '>' . esc_html($sections['links_heading']) . '</' . $heading_tag . ">\n";
            if (!empty($links)) {
                $html .= "<ul>\n";
                foreach ($links as $link) {
                    $html .= '<li><strong>' . esc_html($link['label']) . '：</strong>' . self::render_link_value($link) . "</li>\n";
                }
                $html .= "</ul>\n";
            }

            $html = wp_kses_post($html);
            $plain = trim(wp_strip_all_tags($html));
            $warnings = array_merge($warnings, self::scan_claims($rendered_features, $facts, $plain));

            return array(
                'html' => trim($html),
                'intro' => $intro,
                'feature_stats' => $stats,
                'warnings' => array_values(array_unique($warnings)),
                'type' => $type,
            );
        }

        /**
         * 是否是“支持系统/适用平台”这类允许出现在字数带之外的条目。
         */
        private static function is_platform_feature($label, $text) {
            $label = (string) $label;
            $haystack = $label . ' ' . (string) $text;
            if (preg_match('/支持系统|适用平台|系统要求|兼容平台|运行环境/u', $label)) {
                return true;
            }
            if (!preg_match('/支持系统|适用平台|系统要求|兼容平台|运行环境/u', $haystack)) {
                return false;
            }
            // 只有在确实列出平台清单时才放宽，避免把普通功能条误判。
            return (bool) preg_match('/Windows|macOS|Mac|Linux|Android|安卓|iOS|iPhone|iPad|鸿蒙|Web|网页版|待核实|跨平台/ui', $haystack);
        }

        private static function render_feature_line($line) {
            $parts = explode('：', $line, 2);
            if (count($parts) === 2 && self::len($parts[0]) <= self::LABEL_MAX + 2) {
                return '<strong>' . esc_html($parts[0]) . '：</strong>' . esc_html(self::strip_head($parts[1], '：'));
            }
            return esc_html($line);
        }

        private static function render_link_value($link) {
            if (!is_array($link)) {
                return esc_html(self::scalar($link));
            }
            $value = self::scalar(isset($link['value']) ? $link['value'] : '');
            $url = self::scalar(isset($link['url']) ? $link['url'] : '');
            if ($url !== '') {
                return '<a href="' . esc_url($url) . '" rel="nofollow noopener" target="_blank">' . esc_html($value !== '' ? $value : $url) . '</a>';
            }
            return esc_html($value);
        }

        private static function resolve_type($type, $payload, $facts) {
            $allowed = array('software', 'website', 'material');
            if (in_array($type, $allowed, true)) {
                return $type;
            }
            $model_type = isset($payload['post_type']) ? strtolower(self::scalar($payload['post_type'])) : '';
            $map = array(
                'software' => 'software',
                'tool' => 'software',
                'app' => 'software',
                'github' => 'software',
                'website' => 'website',
                'web' => 'website',
                'service' => 'website',
                'material' => 'material',
                'asset' => 'material',
                'template' => 'material',
            );
            if (isset($map[$model_type])) {
                return $map[$model_type];
            }
            return !empty($facts['is_github']) ? 'software' : 'website';
        }

        private static function collect_sections($payload, $type, $facts) {
            $defaults = array(
                'software' => array('软件特点', '软件截图', '下载地址', '请插入软件界面截图'),
                'website' => array('网站特点', '网站截图', '官网地址', '请插入网站首页或栏目截图'),
                'material' => array('素材特点', '截图演示', '下载地址', '请插入素材预览图或使用效果图'),
            );
            $default = $defaults[$type];
            $payload_sections = isset($payload['sections']) && is_array($payload['sections']) ? $payload['sections'] : array();

            $feature_heading = $default[0];
            $shot_heading = $default[1];
            $links_heading = $default[2];
            $note = '';
            $features = array();
            $links = array();

            foreach ($payload_sections as $section) {
                if (!is_array($section)) {
                    continue;
                }
                $heading = isset($section['heading']) ? trim(self::scalar(isset($section['heading']) ? $section['heading'] : '')) : '';
                $items = isset($section['items']) && is_array($section['items']) ? $section['items'] : array();
                $text = isset($section['text']) ? trim(self::scalar(isset($section['text']) ? $section['text'] : '')) : '';
                if ($text === '' && isset($section['note'])) {
                    $text = trim(self::scalar(isset($section['note']) ? $section['note'] : ''));
                }
                $looks_like_link = false;
                foreach ($items as $item) {
                    $candidate = is_array($item) && isset($item['url']) ? self::scalar(isset($item['url']) ? $item['url'] : '') : '';
                    if ($candidate === '' && is_array($item) && isset($item['text'])) {
                        $candidate = self::scalar(isset($item['text']) ? $item['text'] : '');
                    }
                    if (preg_match('~https?://~i', $candidate)) {
                        $looks_like_link = true;
                        break;
                    }
                }
                if ($looks_like_link || preg_match('/地址|链接|官网|下载|获取|热度|平台/u', $heading)) {
                    $links = $items;
                    if ($heading !== '') {
                        $links_heading = $heading;
                    }
                    continue;
                }
                if (preg_match('/截图|预览|界面|演示/u', $heading)) {
                    $shot_heading = $heading !== '' ? $heading : $shot_heading;
                    if ($text !== '') {
                        $note = $text;
                    }
                    continue;
                }
                if (empty($features)) {
                    if ($heading !== '') {
                        $feature_heading = self::normalize_heading($heading, $type);
                    }
                    $features = $items;
                    continue;
                }
                // 追加的补充段落，合并进特点的说明里不现实，忽略并提示。
            }

            if ($feature_heading === '') {
                $feature_heading = $default[0];
            }
            if ($shot_heading === '') {
                $shot_heading = $default[1];
            }
            if ($links_heading === '') {
                $links_heading = $default[2];
            }
            return array(
                'features' => $features,
                'note' => $note,
                'links' => $links,
                'feature_heading' => $feature_heading,
                'shot_heading' => $shot_heading,
                'links_heading' => $links_heading,
                'default_note' => $default[3],
            );
        }

        private static function normalize_heading($heading, $type) {
            if ($type === 'material' && preg_match('/(素材|模板|预设|字体|音效|贴图|图标|纹理)特点/u', $heading, $matches)) {
                return $matches[1] . '特点';
            }
            return $heading;
        }

        /**
         * 补齐地址区，优先使用已核实的事实，模型提供的链接只接受 http(s)。
         */
        private static function complete_links($links, $type, $facts, $source_url) {
            $model_links = array();
            foreach ($links as $item) {
                if (!is_array($item)) {
                    continue;
                }
                $label = isset($item['label']) ? trim(self::scalar(isset($item['label']) ? $item['label'] : '')) : '';
                $value = isset($item['text']) ? trim(self::scalar(isset($item['text']) ? $item['text'] : '')) : '';
                if ($value === '' && isset($item['value'])) {
                    $value = trim(self::scalar(isset($item['value']) ? $item['value'] : ''));
                }
                $url = isset($item['url']) ? trim(self::scalar(isset($item['url']) ? $item['url'] : '')) : '';
                if ($url === '' && preg_match('~https?://[^\s，。；]+~i', $value, $matches)) {
                    $url = $matches[0];
                }
                if ($url !== '' && !wp_http_validate_url($url)) {
                    $url = '';
                }
                if ($label === '') {
                    continue;
                }
                $model_links[$label] = array('label' => $label, 'value' => $value, 'url' => $url);
            }

            $official = '';
            if (!empty($facts['homepage'])) {
                $official = (string) $facts['homepage'];
            } elseif (!empty($facts['repo_url'])) {
                $official = (string) $facts['repo_url'];
            } elseif ($source_url !== '' && wp_http_validate_url($source_url)) {
                $official = $source_url;
            }
            if ($official !== '' && !wp_http_validate_url($official)) {
                $official = '';
            }

            $platform = isset($facts['platforms']) ? trim(self::scalar(isset($facts['platforms']) ? $facts['platforms'] : '')) : '';
            $heat = isset($facts['stars']) && $facts['stars'] !== null && $facts['stars'] !== ''
                ? number_format((int) $facts['stars']) . ' Stars（截至 ' . (isset($facts['retrieved_at']) ? $facts['retrieved_at'] : current_time('Y-m-d')) . '）'
                : '';

            $result = array();
            if ($type === 'website') {
                $result[] = array(
                    'label' => '官网地址',
                    'value' => isset($model_links['官网地址']) && $model_links['官网地址']['url'] === '' ? $model_links['官网地址']['value'] : ($official !== '' ? $official : '待补充'),
                    'url' => $official !== '' ? $official : (isset($model_links['官网地址']) ? $model_links['官网地址']['url'] : ''),
                );
                if (!empty($model_links['获取说明'])) {
                    $result[] = $model_links['获取说明'];
                }
                return $result;
            }

            if ($type === 'material') {
                $address = $official !== '' ? $official : '待补充';
                $result[] = array(
                    'label' => '资源地址',
                    'value' => $address,
                    'url' => $official,
                );
                $result[] = self::link_or_placeholder($model_links, '包含内容', '待核实');
                $result[] = self::link_or_placeholder($model_links, '使用说明', '待补充');
                return $result;
            }

            // software
            $address = $official !== '' ? $official : '待补充';
            $result[] = array(
                'label' => '官方地址',
                'value' => $address,
                'url' => $official,
            );
            $result[] = array(
                'label' => '适用平台',
                'value' => $platform !== '' ? $platform : '待核实',
                'url' => '',
            );
            $result[] = self::link_or_placeholder($model_links, '获取说明', '待补充');
            if ($heat !== '') {
                $result[] = array('label' => '项目热度', 'value' => $heat, 'url' => '');
            }
            return $result;
        }

        private static function link_or_placeholder($model_links, $label, $fallback) {
            if (!empty($model_links[$label])) {
                $link = $model_links[$label];
                if ($link['value'] === '' && $link['url'] !== '') {
                    $link['value'] = $link['url'];
                }
                return $link;
            }
            return array('label' => $label, 'value' => $fallback, 'url' => '');
        }
    }
}


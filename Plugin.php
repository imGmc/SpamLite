<?php

/**
 * SpamLite评论过滤器，SmartSpam简化版
 *
 * @package SpamLite
 * @author 陶小桃Blog Gmc
 * @version 0.1.2
 * @link https://www.gmcllp.cn
 */
class SpamLite_Plugin implements Typecho_Plugin_Interface
{
    /** @var int 日志保留上限 */
    private const LOG_MAX_ENTRIES = 200;

    /** @var int 日志中评论正文截断长度 */
    private const TEXT_TRUNCATE_LENGTH = 200;

    /** @var int 配置页日志展示条数 */
    private const LOG_DISPLAY_COUNT = 20;

    /**
     * 过滤规则配置。每项包含：选项键名、敏感词键名、评论字段、检查方式、规则名、拦截错误消息。
     */
    private const RULES = [
        ['opt' => 'opt_sensitive_words',  'words' => 'words_sensitive',           'field' => 'text',   'checker' => 'check_in',  'name' => '敏感词汇', 'message' => '评论内容中包含敏感词汇'],
        ['opt' => 'opt_no_chinese',       'words' => null,                         'field' => 'text',   'checker' => 'no_chinese', 'name' => '非中文评论', 'message' => '评论内容请包含至少一个中文汉字'],
        ['opt' => 'opt_sensitive_nickname','words' => 'words_sensitive_nickname',  'field' => 'author', 'checker' => 'check_in',  'name' => '敏感昵称', 'message' => '评论者的昵称包含敏感词汇'],
        ['opt' => 'opt_sensitive_url',    'words' => 'words_sensitive_url',        'field' => 'url',    'checker' => 'check_in',  'name' => '敏感网址', 'message' => '评论者的网址包含敏感词汇'],
        ['opt' => 'opt_sensitive_email',  'words' => 'words_sensitive_email',      'field' => 'mail',   'checker' => 'check_in',  'name' => '敏感邮箱', 'message' => '评论者的邮箱包含敏感词汇'],
    ];

    public static function activate()
    {
        Typecho_Plugin::factory('Widget_Feedback')->comment = array('SpamLite_Plugin', 'filter');
        return _t('SpamLite插件启用成功，请配置需要过滤的内容');
    }

    public static function deactivate() {}

    public static function config(Typecho_Widget_Helper_Form $form)
    {
        // 清空日志动作（带 CSRF 保护），必须在任何输出前处理
        if (isset($_GET['clear_log'])) {
            self::handleClearLog();
        }

        $opt_sensitive_words = new Typecho_Widget_Helper_Form_Element_Radio('opt_sensitive_words',
            array("none" => "无动作", "waiting" => "标记为待审核", "abandon" => "评论失败"), "none",
            _t('敏感词汇操作'), "如果评论中包含敏感词汇列表中的词汇，将执行该操作");
        $form->addInput($opt_sensitive_words);

        $words_sensitive = new Typecho_Widget_Helper_Form_Element_Textarea('words_sensitive', NULL, "",
            _t('敏感词汇'), _t('多条词汇请用换行符隔开'));
        $form->addInput($words_sensitive);

        $opt_no_chinese = new Typecho_Widget_Helper_Form_Element_Radio('opt_no_chinese',
            array("none" => "无动作", "waiting" => "标记为待审核", "abandon" => "评论失败"), "none",
            _t('非中文评论操作'), "如果评论中不包含中文，则执行该操作");
        $form->addInput($opt_no_chinese);

        $opt_sensitive_nickname = new Typecho_Widget_Helper_Form_Element_Radio('opt_sensitive_nickname',
            array("none" => "无动作", "waiting" => "标记为待审核", "abandon" => "评论失败"), "none",
            _t('敏感昵称操作'), "如果评论者的昵称包含敏感词汇列表中的词汇，将执行该操作");
        $form->addInput($opt_sensitive_nickname);

        $words_sensitive_nickname = new Typecho_Widget_Helper_Form_Element_Textarea('words_sensitive_nickname', NULL, "",
            _t('敏感昵称词汇'), _t('多条词汇请用换行符隔开'));
        $form->addInput($words_sensitive_nickname);

        $opt_sensitive_url = new Typecho_Widget_Helper_Form_Element_Radio('opt_sensitive_url',
            array("none" => "无动作", "waiting" => "标记为待审核", "abandon" => "评论失败"), "none",
            _t('敏感网址操作'), "如果评论者的网址包含敏感词汇列表中的词汇，将执行该操作");
        $form->addInput($opt_sensitive_url);

        $words_sensitive_url = new Typecho_Widget_Helper_Form_Element_Textarea('words_sensitive_url', NULL, "",
            _t('敏感网址词汇'), _t('多条词汇请用换行符隔开'));
        $form->addInput($words_sensitive_url);

        $opt_sensitive_email = new Typecho_Widget_Helper_Form_Element_Radio('opt_sensitive_email',
            array("none" => "无动作", "waiting" => "标记为待审核", "abandon" => "评论失败"), "none",
            _t('敏感邮箱操作'), "如果评论者的邮箱包含敏感词汇列表中的词汇，将执行该操作");
        $form->addInput($opt_sensitive_email);

        $words_sensitive_email = new Typecho_Widget_Helper_Form_Element_Textarea('words_sensitive_email', NULL, "",
            _t('敏感邮箱词汇'), _t('多条词汇请用换行符隔开'));
        $form->addInput($words_sensitive_email);

        $opt_log = new Typecho_Widget_Helper_Form_Element_Radio('__log_enabled',
            array('1' => '启用', '0' => '关闭'), '1',
            _t('审计日志'), '每条拦截记录将以JSON格式写入日志');
        $form->addInput($opt_log);

        self::renderLogSection();
        self::renderClearButton();
    }

    public static function personalConfig(Typecho_Widget_Helper_Form $form) {}

    public static function filter($comment)
    {
        $filter_set = Typecho_Widget::widget('Widget_Options')->plugin('SpamLite');
        $logEnabled = ($filter_set->__log_enabled ?? '1') === '1';

        $shouldWait = false;
        $matchedRules = [];

        foreach (self::RULES as $rule) {
            $optValue = $filter_set->{$rule['opt']} ?? 'none';
            if ($optValue === 'none') {
                continue;
            }

            $fieldValue = $comment[$rule['field']] ?? '';
            // check_in 类规则在字段为空时跳过
            if ($rule['checker'] === 'check_in' && $fieldValue === '') {
                continue;
            }

            $matched = self::checkRule($rule, $fieldValue, $filter_set);

            if ($matched === false) {
                continue;
            }

            // abandon：记录日志后立即拦截
            if ($optValue === 'abandon') {
                if ($logEnabled) {
                    $matchText = $rule['checker'] === 'no_chinese' ? '' : $matched;
                    self::log(self::buildRecord($comment, $rule['name'], $matchText, 'abandon'));
                }
                throw new Typecho_Widget_Exception($rule['message'] ?? '评论被拦截');
            }

            // waiting：收束到 matchedRules 统一处理
            $matchText = $rule['checker'] === 'no_chinese' ? '' : $matched;
            $matchedRules[] = ['rule' => $rule['name'], 'matched' => $matchText];
            $shouldWait = true;
        }

        // 统一记录 waiting 日志
        if ($logEnabled && !empty($matchedRules)) {
            foreach ($matchedRules as $m) {
                self::log(self::buildRecord($comment, $m['rule'], $m['matched'], 'waiting'));
            }
        }

        if ($shouldWait) {
            $comment['status'] = 'waiting';
        }

        return $comment;
    }

    private static function buildRecord($comment, $rule, $matched, $action)
    {
        return [
            'ip' => $comment['ip'] ?? '',
            'author' => $comment['author'] ?? '',
            'mail' => $comment['mail'] ?? '',
            'url' => $comment['url'] ?? '',
            'text' => mb_substr($comment['text'] ?? '', 0, self::TEXT_TRUNCATE_LENGTH, 'UTF-8'),
            'rule' => $rule,
            'matched' => $matched,
            'action' => $action,
        ];
    }

    private static function check_in($needles, $haystack)
    {
        if (!is_string($haystack) || $haystack === '') {
            return false;
        }
        // (string) 转换防止配置未保存时传入 null 触发 depreciation
        $needles = explode("\n", (string)$needles);
        foreach ($needles as $needle) {
            $needle = trim($needle);
            if ($needle !== '' && mb_stripos($haystack, $needle) !== false) {
                return $needle;
            }
        }
        return false;
    }

    private static function has_chinese($text)
    {
        if ($text === '') {
            return false;
        }
        return preg_match("/[\x{4e00}-\x{9fff}\x{3000}-\x{303f}]/u", $text) === 1;
    }

    /**
     * 执行单条规则的检查，返回匹配值或 false。
     * @param array $rule 规则定义
     * @param string $fieldValue 待检查的字段值
     * @param mixed $filter_set 插件配置对象
     * @return string|false 匹配到的词汇（check_in 类），规则名（no_chinese 类），或 false（未匹配）
     */
    private static function checkRule(array $rule, string $fieldValue, $filter_set): string|false
    {
        if ($rule['checker'] === 'check_in') {
            return self::check_in($filter_set->{$rule['words']}, $fieldValue);
        }
        if ($rule['checker'] === 'no_chinese') {
            return self::has_chinese($fieldValue) ? false : $rule['name'];
        }
        return false;
    }

    /** @var bool 日志目录防护文件是否已在本请求内创建 */
    private static $logProtectionDone = false;

    private static function log(array $record)
    {
        $logDir = __DIR__ . '/logs';
        if (!is_dir($logDir)) {
            if (!mkdir($logDir, 0755, true) && !is_dir($logDir)) {
                error_log('SpamLite: 无法创建日志目录');
                return;
            }
        }

        // 惰性创建 Web 访问防护文件（同一请求内仅检查一次）
        if (!self::$logProtectionDone) {
            self::$logProtectionDone = true;
            $htaccess = $logDir . '/.htaccess';
            if (!file_exists($htaccess)) {
                @file_put_contents($htaccess, "Require all denied\nDeny from all\n", LOCK_EX);
            }
            $indexFile = $logDir . '/index.html';
            if (!file_exists($indexFile)) {
                @file_put_contents($indexFile, '', LOCK_EX);
            }
        }

        $logFile = self::logFilePath();

        $record['time'] = date('Y-m-d H:i:s');
        $newLine = json_encode($record, JSON_UNESCAPED_UNICODE);
        if ($newLine === false) {
            error_log('SpamLite: JSON 编码失败: ' . json_last_error_msg());
            return;
        }

        // 在 flock 保护下完成读-改-写，避免并发竞争
        $fp = @fopen($logFile, 'c');
        if (!is_resource($fp)) {
            error_log('SpamLite: 无法打开日志文件');
            return;
        }
        if (!flock($fp, LOCK_EX)) {
            error_log('SpamLite: 无法锁定日志文件');
            fclose($fp);
            return;
        }

        $content = stream_get_contents($fp);
        $lines = ($content === false || $content === '') ? [] : array_values(array_filter(
            explode("\n", rtrim($content, "\n")),
            'strlen'
        ));

        $lines[] = $newLine;

        if (count($lines) > self::LOG_MAX_ENTRIES) {
            $lines = array_slice($lines, -self::LOG_MAX_ENTRIES);
        }

        ftruncate($fp, 0);
        rewind($fp);
        fwrite($fp, implode("\n", $lines) . "\n");
        fflush($fp);
        flock($fp, LOCK_UN);
        fclose($fp);
    }

    private static function logFilePath(): string
    {
        return __DIR__ . '/logs/log';
    }

    private static function get_log_entries($n)
    {
        $logFile = self::logFilePath();
        if (!file_exists($logFile)) {
            return [];
        }
        $lines = @file($logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if (!is_array($lines)) {
            return [];
        }
        $lines = array_slice($lines, -$n);
        $entries = [];
        foreach ($lines as $line) {
            $data = @json_decode($line, true);
            if (is_array($data)) {
                $entries[] = $data;
            }
        }
        return $entries;
    }

    /**
     * 基于站点密钥的 HMAC 令牌，用于 clear_log 防 CSRF。
     * 无需额外存储，站点特有。
     */
    private static function clearLogToken(): string
    {
        $key = __FILE__ . Typecho_Widget::widget('Widget_Options')->siteUrl;
        return substr(hash_hmac('sha256', 'clear_log', $key), 0, 16);
    }

    /**
     * 处理清空日志请求（带 CSRF 校验）。
     */
    private static function handleClearLog(): void
    {
        $token = $_GET['_spamlite_token'] ?? '';
        if (!hash_equals(self::clearLogToken(), $token)) {
            return;
        }

        $logFile = self::logFilePath();
        if (file_exists($logFile)) {
            if (!unlink($logFile)) {
                error_log('SpamLite: 无法删除日志文件');
            }
        }

        // 从 URL 中移除 clear_log 和 _spamlite_token 参数
        $url = $_SERVER['REQUEST_URI'] ?? '';
        $url = preg_replace('/[?&]clear_log=\d*/', '', $url);
        $url = preg_replace('/[?&]_spamlite_token=[^&]*/', '', $url);
        $url = rtrim($url, '?&') ?: '.';

        header('Location: ' . $url);
        exit;
    }

    /** 渲染日志展示区域（配置页使用） */
    private static function renderLogSection(): void
    {
        echo '<div class="typecho-page-title" style="margin-top:30px"><h2>最近 ' . self::LOG_DISPLAY_COUNT . ' 条日志</h2></div>';

        $entries = self::get_log_entries(self::LOG_DISPLAY_COUNT);
        if (empty($entries)) {
            echo '<p style="color:#999">暂无日志记录</p>';
            return;
        }

        echo '<div style="max-height:300px;overflow-y:auto;background:#f9f9f9;border:1px solid #ddd;border-radius:4px;padding:10px;font-family:Consolas,monospace;font-size:12px;line-height:1.6;white-space:pre-wrap;word-break:break-all">';
        foreach ($entries as $entry) {
            echo htmlspecialchars(
                $entry['time'] . ' | ' . $entry['ip'] . ' | ' . $entry['author'] . ' | '
                . $entry['rule'] . ' [' . ($entry['action'] ?? '') . ']'
                . (!empty($entry['matched']) ? ' | matched: ' . $entry['matched'] : '')
            ) . "\n";
        }
        echo '</div>';
    }

    /** 渲染清空日志按钮（配置页使用） */
    private static function renderClearButton(): void
    {
        $clearUrl = '?clear_log=1&_spamlite_token=' . urlencode(self::clearLogToken());
        echo '<p style="margin-top:8px"><a href="' . $clearUrl . '" class="btn btn-s" style="color:#c33" onclick="return confirm(\'确定清空所有日志？\')">清空日志</a></p>';
    }
}

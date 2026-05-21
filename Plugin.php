<?php

/**
 * SpamLite评论过滤器，SmartSpam简化版
 *
 * @package SpamLite
 * @author 陶小桃Blog Gmc
 * @version 0.1.1
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

        echo '<div class="typecho-page-title" style="margin-top:30px"><h2>最近 ' . self::LOG_DISPLAY_COUNT . ' 条日志</h2></div>';

        $entries = self::get_log_entries(self::LOG_DISPLAY_COUNT);
        if (empty($entries)) {
            echo '<p style="color:#999">暂无日志记录</p>';
        } else {
            echo '<div style="max-height:300px;overflow-y:auto;background:#f9f9f9;border:1px solid #ddd;border-radius:4px;padding:10px;font-family:Consolas,monospace;font-size:12px;line-height:1.6;white-space:pre-wrap;word-break:break-all">';
            foreach ($entries as $entry) {
                $line = htmlspecialchars(
                    $entry['time'] . ' | ' . $entry['ip'] . ' | ' . $entry['author'] . ' | '
                    . $entry['rule'] . ' [' . $entry['action'] . ']'
                    . (!empty($entry['matched']) ? ' | matched: ' . $entry['matched'] : '')
                );
                echo $line . "\n";
            }
            echo '</div>';
        }

        // 带 CSRF token 的清空日志链接
        $clearUrl = '?clear_log=1&_spamlite_token=' . urlencode(self::clearLogToken());
        echo '<p style="margin-top:8px"><a href="' . $clearUrl . '" class="btn btn-s" style="color:#c33" onclick="return confirm(\'确定清空所有日志？\')">清空日志</a></p>';
    }

    public static function personalConfig(Typecho_Widget_Helper_Form $form) {}

    /**
     * 过滤规则配置。
     * 每项定义：选项键名、敏感词键名（可选）、评论字段、检查方式、规则名。
     */
    private const RULES = [
        ['opt' => 'opt_sensitive_words',  'words' => 'words_sensitive',           'field' => 'text',   'checker' => 'check_in',  'name' => '敏感词汇'],
        ['opt' => 'opt_no_chinese',       'words' => null,                         'field' => 'text',   'checker' => 'no_chinese', 'name' => '非中文评论'],
        ['opt' => 'opt_sensitive_nickname','words' => 'words_sensitive_nickname',  'field' => 'author', 'checker' => 'check_in',  'name' => '敏感昵称'],
        ['opt' => 'opt_sensitive_url',    'words' => 'words_sensitive_url',        'field' => 'url',    'checker' => 'check_in',  'name' => '敏感网址'],
        ['opt' => 'opt_sensitive_email',  'words' => 'words_sensitive_email',      'field' => 'mail',   'checker' => 'check_in',  'name' => '敏感邮箱'],
    ];

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

            // 执行检查
            $matched = false;
            if ($rule['checker'] === 'check_in') {
                $matched = self::check_in($filter_set->{$rule['words']}, (string)$fieldValue);
            } elseif ($rule['checker'] === 'no_chinese') {
                $matched = !self::has_chinese((string)$fieldValue);
            }

            if ($matched === false) {
                continue;
            }

            // abandon：记录日志后立即拦截
            if ($optValue === 'abandon') {
                if ($logEnabled) {
                    $matchText = $rule['checker'] === 'no_chinese' ? '' : $matched;
                    self::log(self::buildRecord($comment, $rule['name'], $matchText, 'abandon'));
                }
                $messages = [
                    '敏感词汇' => '评论内容中包含敏感词汇',
                    '非中文评论' => '评论内容请包含至少一个中文汉字',
                    '敏感昵称' => '评论者的昵称包含敏感词汇',
                    '敏感网址' => '评论者的网址包含敏感词汇',
                    '敏感邮箱' => '评论者的邮箱包含敏感词汇',
                ];
                throw new Typecho_Widget_Exception($messages[$rule['name']] ?? '评论被拦截');
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

    private static function log(array $record)
    {
        $logDir = __DIR__ . '/logs';
        if (!is_dir($logDir)) {
            if (!mkdir($logDir, 0755, true) && !is_dir($logDir)) {
                error_log('SpamLite: 无法创建日志目录');
                return;
            }
        }
        $logFile = $logDir . '/log';

        $record['time'] = date('Y-m-d H:i:s');
        $newLine = json_encode($record, JSON_UNESCAPED_UNICODE);

        $lines = @file($logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if (!is_array($lines)) {
            $lines = [];
        }

        $lines[] = $newLine;

        if (count($lines) > self::LOG_MAX_ENTRIES) {
            $lines = array_slice($lines, -self::LOG_MAX_ENTRIES);
        }

        $result = file_put_contents($logFile, implode("\n", $lines) . "\n", LOCK_EX);
        if ($result === false) {
            error_log('SpamLite: 无法写入日志文件');
        }
    }

    private static function get_log_entries($n)
    {
        $logFile = __DIR__ . '/logs/log';
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

        $logFile = __DIR__ . '/logs/log';
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
}

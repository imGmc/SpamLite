<?php

/**
 * SpamLite评论过滤器，SmartSpam简化版
 *
 * @package SpamLite
 * @author 陶小桃Blog Gmc
 * @version 0.1.0
 * @link https://www.gmcllp.cn
 */

class SpamLite_Plugin implements Typecho_Plugin_Interface
{
    public static function activate()
    {
        Typecho_Plugin::factory('Widget_Feedback')->comment = array('SpamLite_Plugin', 'filter');
        return _t('SpamLite插件启用成功，请配置需要过滤的内容');
    }

    public static function deactivate(){}

    public static function config(Typecho_Widget_Helper_Form $form)
    {
        if (isset($_GET['clear_log'])) {
            $logFile = __DIR__ . '/logs/log';
            if (file_exists($logFile)) {
                @unlink($logFile);
            }
            $url = preg_replace('/[?&]clear_log=\d*/', '', $_SERVER['REQUEST_URI']);
            header('Location: ' . $url);
            exit;
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

        echo '<div class="typecho-page-title" style="margin-top:30px"><h2>最近 20 条日志</h2></div>';

        $entries = self::get_log_entries(20);
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

        echo '<p style="margin-top:8px"><a href="?clear_log=1" class="btn btn-s" style="color:#c33" onclick="return confirm(\'确定清空所有日志？\')">清空日志</a></p>';
    }

    public static function personalConfig(Typecho_Widget_Helper_Form $form){}

    public static function filter($comment)
    {
        $filter_set = Typecho_Widget::widget('Widget_Options')->plugin('SpamLite');
        $logEnabled = ($filter_set->__log_enabled ?? '1') === '1';

        $text = (string)($comment['text'] ?? '');
        $author = (string)($comment['author'] ?? '');
        $url = (string)($comment['url'] ?? '');
        $mail = (string)($comment['mail'] ?? '');
        $shouldWait = false;
        $matchedRules = [];

        if ($filter_set->opt_sensitive_words != "none") {
            $matched = self::check_in($filter_set->words_sensitive, $text);
            if ($matched !== false) {
                if ($filter_set->opt_sensitive_words == "abandon") {
                    if ($logEnabled) {
                        self::log(self::buildRecord($comment, '敏感词汇', $matched, 'abandon'));
                    }
                    throw new Typecho_Widget_Exception("评论内容中包含敏感词汇");
                }
                $matchedRules[] = ['rule' => '敏感词汇', 'matched' => $matched];
                $shouldWait = true;
            }
        }

        if ($filter_set->opt_no_chinese != "none") {
            if (!self::has_chinese($text)) {
                if ($filter_set->opt_no_chinese == "abandon") {
                    if ($logEnabled) {
                        self::log(self::buildRecord($comment, '非中文评论', '', 'abandon'));
                    }
                    throw new Typecho_Widget_Exception("评论内容请包含至少一个中文汉字");
                }
                $matchedRules[] = ['rule' => '非中文评论', 'matched' => ''];
                $shouldWait = true;
            }
        }

        if ($filter_set->opt_sensitive_nickname != "none" && $author !== '') {
            $matched = self::check_in($filter_set->words_sensitive_nickname, $author);
            if ($matched !== false) {
                if ($filter_set->opt_sensitive_nickname == "abandon") {
                    if ($logEnabled) {
                        self::log(self::buildRecord($comment, '敏感昵称', $matched, 'abandon'));
                    }
                    throw new Typecho_Widget_Exception("评论者的昵称包含敏感词汇");
                }
                $matchedRules[] = ['rule' => '敏感昵称', 'matched' => $matched];
                $shouldWait = true;
            }
        }

        if ($filter_set->opt_sensitive_url != "none" && $url !== '') {
            $matched = self::check_in($filter_set->words_sensitive_url, $url);
            if ($matched !== false) {
                if ($filter_set->opt_sensitive_url == "abandon") {
                    if ($logEnabled) {
                        self::log(self::buildRecord($comment, '敏感网址', $matched, 'abandon'));
                    }
                    throw new Typecho_Widget_Exception("评论者的网址包含敏感词汇");
                }
                $matchedRules[] = ['rule' => '敏感网址', 'matched' => $matched];
                $shouldWait = true;
            }
        }

        if ($filter_set->opt_sensitive_email != "none" && $mail !== '') {
            $matched = self::check_in($filter_set->words_sensitive_email, $mail);
            if ($matched !== false) {
                if ($filter_set->opt_sensitive_email == "abandon") {
                    if ($logEnabled) {
                        self::log(self::buildRecord($comment, '敏感邮箱', $matched, 'abandon'));
                    }
                    throw new Typecho_Widget_Exception("评论者的邮箱包含敏感词汇");
                }
                $matchedRules[] = ['rule' => '敏感邮箱', 'matched' => $matched];
                $shouldWait = true;
            }
        }

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
            'text' => mb_substr($comment['text'] ?? '', 0, 200),
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
        $needles = explode("\n", $needles);
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
            @mkdir($logDir, 0755, true);
        }
        $logFile = $logDir . '/log';

        $record['time'] = date('Y-m-d H:i:s');
        $newLine = json_encode($record, JSON_UNESCAPED_UNICODE);

        $lines = @file($logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines === false || !is_array($lines)) {
            $lines = [];
        }

        $lines[] = $newLine;

        if (count($lines) > 200) {
            $lines = array_slice($lines, -200);
        }

        $content = '';
        foreach ($lines as $line) {
            $content .= $line . "\n";
        }

        @file_put_contents($logFile, $content, LOCK_EX);
    }

    private static function get_log_entries($n)
    {
        $logFile = __DIR__ . '/logs/log';
        if (!file_exists($logFile)) {
            return [];
        }
        $lines = @file($logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines === false || !is_array($lines)) {
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
}

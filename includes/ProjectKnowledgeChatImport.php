<?php
/**
 * 精选聊天记录批量导入：粘贴 / 上传聊天导出文本 → AI 切分成多段独立对话并整理；
 * AI 未启用或失败时，用本地规则识别托底（按空行 / 分隔线切段，识别“客户：”“客服：”与微信、QQ 导出格式）。
 * 全程先脱敏再识别；结果只是预览，用户核对后才会保存。
 */
require_once __DIR__ . '/ProjectKnowledgeSkills.php';

const PKS_CHAT_IMPORT_MAX = 12000;

/** 把一行 “昵称  2026-09-01 10:20:33” / “昵称(2026/9/1 10:20)” 这类消息头拆成 [昵称, 余下]，不是消息头返回 null。 */
function pks_chat_header($line)
{
    $line = trim($line);
    if (preg_match('/^(.{1,30}?)[\s　]*[\(（\[【]?\s*(\d{4}[-\/.年]\d{1,2}[-\/.月]\d{1,2}日?[\sT]*\d{1,2}:\d{2}(?::\d{2})?)\s*[\)）\]】]?\s*$/u', $line, $m)) return trim($m[1]);
    if (preg_match('/^(.{1,30}?)[\s　]+(\d{1,2}:\d{2}(?::\d{2})?)\s*$/u', $line, $m)) return trim($m[1]);
    return null;
}

function pks_chat_split_blocks($text)
{
    $text = str_replace("\r", '', $text);
    // 分隔线 / 三个以上空行 / “对话1”“案例一”这类小标题都当作对话边界
    $text = preg_replace('/^[ \t]*([-=_*~·]{3,}|#{1,3}[ \t].*|(?:对话|案例|聊天|记录)[ \t]*[0-9一二三四五六七八九十]+[ \t]*[:：.、]?[^\n]*)[ \t]*$/mu', "\n\n\n", $text);
    $blocks = preg_split('/\n\s*\n\s*\n+/u', $text);
    return array_values(array_filter(array_map('trim', $blocks), 'strlen'));
}

/** 一段对话原文 → 规范成“客户：/客服：”逐行文本；返回 [文本, 是否为推测]。 */
function pks_chat_normalize($block, array $myNames)
{
    $lines = array_values(array_filter(array_map('trim', explode("\n", $block)), 'strlen'));
    $out = []; $guessed = false;
    $tagged = 0;
    foreach ($lines as $line) if (preg_match('/^(客户|顾客|买家|用户|客人|甲方|客服|商家|卖家|我|售后|销售|技术|设计师|我方)\s*[:：]/u', $line)) $tagged++;
    if ($tagged >= 2) {
        foreach ($lines as $line) {
            if (preg_match('/^(客户|顾客|买家|用户|客人|甲方)\s*[:：]\s*(.*)$/u', $line, $m)) $out[] = '客户：' . $m[2];
            elseif (preg_match('/^(客服|商家|卖家|我|售后|销售|技术|设计师|我方)\s*[:：]\s*(.*)$/u', $line, $m)) $out[] = '客服：' . $m[2];
            elseif ($out) $out[count($out) - 1] .= "\n" . $line;
        }
        return [implode("\n", $out), false];
    }
    // 微信 / QQ 导出：消息头一行，正文若干行
    $turns = []; $cur = null; $order = [];
    foreach ($lines as $line) {
        $name = pks_chat_header($line);
        if ($name !== null && $name !== '') { $cur = count($turns); $turns[] = ['name' => $name, 'text' => '']; if (!in_array($name, $order, true)) $order[] = $name; continue; }
        if ($cur !== null) $turns[$cur]['text'] .= ($turns[$cur]['text'] === '' ? '' : "\n") . $line;
    }
    if (count($turns) >= 2 && count($order) >= 1) {
        $mine = array_map('mb_strtolower', $myNames);
        foreach ($turns as $t) {
            if ($t['text'] === '') continue;
            if ($mine) $isAgent = in_array(mb_strtolower($t['name']), $mine, true);
            else { $isAgent = $t['name'] !== $order[0]; $guessed = true; }  // 没填昵称：先发言的当客户
            $out[] = ($isAgent ? '客服：' : '客户：') . $t['text'];
        }
        return [implode("\n", $out), $guessed];
    }
    // 只有零散的行：按轮流发言推测（客户先说）
    foreach ($lines as $i => $line) $out[] = ($i % 2 === 0 ? '客户：' : '客服：') . $line;
    return [implode("\n", $out), true];
}

function pks_chat_import_local($text, array $myNames)
{
    $items = []; $guessAny = false;
    foreach (pks_chat_split_blocks($text) as $block) {
        [$log, $guessed] = pks_chat_normalize($block, $myNames);
        $turns = pks_parse_chat($log);
        if (count($turns) < 2) continue;
        $guessAny = $guessAny || $guessed;
        $first = '';
        foreach ($turns as $t) if ($t['speaker'] === 'customer') { $first = $t['text']; break; }
        $items[] = ['title' => mb_substr(preg_replace('/\s+/u', ' ', $first !== '' ? $first : $turns[0]['text']), 0, 40), 'scenario' => '', 'chat_log' => $log, 'content' => '', 'tags' => '', 'guessed' => $guessed];
    }
    return [$items, $guessAny];
}

/**
 * 返回 ['items' => [[title,scenario,chat_log,content,tags,guessed]], 'source' => 'ai'|'local', 'note' => '']。
 * $myNames：用户自己的昵称（可空），用来判断哪些是“客服”发言。
 */
function pks_chat_import_parse($text, array $myNames = [])
{
    $text = trim((string)$text);
    if ($text === '') throw new RuntimeException('请先粘贴或上传聊天记录。');
    if (!mb_check_encoding($text, 'UTF-8') || strpos($text, "\0") !== false) throw new RuntimeException('文件需为 UTF-8 文本，请另存为 .txt 后重试。');
    if (mb_strlen($text) > PKS_CHAT_IMPORT_MAX) throw new RuntimeException('文字太长，请分几次导入（每次不超过 ' . PKS_CHAT_IMPORT_MAX . ' 字）。');
    $text = pks_mask_pii($text);
    $myNames = array_values(array_filter(array_map('trim', $myNames), 'strlen'));
    $items = null; $note = '';
    if (ps_ai_ready()) {
        try {
            $who = $myNames ? '其中“' . implode('”“', $myNames) . '”是我方客服，其余发言者都是客户。' : '请根据内容判断谁是客服、谁是客户（先发起咨询的一般是客户）。';
            $prompt = "下面是从聊天软件复制或导出的客服与客户的聊天记录，可能包含多段互相独立的对话。" . $who . "\n"
                . "请把它切分成独立的对话（按话题、日期或客户区分），每段整理成一个条目，输出 JSON：{\"items\":[{\"title\":\"\",\"scenario\":\"\",\"chat_log\":\"\",\"content\":\"\",\"tags\":\"\"}]}\n"
                . "title：15 字内的简短标题；scenario：客户的意图/场景；chat_log：对话原文，每句一行，以“客户：”或“客服：”开头，保持原话不改写、不删减；"
                . "content：亮点点评，一两句说明客服哪里处理得好、值得学习（没有明显亮点就留空）；tags：2-4 个关键词，逗号分隔。"
                . "手机号等已被打码的内容保持原样；只有一两句寒暄、没有实质内容的片段不要输出。只输出 JSON。\n\n" . $text;
            $messages = [['role' => 'system', 'content' => '你是严谨的客服培训资料整理助手，只输出 JSON。'], ['role' => 'user', 'content' => $prompt]];
            try { $reply = ps_ai_chat($messages, 10000); } catch (RuntimeException $e) { if (mb_strpos($e->getMessage(), '无法连接') === false) throw $e; $reply = ps_ai_chat($messages, 10000); }
            $data = ps_ai_json($reply);
            if (!is_array($data['items'] ?? null)) throw new RuntimeException('AI 未返回条目');
            $items = [];
            foreach ($data['items'] as $it) {
                if (!is_array($it)) continue;
                $row = [];
                foreach (['title', 'scenario', 'chat_log', 'content', 'tags'] as $field) { $v = $it[$field] ?? ''; $row[$field] = trim(is_array($v) ? implode("\n", array_map('strval', $v)) : (string)$v); }
                if (count(pks_parse_chat($row['chat_log'])) < 2) continue;
                $row['title'] = mb_substr($row['title'] !== '' ? $row['title'] : $row['scenario'], 0, 100);
                $row['guessed'] = false;
                $items[] = $row;
            }
            if (!$items) { $items = null; throw new RuntimeException('AI 没有识别出完整的对话'); }
        } catch (RuntimeException $e) {
            $note = 'AI 识别失败（' . mb_substr($e->getMessage(), 0, 60) . '），已改用规则识别，请逐条核对';
            $items = null;
        }
    } else $note = 'AI 未启用，已按规则识别，请逐条核对';
    $source = $items === null ? 'local' : 'ai';
    if ($items === null) {
        [$items, $guessed] = pks_chat_import_local($text, $myNames);
        if ($guessed) $note = trim($note . '；部分对话的客户 / 客服是推测的，请核对每句的开头', '；');
    }
    return ['items' => array_slice($items, 0, 30), 'source' => $source, 'note' => $note];
}

/** 批量保存：每条按“精选聊天记录”存入，默认待审核（主管 / 管理员导入可直接采纳）。返回成功条数。 */
function pks_chat_import_save(array $items, array $defaults, array $ctx)
{
    if (!$items) throw new RuntimeException('没有要导入的对话。');
    if (count($items) > 30) throw new RuntimeException('一次最多导入 30 段对话。');
    $saved = 0;
    foreach ($items as $it) {
        if (!is_array($it)) continue;
        pks_save([
            'kind' => 'chat', 'title' => $it['title'] ?? '', 'scenario' => $it['scenario'] ?? '', 'chat_log' => $it['chat_log'] ?? '',
            'content' => $it['content'] ?? '', 'tags' => $it['tags'] ?? '',
            'roles' => $it['roles'] ?? ($defaults['roles'] ?? []), 'business' => $it['business'] ?? ($defaults['business'] ?? ''),
            'status' => !empty($defaults['approve']) ? 'approved' : 'pending', 'quality' => 3,
        ], $ctx);
        $saved++;
    }
    return $saved;
}

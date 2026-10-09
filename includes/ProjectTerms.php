<?php
/**
 * 对外用语规则（仅限代码注释 / 内部文档，不要出现在任何用户可见的页面、提示、规则说明里）。
 *
 * 系统对外把合作人员的服务按“服务关系”表述，不使用劳动管理术语；页面上用户看到的是右边的词，代码、数据库字段、
 * 上传表头匹配里仍保留左边的老词（上传表头两种写法都认），页面输出时由 pt_filter_html() 统一换成右边的词。
 *
 *   考勤表 / 考勤记录   → 服务时长确认单        考勤（其他）   → 服务时长确认
 *   出勤表 / 出勤        → 到岗确认              缺勤记录 / 缺勤 → 未履约记录 / 未履约
 *   打卡记录 / 打卡      → 签到确认
 *   值班表 / 值班        → 排班时段表 / 排班时段
 *   工时统计表 / 工时    → 服务时长
 *   请假 / 调休记录      → 服务暂停（申请）/ 时段调整确认
 *   加班 / 加班费        → 延时服务 / 超时补贴（见 ProjectOvertime.php）
 *
 * 新增页面、提示、模板、导出文件时，用户可见文字一律写右边的词；老词只允许出现在 PHP 注释和上传表头识别里。
 * 同步记录在 CLAUDE.md“对外用语”和 docs/terminology.md。
 */

/** 老词 → 对外用语。strtr 按最长匹配优先替换，所以长词不必排在前面。 */
function pt_terms()
{
    return [
        '考勤表' => '服务时长确认单', '考勤记录' => '服务时长确认单', '考勤' => '服务时长确认',
        '出勤表' => '到岗确认单', '出勤' => '到岗确认',
        '缺勤记录' => '未履约记录', '缺勤' => '未履约',
        '打卡记录' => '签到确认', '打卡' => '签到确认',
        '值班表' => '排班时段表', '值班' => '排班时段',
        '工时统计表' => '服务时长', '工时' => '服务时长',
        '请假记录' => '服务暂停申请', '调休记录' => '时段调整确认', '请假' => '服务暂停', '调休' => '时段调整',
        '加班费' => '超时补贴', '加班' => '延时服务',
    ];
}

/** 把一段文字里的老词换成对外用语。 */
function pt_text($text)
{
    return strtr((string)$text, pt_terms());
}

/**
 * 处理整页 HTML：只换页面上看得见的文字（标签之间的文字，以及 title / placeholder / alt / aria-label 等提示属性）。
 * 不动 script / style / textarea / 注释、表单 value 和链接地址，避免影响脚本逻辑和用户已填写的内容。
 */
function pt_filter_html($html)
{
    if (!is_string($html) || $html === '' || !preg_match('/考勤|出勤|缺勤|打卡|值班|工时|请假|调休|加班/u', $html)) return $html;
    $parts = preg_split('~(<script\b.*?</script>|<style\b.*?</style>|<textarea\b.*?</textarea>|<!--.*?-->|<[^>]*>)~isu', $html, -1, PREG_SPLIT_DELIM_CAPTURE);
    if ($parts === false) return $html;
    $terms = pt_terms();
    foreach ($parts as $i => $part) {
        if ($i % 2 === 0) { $parts[$i] = strtr($part, $terms); continue; }
        if (isset($part[1]) && $part[0] === '<' && ctype_alpha($part[1])) {
            $parts[$i] = preg_replace_callback('/\b(title|placeholder|alt|aria-label|data-title|data-original-title|data-confirm)=(["\'])(.*?)\2/isu', function ($m) use ($terms) {
                return $m[1] . '=' . $m[2] . strtr($m[3], $terms) . $m[2];
            }, $part);
        }
    }
    return implode('', $parts);
}

/** 输出缓冲回调：只处理网页（HTML）输出；下载文件、JSON 等原样返回。 */
function pt_output_filter($buffer)
{
    foreach (headers_list() as $header) {
        if (stripos($header, 'Content-Disposition:') === 0) return $buffer;
        if (stripos($header, 'Content-Type:') === 0 && stripos($header, 'text/html') === false) return $buffer;
    }
    $filtered = pt_filter_html($buffer);
    return is_string($filtered) ? $filtered : $buffer;
}

/** 页面开始时调用（includes/header.php 顶部）：之后的整页输出统一换成对外用语。 */
function pt_start()
{
    static $started = false;
    if ($started || PHP_SAPI === 'cli') return;
    $started = true;
    ob_start('pt_output_filter');
}

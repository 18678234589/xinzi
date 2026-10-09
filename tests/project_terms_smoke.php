<?php
// 对外用语：页面上看得见的文字不出现劳动管理术语（规则见 includes/ProjectTerms.php），脚本 / 表单内容 / 上传表头识别不受影响。
// 纯计算，不读写数据库：php tests/project_terms_smoke.php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
require_once __DIR__ . '/../includes/ProjectTerms.php';
$check = function ($ok, $label) { if (!$ok) throw new RuntimeException('未通过：' . $label); echo "  [OK] $label\n"; };
$banned = '/考勤|出勤|缺勤|打卡|值班|工时|请假|调休|加班/u';
try {
    $html = '<h4>考勤表</h4><th title="请假小时数">请假(h)</th><input placeholder="缺勤原因" value="用户填写的考勤备注"><textarea name="n">考勤 请假</textarea>'
        . '<script>var t="考勤"; if (x==="请假") {}</script><!-- 考勤 --><a href="/attendance/index.php?q=考勤">实际出勤天数 / 应出勤小时 / 打卡记录 / 值班表 / 工时统计表 / 调休记录 / 加班费 / 加班</a>';
    $out = pt_filter_html($html);
    $check(strpos($out, '<h4>服务时长确认单</h4>') !== false, '考勤表 → 服务时长确认单');
    $check(strpos($out, 'title="服务暂停小时数">服务暂停(h)') !== false, '请假 → 服务暂停（含 title 提示）');
    $check(strpos($out, 'placeholder="未履约原因"') !== false, '缺勤 → 未履约（placeholder）');
    $check(strpos($out, 'value="用户填写的考勤备注"') !== false, '表单 value（用户填写内容）不动');
    $check(strpos($out, '<textarea name="n">考勤 请假</textarea>') !== false, 'textarea 内容不动');
    $check(strpos($out, 'var t="考勤"; if (x==="请假")') !== false, 'script 里的逻辑不动');
    $check(strpos($out, '<!-- 考勤 -->') !== false, 'HTML 注释不动');
    $check(strpos($out, 'href="/attendance/index.php?q=考勤"') !== false, '链接地址不动');
    $check(strpos($out, '实际到岗确认天数 / 应到岗确认小时 / 签到确认 / 排班时段表 / 服务时长 / 时段调整确认 / 超时补贴 / 延时服务') !== false, '实际出勤天数、应出勤小时、打卡记录、值班表、工时统计表、调休记录、加班费、加班');
    $visible = preg_replace('~<(script|style|textarea)\b.*?</\1>|<!--.*?-->|<[^>]*>~isu', '', $out);
    $check(!preg_match($banned, $visible), '页面可见文字里没有任何禁用词');
    $check(pt_filter_html('没有相关词的页面') === '没有相关词的页面', '无关页面原样返回');
    $check(pt_text('请假2天，按实际出勤26天') === '服务暂停2天，按实际到岗确认26天', 'pt_text 直接换词');

    // 真实页面：考勤月度页、规则中心规则说明
    $attendanceView = __DIR__ . '/../attendance/month/view/section_1.php';
    $check(is_file($attendanceView), '考勤页视图存在');
    $src = file_get_contents($attendanceView);
    $check(preg_match($banned, $src) === 1, '（前提）源码里仍保留老词，由页面输出统一替换');
    echo "\n=== 对外用语测试全部通过 ===\n";
} catch (Throwable $e) { fwrite(STDERR, $e->getMessage() . "\n"); exit(1); }

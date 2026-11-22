<?php
/**
 * 雅云已购 VPS：列表、详情、电源 / 重装 / 重置密码 / VNC / 救援 / 监控 / 续费 / 停用。接口与参数沿用 ZJMF-CBAP 的 UpstreamHostModel（已在线上验证过）。
 * 权限：有「服务器管理」权限的人可看列表、详情（不含密码）、开机 / 关机 / 重启 / 状态 / VNC / 监控；
 * 「可开通」的人（超级管理员 + 指定员工）才能续费、重装、重置密码、查看登录密码、强制断电、救援、停用。
 */
require_once __DIR__ . '/YayunApi.php';

function yh_status_labels()
{
    return ['Active' => '运行中', 'Pending' => '开通中', 'Suspended' => '已暂停', 'Terminated' => '已终止', 'Cancelled' => '已取消', 'Fraud' => '异常', 'Deleted' => '已删除'];
}
/** 需要「可开通」权限的动作。 */
function yh_manage_actions() { return ['hard_off', 'hard_reboot', 'reset_password', 'reinstall', 'rescue', 'exit_rescue', 'rescue_info', 'renew', 'cancel', 'password']; }

function yh_public_row(array $row)
{
    $due = (int)($row['nextduedate'] ?? 0);
    $status = (string)($row['domainstatus'] ?? '');
    $remark = trim((string)(($row['remark'] ?? '') !== '' ? $row['remark'] : ($row['notes'] ?? '')));
    return [
        'id' => (int)($row['id'] ?? 0), 'name' => (string)($row['domain'] ?? ''), 'product' => (string)($row['productname'] ?? ''), 'pid' => (int)($row['productid'] ?? $row['pid'] ?? 0),
        'status' => $status, 'status_text' => (string)($row['domainstatus_desc'] ?? (yh_status_labels()[$status] ?? $status)),
        'ip' => (string)($row['dedicatedip'] ?? ''), 'due_ts' => $due, 'due' => $due > 0 ? date('Y-m-d H:i', $due) : '', 'days_left' => $due > 0 ? (int)floor(($due - time()) / 86400) : null,
        'amount' => number_format((float)($row['amount'] ?? 0), 2, '.', ''), 'cycle' => (string)($row['cycle_desc'] ?? $row['billingcycle'] ?? ''),
        'os' => str_replace('^', ' · ', (string)($row['os'] ?? '')), 'notes' => (string)($row['notes'] ?? ''), 'remark' => $remark, 'created' => !empty($row['regdate']) ? date('Y-m-d', (int)$row['regdate']) : '',
        'order_id' => (int)($row['orderid'] ?? 0), 'auto_renew' => (int)($row['initiative_renew'] ?? 0) === 1,
    ];
}
/** 全部主机（分页取全，防止漏掉后面几页）；返回 [rows, 总数]。 */
function yh_list($call = 'ya_call')
{
    $rows = []; $total = null;
    for ($page = 1; $page <= 50; $page++) {
        $res = $call('host/list', ['page' => $page, 'limit' => 100], 'GET', 30);
        if ((int)($res['status'] ?? 0) !== 200 || !isset($res['data']['list']) || !is_array($res['data']['list'])) throw new RuntimeException('雅云主机列表读取失败：' . mb_substr((string)($res['msg'] ?? ''), 0, 100));
        $total = (int)($res['data']['sum'] ?? count($res['data']['list']));
        foreach ($res['data']['list'] as $r) if (is_array($r)) $rows[(int)($r['id'] ?? 0)] = yh_public_row($r);
        if (count($rows) >= $total || !$res['data']['list']) break;
    }
    if (count($rows) < $total) throw new RuntimeException('雅云主机列表不完整，请稍后重试');
    return [array_values($rows), $total];
}
/** 详情；$withSecret 为 true 才返回登录账号 / 密码（仅可开通的人）。 */
function yh_detail($id, $withSecret = false, $call = 'ya_call')
{
    $id = (int)$id;
    $res = $call('host/header', ['host_id' => $id], 'GET', 30);
    $row = $res['data']['host_data'] ?? [];
    if ((int)($res['status'] ?? 0) !== 200 || !is_array($row) || (int)($row['id'] ?? 0) !== $id) throw new RuntimeException('没有找到这台主机，或不属于当前雅云账号');
    $host = yh_public_row($row) + [
        'assigned_ips' => array_values((array)($row['assignedips'] ?? [])), 'port' => (string)($row['port'] ?? ''), 'username' => $withSecret ? (string)($row['username'] ?? '') : '',
        'password' => $withSecret ? (string)($row['password'] ?? '') : '', 'first_amount' => number_format((float)($row['firstpaymentamount'] ?? 0), 2, '.', ''),
        'cycle' => (string)($row['billingcycle_desc'] ?? $row['billingcycle'] ?? ''), 'active' => !empty($row['regdate']) ? date('Y-m-d H:i', (int)$row['regdate']) : '',
    ];
    $groups = [];
    foreach ((array)($res['data']['cloud_os_group'] ?? []) as $g) $groups[(int)($g['id'] ?? 0)] = ['id' => (int)($g['id'] ?? 0), 'name' => (string)($g['name'] ?? ''), 'images' => []];
    foreach ((array)($res['data']['cloud_os'] ?? []) as $img) { $gid = (int)($img['group'] ?? 0); if (isset($groups[$gid]) && isset($img['id'])) $groups[$gid]['images'][] = ['id' => (int)$img['id'], 'name' => (string)($img['name'] ?? '')]; }
    $host['os_groups'] = array_values(array_filter($groups, function ($g) { return !empty($g['images']); }));
    return $host;
}
function yh_password_ok($p) { return strlen($p) >= 8 && strlen($p) <= 30 && preg_match('/^[A-Za-z0-9!@#$%^&*()_+\-=\[\]{};:,.?]+$/', $p); }

/** 在已确认属于本账号的主机上执行动作。返回 [提示, 数据]。 */
function yh_action($id, $action, array $in, $call = 'ya_call')
{
    $funcs = ['on' => 'on', 'off' => 'off', 'reboot' => 'reboot', 'hard_off' => 'hard_off', 'hard_reboot' => 'hard_reboot', 'reset_password' => 'crack_pass', 'reinstall' => 'reinstall', 'vnc' => 'vnc', 'status' => 'status', 'rescue' => 'rescue_system', 'exit_rescue' => 'exitRescue', 'rescue_info' => 'remoteInfo'];
    if (!isset($funcs[$action])) throw new InvalidArgumentException('不支持此操作');
    yh_detail($id, false, $call); // 先确认主机属于本账号
    $data = ['func' => $funcs[$action], 'is_api' => true, 'id' => (int)$id];
    if ($action === 'reset_password') {
        if (!yh_password_ok((string)($in['password'] ?? ''))) throw new InvalidArgumentException('密码需为 8-30 位，仅限字母、数字和常用符号');
        $data['password'] = (string)$in['password'];
    } elseif ($action === 'rescue') {
        $type = (int)($in['type'] ?? 0); $pw = (string)($in['temp_pass'] ?? '');
        if (!in_array($type, [1, 2], true) || !yh_password_ok($pw)) throw new InvalidArgumentException('请选择救援系统并设置 8-30 位临时密码');
        $data['system'] = $type; $data['temp_pass'] = $pw;
    } elseif ($action === 'reinstall') {
        $os = (int)($in['os'] ?? 0);
        if ($os < 1 || ($in['confirm'] ?? '') !== (string)(int)$id) throw new InvalidArgumentException('重装会清空系统盘，请选择系统并输入主机编号确认');
        $data['os'] = $os; $data['port'] = preg_replace('/\D/', '', (string)($in['port'] ?? '')); $data['format_data_disk'] = !empty($in['format_data_disk']) ? 1 : 0;
    }
    $custom = in_array($action, ['exit_rescue', 'rescue_info'], true);
    $res = $call($custom ? 'provision/custom/' . (int)$id : 'provision/default', $data, 'POST', 60);
    if ((int)($res['status'] ?? 0) !== 200) throw new RuntimeException(mb_substr((string)($res['msg'] ?? '雅云操作失败'), 0, 150));
    $out = [];
    if ($action === 'vnc') {
        $url = (string)($res['data']['url'] ?? '');
        if (!preg_match('#^https://#i', $url)) throw new RuntimeException('雅云没有返回控制台地址');
        $out = ['url' => $url];
    } elseif ($action === 'status') $out = ['status' => (string)($res['data']['status'] ?? ''), 'desc' => (string)($res['data']['des'] ?? $res['data']['desc'] ?? '')];
    elseif ($action === 'rescue_info') $out = ['rescue' => (int)($res['data']['rescue'] ?? 0) === 1];
    return [(string)($res['msg'] ?? '已提交'), $out];
}
function yh_chart($id, $type, $call = 'ya_call')
{
    if (!in_array($type, ['cpu', 'disk', 'memory', 'flow'], true)) throw new InvalidArgumentException('不支持的监控类型');
    yh_detail($id, false, $call);
    $end = time() * 1000;
    $res = $call('provision/chart/' . (int)$id, ['type' => $type, 'start' => $end - 24 * 3600 * 1000, 'end' => $end, 'select' => '', 'is_api' => true], 'GET', 60);
    if ((int)($res['status'] ?? 0) !== 200) throw new RuntimeException(mb_substr((string)($res['msg'] ?? '监控读取失败'), 0, 100));
    return ['list' => array_slice((array)($res['data']['list'] ?? []), -288), 'unit' => (string)($res['data']['unit'] ?? '')];
}
/** 续费报价（可选周期与金额）。 */
function yh_renew_info($id, $call = 'ya_call')
{
    yh_detail($id, false, $call);
    $res = $call('host/renewpage', ['hostid' => (int)$id], 'GET', 30);
    if ((int)($res['status'] ?? 0) !== 200) throw new RuntimeException(mb_substr((string)($res['msg'] ?? '续费报价读取失败'), 0, 100));
    $cycles = [];
    foreach ((array)($res['data']['cycle'] ?? []) as $c) if (!empty($c['billingcycle'])) $cycles[] = ['cycle' => (string)$c['billingcycle'], 'duration' => (string)($c['duration'] ?? ''), 'amount' => number_format((float)($c['amount'] ?? 0), 2, '.', '')];
    return $cycles;
}
/** 续费并用雅云余额支付。只允许当前报价里存在的周期，金额以服务端重新取的报价为准。 */
function yh_renew($id, $cycle, $confirm, $call = 'ya_call')
{
    if ($confirm !== (string)(int)$id) throw new InvalidArgumentException('请输入主机编号确认续费');
    $quote = null;
    foreach (yh_renew_info($id, $call) as $c) if ($c['cycle'] === $cycle) $quote = $c;
    if (!$quote) throw new InvalidArgumentException('该周期当前不可续费，请刷新后重试');
    $res = $call('host/renew', ['billingcycles' => $cycle, 'hostid' => (int)$id], 'POST', 30);
    if ((int)($res['status'] ?? 0) !== 200 || empty($res['data']['invoiceid'])) throw new RuntimeException('续费账单生成失败：' . mb_substr((string)($res['msg'] ?? ''), 0, 100));
    $pay = $call('apply_credit', ['invoiceid' => $res['data']['invoiceid'], 'use_credit' => 1], 'POST', 30);
    if ((int)($pay['status'] ?? 0) !== 1001) throw new RuntimeException('续费账单已生成（金额 ¥' . $quote['amount'] . '）但余额支付未完成：' . mb_substr((string)($pay['msg'] ?? ''), 0, 100) . '。请到雅云后台处理该账单，不要重复续费');
    return $quote;
}
function yh_cancel($id, $type, $reason, $confirm, $call = 'ya_call')
{
    $reason = trim($reason);
    if (!in_array($type, ['Immediate', 'Endofbilling'], true) || $reason === '' || mb_strlen($reason) > 200 || $confirm !== (string)(int)$id) throw new InvalidArgumentException('请选择停用方式、填写原因，并输入主机编号确认');
    yh_detail($id, false, $call);
    $res = $call('host/cancel', ['id' => (int)$id, 'type' => $type, 'reason' => $reason], 'POST', 30);
    if ((int)($res['status'] ?? 0) !== 200) throw new RuntimeException(mb_substr((string)($res['msg'] ?? '停用申请失败'), 0, 120));
    return (string)($res['msg'] ?? '停用申请已提交');
}
/** 修改主机备注名称（同步写入雅云 host/remark）。 */
function yh_remark($id, $remark, $call = 'ya_call')
{
    $id = (int)$id;
    $remark = trim((string)$remark);
    if (mb_strlen($remark) > 100) throw new InvalidArgumentException('备注名称最多 100 个字');
    yh_detail($id, false, $call);
    $res = $call('host/remark', ['id' => $id, 'remark' => $remark], 'POST', 30);
    if ((int)($res['status'] ?? 0) !== 200) throw new RuntimeException(mb_substr((string)($res['msg'] ?? '备注保存失败'), 0, 120));
    return ['id' => $id, 'remark' => $remark, 'message' => $remark === '' ? '备注已清空' : '备注已保存'];
}

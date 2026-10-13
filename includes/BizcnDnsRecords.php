<?php
require_once __DIR__ . '/BizcnDomainValues.php';

/**
 * 改了解析记录的类型或主机记录：商务中国靠“类型 + 主机 + 旧值”定位记录、没有改类型的接口，只能新增 + 删除。
 * - 一般情况先新增新记录，成功后再删旧记录；新增失败时旧记录原样保留。
 * - 同一主机上 CNAME 与 A / AAAA 互斥（商务中国：A Resolverecord is Exist），必须先删旧再加新；
 *   新记录加不上时立刻把旧记录恢复回去，恢复也失败则明确告知要手动补回的记录。
 * $io 仅供测试注入：['call' => 接口调用, 'audit' => 审计函数]。返回提示语。
 */
function bd_record_replace($domain, array $old, array $new, $actor, array $io = [])
{
    $call = $io['call'] ?? 'bd_call';
    $audit = $io['audit'] ?? function ($action, $context) use ($actor) { ps_audit('domain', 0, $action, $actor, $context); };
    $live = $call('infoDnsRecordService', 'infoDnsRecord', ['domainname' => $domain]);
    $foundOld = false; $duplicate = false; $conflicts = [];
    foreach (bd_rows($live['records']['record'] ?? []) as $row) {
        $type = strtoupper((string)$row['type']); $host = (string)$row['host']; $value = (string)$row['value'];
        $isOld = strcasecmp($host, $old['resolvehost']) === 0 && $type === $old['resolvetype'] && $value === $old['resolveoldvalue'];
        if ($isOld) $foundOld = true;
        if ($host === $new['resolvehost'] && $type === $new['resolvetype'] && $value === $new['resolvevalue']) $duplicate = true;
        if (!$isOld && strcasecmp($host, $new['resolvehost']) === 0 && ($new['resolvetype'] === 'CNAME' || $type === 'CNAME')) $conflicts[] = $type . ' ' . $host;
    }
    if (!$foundOld) throw new RuntimeException('原解析记录已变化，请刷新后再修改，避免覆盖他人操作');
    if ($duplicate) throw new RuntimeException('改成的这条解析记录已存在，无需重复添加');
    if ($conflicts) throw new RuntimeException('同一主机还有其它与 CNAME 冲突的记录（' . implode('、', array_unique($conflicts)) . '），本次没有修改任何记录。请先核对这些记录，不能直接覆盖');
    $oldLabel = $old['resolvetype'] . ' ' . $old['resolvehost'];
    $context = ['domain' => $domain, 'from' => $oldLabel, 'to' => $new['resolvetype'] . ' ' . $new['resolvehost']];
    $deleteFirst = strcasecmp($old['resolvehost'], $new['resolvehost']) === 0
        && (($old['resolvetype'] === 'CNAME') !== ($new['resolvetype'] === 'CNAME'));
    $audit('record_replace_requested', $context + ['order' => $deleteFirst ? 'delete_first' : 'add_first']);
    if (!$deleteFirst) {
        $call('addDnsRecordService', 'addDnsRecord', ['add' => $new]);
        try {
            $call('delDnsRecordService', 'delDnsRecord', ['del' => $old]);
        } catch (RuntimeException $e) {
            $audit('record_replace_partial', $context);
            throw new RuntimeException('新记录已添加，但旧记录删除失败（' . $e->getMessage() . '），请在列表里手动删除旧的 ' . $oldLabel . ' 记录');
        }
        $audit('record_replace_success', $context);
        return '解析记录已成功提交（已新增新记录并删除旧记录）';
    }
    $call('delDnsRecordService', 'delDnsRecord', ['del' => $old]);
    try {
        $call('addDnsRecordService', 'addDnsRecord', ['add' => $new]);
    } catch (RuntimeException $e) {
        $restore = ['domainname' => $old['domainname'], 'resolvetype' => $old['resolvetype'], 'resolvehost' => $old['resolvehost'], 'resolvevalue' => $old['resolveoldvalue'], 'mxlevel' => $old['mxlevel'] ?? '10'];
        $restored = false;
        try { $call('addDnsRecordService', 'addDnsRecord', ['add' => $restore]); $restored = true; } catch (RuntimeException $ignored) { }
        $audit($restored ? 'record_replace_rolled_back' : 'record_replace_rollback_failed', $context);
        throw new RuntimeException($restored
            ? '改成 ' . $new['resolvetype'] . ' 失败（' . $e->getMessage() . '），已自动恢复原来的 ' . $oldLabel . ' 记录，解析没有变化'
            : '改成 ' . $new['resolvetype'] . ' 失败（' . $e->getMessage() . '），且原记录恢复失败！请立即手动重新添加：' . $oldLabel . ' ' . $old['resolveoldvalue']);
    }
    $audit('record_replace_success', $context);
    return '解析记录已成功提交（同一主机上 ' . $old['resolvetype'] . ' 与 ' . $new['resolvetype'] . ' 不能并存，已先删除旧记录再添加新记录）';
}

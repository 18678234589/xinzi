<?php

/** 本栏目只认已登录合作人员对应的员工 ID；财务管理员身份不自动取得权限。 */
function pg_member($actor)
{
    if (!$actor || ($actor['type'] ?? '') !== 'employee' || (int)($actor['employee_id'] ?? 0) < 1) return null;
    $q = db()->prepare('SELECT m.employee_id,m.governance_role,e.name FROM project_governance_members m JOIN employees e ON e.id=m.employee_id WHERE m.employee_id=? AND m.is_active=1 LIMIT 1'
    );
    $q->execute([(int)$actor['employee_id']]);
    return $q->fetch() ?: null;
}

function pg_require_member()
{
    $actor = ps_require_actor();
    $member = pg_member($actor);
    if (!$member) { http_response_code(403); exit('无权限查看管理层激励考核'); }
    return [$actor, $member];
}

/**
 * 建议 / Bug / 主动做事奖励台账：财务（管理员）与监委会成员可录入、补凭证、登记发放；其他人 403。
 * 返回 [$actor, 显示名, 是否财务]。
 */
function pg_require_contribution_editor()
{
    $actor = ps_require_actor();
    if (($actor['type'] ?? '') === 'admin') {
        $q = db()->prepare('SELECT username FROM admins WHERE id=?');
        $q->execute([(int)$actor['id']]);
        return [$actor, '财务 ' . ($q->fetchColumn() ?: '#' . (int)$actor['id']), true];
    }
    $member = pg_member($actor);
    if (!$member || $member['governance_role'] !== 'committee') { http_response_code(403); exit('仅财务与监委会可录入建议 / Bug 奖励'); }
    return [$actor, $member['name'], false];
}

/** 录入人字段：管理员写 admin_id，合作人员写 employee_id；系统补录两者皆空。 */
function pg_actor_columns($actor)
{
    if (($actor['type'] ?? '') === 'admin') return [null, (int)$actor['id']];
    return [(int)($actor['employee_id'] ?? 0) > 0 ? (int)$actor['employee_id'] : null, null];
}

/** 多文件上传（name="evidence_files[]"）拆成单个文件数组。 */
function pg_uploaded_files($field)
{
    $files = [];
    if (!isset($field['name'])) return $files;
    if (!is_array($field['name'])) return [$field];
    foreach ($field['name'] as $i => $name) {
        $files[] = ['name' => $name, 'type' => $field['type'][$i] ?? '', 'tmp_name' => $field['tmp_name'][$i] ?? '', 'error' => $field['error'][$i] ?? UPLOAD_ERR_NO_FILE, 'size' =>
    $field['size'][$i] ?? 0];
    }
    return $files;
}

function pg_kind_label($kind)
{
    return ['chair' => '轮值董事长事项', 'committee' => '监委会监督', 'contribution' => '建议 / Bug / 主动做事'][$kind] ?? '其他';
}

function pg_review_label($state)
{
    return ['pending' => '待监委核验', 'approved' => '已核验', 'rejected' => '已退回'][$state] ?? '待核验';
}

function pg_can_review($member, $record)
{
    return $member && $member['governance_role'] === 'committee'
        && (int)$member['employee_id'] !== (int)$record['owner_employee_id']
        && (int)$member['employee_id'] !== (int)($record['created_by_employee_id'] ?? 0)
        && $record['review_state'] === 'pending';
}

function pg_validate_date($date, $required = false)
{
    $date = trim((string)$date);
    if ($date === '') {
        if ($required) throw new RuntimeException('请填写记录日期');
        return null;
    }
    $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
    if (!$parsed || $parsed->format('Y-m-d') !== $date) throw new RuntimeException('日期格式不正确');
    return $date;
}

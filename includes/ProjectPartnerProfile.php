<?php
/**
 * 合作人员结算资料（主管、工行卡号、签名确认、买保险公司、已扣除预留金）。
 * 只有财务（管理员登录）和管理层账号能查看 / 修改；合作人员本人也看不到，报酬表里同样只对这两类账号显示。
 */
require_once __DIR__ . '/ProjectSettlement.php';

/** 谁能看全部人员的报酬、以及结算资料：财务（管理员登录）和管理层账号。 */
function pps_sees_all($actor)
{
    return is_array($actor) && (($actor['type'] ?? '') === 'admin' || ($actor['role'] ?? '') === 'finance' || ($actor['account_role'] ?? '') === 'management');
}

function ppp_can($actor)
{
    return pps_sees_all($actor);
}

function ppp_ensure()
{
    static $done = false;
    if ($done) return;
    db()->exec("CREATE TABLE IF NOT EXISTS project_partner_profiles (
        employee_id INT NOT NULL PRIMARY KEY, supervisor VARCHAR(40) NOT NULL DEFAULT '', bank_card VARCHAR(32) NOT NULL DEFAULT '',
        signed TINYINT(1) NOT NULL DEFAULT 0, insurance_company VARCHAR(60) NOT NULL DEFAULT '', reserve_deducted DECIMAL(12,2) NULL,
        updated_by VARCHAR(60) NOT NULL DEFAULT '', updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $done = true;
}

/** [employee_id => 资料行]；没有资料的人不在结果里。 */
function ppp_all()
{
    ppp_ensure();
    $out = [];
    foreach (db()->query('SELECT * FROM project_partner_profiles')->fetchAll() as $r) $out[(int)$r['employee_id']] = $r;
    return $out;
}

function ppp_blank()
{
    return ['supervisor' => '', 'bank_card' => '', 'signed' => 0, 'insurance_company' => '', 'reserve_deducted' => null];
}

/** 校验并保存一个人的结算资料（只改传入的这五项）。返回保存后的行。 */
function ppp_save($employeeId, array $in, $actor)
{
    if (!ppp_can($actor)) throw new RuntimeException('仅财务和管理员可以修改合作人员结算资料');
    ppp_ensure();
    $exists = db()->prepare('SELECT COUNT(*) FROM employees WHERE id=?');
    $exists->execute([(int)$employeeId]);
    if (!(int)$exists->fetchColumn()) throw new RuntimeException('合作人员不存在');
    $card = preg_replace('/[\s\-]+/u', '', trim((string)($in['bank_card'] ?? '')));
    if ($card !== '' && !preg_match('/^\d{12,25}$/', $card)) throw new RuntimeException('工行卡号应为 12–25 位数字');
    $supervisor = trim((string)($in['supervisor'] ?? ''));
    $company = trim((string)($in['insurance_company'] ?? ''));
    if (mb_strlen($supervisor) > 40 || mb_strlen($company) > 60) throw new RuntimeException('主管或买保险公司过长');
    $reserveText = trim((string)($in['reserve_deducted'] ?? ''));
    if ($reserveText !== '' && !preg_match('/^\d{1,9}(?:\.\d{1,2})?$/', $reserveText)) throw new RuntimeException('已扣除预留金须为非负金额，最多两位小数');
    $row = ['supervisor' => $supervisor, 'bank_card' => $card, 'signed' => empty($in['signed']) ? 0 : 1, 'insurance_company' => $company, 'reserve_deducted' => $reserveText === '' ? null : $reserveText];
    db()->prepare('INSERT INTO project_partner_profiles (employee_id,supervisor,bank_card,signed,insurance_company,reserve_deducted,updated_by) VALUES (?,?,?,?,?,?,?)
        ON DUPLICATE KEY UPDATE supervisor=VALUES(supervisor),bank_card=VALUES(bank_card),signed=VALUES(signed),insurance_company=VALUES(insurance_company),reserve_deducted=VALUES(reserve_deducted),updated_by=VALUES(updated_by)')
        ->execute([(int)$employeeId, $supervisor, $card, $row['signed'], $company, $row['reserve_deducted'], ($actor['type'] ?? '') . ':' . (int)($actor['id'] ?? 0)]);
    // 审计里不留完整卡号，只记尾号
    ps_audit('partner_profile', (int)$employeeId, 'save', $actor, ['supervisor' => $supervisor, 'card_tail' => $card === '' ? '' : substr($card, -4), 'signed' => $row['signed'], 'insurance_company' => $company, 'reserve' => $row['reserve_deducted']]);
    return $row;
}

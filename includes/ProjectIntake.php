<?php
require_once __DIR__ . '/ProjectSettlement.php';

function ps_intake_templates($category = null)
{
    $sql = 'SELECT * FROM project_cost_templates WHERE is_active=1 AND requires_proof=0';
    $params = [];
    if ($category !== null) { $sql .= ' AND category=?'; $params[] = $category; }
    $sql .= ' ORDER BY category,name,specification,id DESC';
    $q = db()->prepare($sql);
    $q->execute($params);
    return $q->fetchAll();
}

function ps_intake_template($id, $category)
{
    $q = db()->prepare('SELECT * FROM project_cost_templates WHERE id=? AND category=? AND is_active=1 AND requires_proof=0');
    $q->execute([(int)$id, $category]);
    $template = $q->fetch();
    if (!$template) throw new RuntimeException('所选' . ($category === 'domain' ? '域名' : '资源') . '成本模板不可用，请刷新后重选');
    return $template;
}

function ps_intake_add_template_cost($orderId, $template, $actor, $origin)
{
    $price = (string)$template['price'];
    $status = ((int)$template['auto_approve'] === 1 && (float)$price <= 500) ? 'approved' : 'pending';
    $q = db()->prepare('INSERT INTO project_costs (order_id,template_id,template_version,category,item_name,quantity,unit,unit_price,amount,cost_kind,is_custom,reason,review_status,submitted_by_employee) VALUES (?,?,?,?,?,1,?,?,?,?,0,?,?,?)');
    $q->execute([(int)$orderId, (int)$template['id'], (int)$template['version'], $template['category'], $template['name'] . ($template['specification'] ? ' · ' . $template['specification'] : ''), $template['unit'], $price, $price, $template['cost_kind'], $origin, $status, $actor['employee_id'] ?? null]);
    $costId = (int)db()->lastInsertId();
    ps_audit('cost', $costId, 'create_from_intake', $actor, ['order_id' => (int)$orderId, 'template_id' => (int)$template['id'], 'price' => $price, 'status' => $status, 'origin' => $origin]);
    return $costId;
}

function ps_intake_save_resources($orderId, $sourceType, $sourceLine, $domainTemplate, $serverTemplate, $sslAmount, $domainMode = null)
{
    $q = db()->prepare('INSERT INTO project_order_resources (order_id,source_type,source_line,domain_mode,domain_template_id,server_template_id,ssl_expected_amount) VALUES (?,?,?,?,?,?,?)');
    $q->execute([(int)$orderId, $sourceType, $sourceLine, $domainMode ?? ($domainTemplate ? 'template' : 'none'), $domainTemplate['id'] ?? null, $serverTemplate['id'] ?? null, $sslAmount !== null && (float)$sslAmount > 0 ? round((float)$sslAmount, 2) : null]);
}

function ps_intake_participants($orderId, $groups)
{
    $insert = db()->prepare('INSERT INTO project_participants (order_id,employee_id,commission_group,role_name,group_weight) VALUES (?,?,?,?,?)');
    $check = db()->prepare('SELECT 1 FROM employees WHERE id=?');
    foreach ($groups as $group => $people) {
        if (!$people) continue;
        $people = array_values($people);
        $count = count($people);
        $baseWeight = intdiv(1000000, $count);
        foreach ($people as $index => $person) {
            $employeeId = (int)$person['id'];
            $check->execute([$employeeId]);
            if (!$check->fetchColumn()) throw new RuntimeException('参与人不存在，请重新选择');
            $weight = ($index === $count - 1 ? 1000000 - $baseWeight * ($count - 1) : $baseWeight) / 1000000;
            $insert->execute([(int)$orderId, $employeeId, $group, (string)$person['role'], $weight]);
        }
    }
}

function ps_intake_domain_suggestion($resourceNote, $templates)
{
    $resourceNote = mb_strtolower(trim((string)$resourceNote));
    if (!preg_match('/\.(com|cn|net|org|top|xyz)(?:\b|\/|：|:|，|,|\s|$)/iu', $resourceNote, $match)) return null;
    $suffix = '.' . strtolower($match[1]);
    $matches = [];
    foreach ($templates as $template) {
        $label = mb_strtolower($template['name'] . ' ' . $template['specification']);
        if (strpos($label, $suffix) === false) continue;
        if ($template['cost_kind'] !== 'annual') continue;
        if (!preg_match('/(?:1\s*年|一年|12\s*个月|12\s*月)/u', $label)) continue;
        $matches[] = $template;
    }
    return count($matches) === 1 ? $matches[0] : null;
}

function ps_import_date($value)
{
    $value = trim((string)$value);
    if (is_numeric($value) && (float)$value > 30000) return gmdate('Y-m-d', ((int)$value - 25569) * 86400);
    $time = strtotime(str_replace(['年','月','日','/','.'], ['-','-','','-','-'], $value));
    return $time ? date('Y-m-d', $time) : null;
}

function ps_import_names($value, $employeesByName)
{
    $out = [];
    foreach (preg_split('/[,，、\/]+/u', trim((string)$value)) as $name) {
        $name = trim($name);
        if ($name === '' || $name === '无') continue;
        if (!isset($employeesByName[$name]) || count($employeesByName[$name]) !== 1) throw new RuntimeException('合作人员“' . $name . '”未找到或重名，请财务先核对');
        $id = (int)$employeesByName[$name][0]['id'];
        $out[$id] = $name;
    }
    return $out;
}

function ps_import_domain_mode($text)
{
    $text = mb_strtolower(trim((string)$text));
    if (in_array($text, ['否','无','无需','无需域名','no','n'], true)) return 'none';
    if (in_array($text, ['是','有','yes','y'], true)) return 'template';
    return '';
}

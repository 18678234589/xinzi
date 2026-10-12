<?php
/** 只识别文档中确定的网站产品标记，不把制作要求中的“定制”泛化为业务类型。 */
function ps_import_website_business($selectedBusiness, $programName, $technicalText, $allowedBusinesses)
{
    if (!in_array($selectedBusiness, ['网站模板', 'AI网站定制'], true)) return $selectedBusiness;
    $program = trim((string)$programName);
    $candidate = $selectedBusiness;
    if (preg_match('/^(博山定制|华梦|大连定制|网站定制|AI[网站开发]*定制)/iu', $program)) $candidate = 'AI网站定制';
    elseif (preg_match('/^(php|jsp|森动)/iu', $program)) $candidate = '网站模板';
    elseif (preg_match('/刘帅|李仁超|孙磊|崔鑫栋|于海波|李子晖/u', (string)$technicalText)
        && !preg_match('/光君|孙妍|张强/u', (string)$technicalText)) $candidate = 'AI网站定制';
    return in_array($candidate, $allowedBusinesses, true) ? $candidate : $selectedBusiness;
}

/**
 * 森动备案只有“项目执行”岗位配了分成规则（一人包办）。客服列又写了同一个人（旧模板两列都填本人）时不再重复记一份——
 * 那份没有规则，订单上会一直显示“待配置”；只写了客服列的人按“项目执行”记。返回 [人员, 提示]。
 */
function ps_import_filing_people($people, $technicalLabel = '项目执行')
{
    $note = '';
    foreach ($people['customer_service'] as $id => $person) {
        if (isset($people['technical'][$id])) unset($people['customer_service'][$id]);
    }
    if (!$people['technical'] && $people['customer_service']) {
        foreach ($people['customer_service'] as $id => $person) $people['technical'][$id] = ['id' => $id, 'role' => $technicalLabel, 'name' => $person['name']];
        $people['customer_service'] = [];
        $note = '森动备案只有“' . $technicalLabel . '”岗位有分成规则，“客服”列的人已按“' . $technicalLabel . '”记入';
    }
    return [$people, $note];
}

function ps_import_website_people_roles($people, $business)
{
    if ($business !== 'AI网站定制') return $people;
    foreach ($people['technical'] as &$person) {
        $name = $person['name'];
        $currentRole = (string)($person['role'] ?? '');
        $hasFront = (mb_strpos($currentRole, '前') !== false || mb_strpos($currentRole, '制作') !== false);
        $hasBack = (mb_strpos($currentRole, '后') !== false);
        if ($hasFront && $hasBack) {
            if (in_array($name, ['孙磊', '李仁超'], true) || mb_strpos($currentRole, '外包') !== false) {
                $person['role'] = '外包前端/后端';
            } else {
                $person['role'] = '前端/后端';
            }
            continue;
        }
        if ($hasFront && !$hasBack) {
            $person['role'] = (in_array($name, ['孙磊', '李仁超'], true) || mb_strpos($currentRole, '外包') !== false) ? '外包前端' : '前端';
            continue;
        }
        if ($hasBack && !$hasFront) {
            $person['role'] = '后端';
            continue;
        }
        if (in_array($name, ['孙磊', '李仁超'], true)) $person['role'] = '外包前端';
        elseif (in_array($name, ['崔鑫栋', '于海波'], true)) $person['role'] = '后端';
        elseif ($name === '李子晖') $person['role'] = '售后';
        elseif ($name === '刘帅') $person['role'] = '前端';
    }
    unset($person);
    return $people;
}

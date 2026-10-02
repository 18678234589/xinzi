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

function ps_import_website_people_roles($people, $business)
{
    if ($business !== 'AI网站定制') return $people;
    foreach ($people['technical'] as &$person) {
        $name = $person['name'];
        if (in_array($name, ['孙磊', '李仁超'], true)) $person['role'] = '外包前端';
        elseif (in_array($name, ['崔鑫栋', '于海波'], true)) $person['role'] = '后端';
        elseif ($name === '李子晖') $person['role'] = '售后';
        elseif ($name === '刘帅') $person['role'] = '前端';
    }
    unset($person);
    return $people;
}

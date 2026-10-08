<?php
function renderModuleForm($idx, $type, $cfg)
{
    global $allTypes, $deptFeeRate;
    $info = $allTypes[$type] ?? $allTypes['standard'];
    $html = '';

    foreach ($info['fields'] as $f) {
        $val = $cfg[$f['key']] ?? ($f['default'] ?? '');
        $step = $f['step'] ?? 'any';
        $ph   = $f['placeholder'] ?? '';
        $extraAttrs = "class=\"form-control form-control-sm\" name=\"mod_cfg[{$f['key']}][{$idx}]\" step=\"{$step}\"";

        // service_fee_rate 字段：当配置值为 0 且部门有费率时，预填部门费率作为默认提示
        $prefilledFromDept = false;
        if ($f['key'] === 'service_fee_rate' && (float)$val === 0 && $deptFeeRate > 0) {
            $val = $deptFeeRate;
            $prefilledFromDept = true;
        }

        // 模块名称字段特殊处理：放在最前面，突出显示
        if (($f['key'] === '_name')) {
            $displayName = is_numeric($val) ? rtrim(rtrim(number_format((float)$val,4,'.',''),'0'),'.') : e($val);
            $html .= "<div class=\"form-group bg-warning bg-light p-2 rounded border-left-4 border-left-warning\">";
            $html .= "<label><strong>{$f['label']}</strong> ";
            $html .= "<small class=\"text-muted\">上传订单时下拉框会显示此名称</small></label>";
            $html .= "<div class=\"input-group\"><div class=\"input-group-prepend\"><span class=\"input-group-text bg-warning text-dark\"><i class=\"fas fa-tag\"></i></span></div>"
    ;
            $html .= "<input type=\"text\" {$extraAttrs} value=\"" . $displayName . "\" placeholder=\"{$ph}\"></div>";
            $html .= "</div>";
        } elseif ($f['type'] === 'number') {
            $html .= "<div class=\"form-group\"><label>{$f['label']}</label>";
            $suffix = strpos(strtolower($f['key']), 'rate') !== false ? '<span class="input-group-append"><span class="input-group-text">%</span></span>' :
                     (strpos(strtolower($f['label']), '金额')!==false ? '<div class="input-group-prepend"><span class="input-group-text">¥</span></div>' :'');
            $valDisplay = is_numeric($val) ? rtrim(rtrim(number_format((float)$val,4,'.',''),'0'),'.') : $val;
            $html .= "<div class=\"input-group\">{$suffix}<input type=\"{$f['type']}\" {$extraAttrs} value=\"" . e($valDisplay) . "\" placeholder=\"{$ph}\"></div>";
            $desc = $f['desc'] ?? '';
            if ($prefilledFromDept) {
                $desc = '<span class="text-info"><i class="fas fa-info-circle"></i> 已从部门配置(dept_fee.php)预填 ' . ($deptFeeRate * 100) . '%，保存后同步到算法配置</span>'
    ;
            }
            $html .= "<small class=\"text-muted\">{$desc}</small></div>";
        } elseif ($f['type'] === 'select') {
            $html .= "<div class=\"form-group\"><label>{$f['label']}</label>";
            $html .= "<select {$extraAttrs}>";
            $options = $f['options'] ?? [];
            foreach ($options as $ov => $ol) {
                $sel = ((string)$ov === (string)$val) ? ' selected' : '';
                $html .= "<option value=\"" . e($ov) . "\"{$sel}>" . e($ol) . "</option>";
            }
            $html .= "</select>";
            $desc = $f['desc'] ?? '';
            $html .= "<small class=\"text-muted\">{$desc}</small></div>";
        } else {
            // 其他类型（text等）
            $html .= "<div class=\"form-group\"><label>{$f['label']}</label>";
            $html .= "<input type=\"{$f['type']}\" {$extraAttrs} value=\"" . e($val) . "\" placeholder=\"{$ph}\"></div>";
        }
    }

    // 阶梯类型特殊处理：显示已有的 tiers
    if ($type === 'tiered' || $type === 'mixed' || $type === 'base_salary_tiered') {
        $tiers = $cfg['tiers'] ?? [];
        if (empty($tiers)) {
            $tiers = [['threshold'=>10000,'rate'=>0.05,'subsidy'=>0],['threshold'=>20000,'rate'=>0.08,'subsidy'=>0],['threshold'=>30000,'rate'=>0.12,'subsidy'=>0]];
        }

        // 根据类型显示不同的字段
        if ($type === 'base_salary_tiered') {
            $html .= '<div class="mt-2" id="tierArea-'.$idx.'"><strong>阶梯规则：</strong>';
            $html .= '<button type="button" class="btn btn-outline-info btn-xs" onclick="addBaseTierRowForForm('.$idx.')">+ 新增档次</button>';
            $html .= '<div id="tierContainer-'.$idx.'" class="mt-2">';
            foreach ($tiers as $ti => $t) {
                $html .= '<div class="tier-row row">'.
                    '<div class="col-5"><label>订单总额≥</label>'.
                    '<input type="number" class="form-control form-control-sm tier-threshold" name="t_threshold['.$idx.']['.$ti.']" value="'.$t['threshold'].'" step="any"></div>'.
                    '<div class="col-5"><label>固定服务费金额</label><div class="input-group input-group-sm">'.
                    '<div class="input-group-prepend"><span class="input-group-text">¥</span></div>'.
                    '<input type="number" class="form-control tier-base-amount" name="t_base_amount['.$idx.']['.$ti.']" value="'.($t['base_amount']??0).'" step="any" min="0"></div></div>'
    .
                    '<div class="col-2 d-flex align-items-end"><button type="button" class="btn btn-outline-danger btn-sm tier-rm-form">×</button></div></div>';
            }
            $html .= '</div></div>';
        } else {
            $html .= '<div class="mt-2" id="tierArea-'.$idx.'"><strong>阶梯规则：</strong>';
            $html .= '<button type="button" class="btn btn-outline-info btn-xs" onclick="addTierRowForForm('.$idx.')">+ 新增档次</button>';
            $html .= '<div id="tierContainer-'.$idx.'" class="mt-2">';
            foreach ($tiers as $ti => $t) {
                $html .= '<div class="tier-row row">'.
                    '<div class="col-3"><label>订单≥</label>'.
                    '<input type="number" class="form-control form-control-sm tier-threshold" name="t_threshold['.$idx.']['.$ti.']" value="'.$t['threshold'].'" step="any"></div>'.
                    '<div class="col-3"><label>比例</label><div class="input-group input-group-sm">'.
                    '<input type="number" class="form-control tier-rate" name="t_rate['.$idx.']['.$ti.']" value="'.$t['rate'].'" step="0.0001" min="0" max="1">'.
                    '<span class="input-group-append"><span class="input-group-text">%</span></span></div></div>'.
                    '<div class="col-3"><label>单量补贴</label><div class="input-group input-group-sm">'.
                    '<div class="input-group-prepend"><span class="input-group-text">¥</span></div>'.
                    '<input type="number" class="form-control tier-subsidy" name="t_subsidy['.$idx.']['.$ti.']" value="'.($t['subsidy']??0).'" step="0.1" min="0"></div></div>'.
                    '<div class="col-2 d-flex align-items-end"><button type="button" class="btn btn-outline-danger btn-sm tier-rm-form">×</button></div></div>';
            }
            $html .= '</div></div>';
        }
    }

    return $html;
}

<?php
/** 共同接单只采用明确选择的人，不由 AI 猜姓名；重复勾选同一人不增加权重。 */
function ps_joint_customer_ids($values, array $allowedIds)
{
    if (!is_array($values)) throw new RuntimeException('共同客服选择无效，请重新选择');
    $ids=[];
    foreach ($values as $value) {
        if (!is_scalar($value) || !preg_match('/^[1-9][0-9]*$/D',(string)$value)) throw new RuntimeException('共同客服选择无效');
        $id=(int)$value;
        if (!in_array($id,$allowedIds,true)) throw new RuntimeException('请选择当前列表中的共同客服');
        $ids[$id]=$id;
    }
    return array_values($ids);
}

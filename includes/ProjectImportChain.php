<?php
/**
 * 备案原表一个文件两张工作表（“备案” = 备案-提成表、“备案修改” = 备案-单量表）：在其中一个业务下导入后，
 * 自动接着把同一个文件里属于另一个备案业务的工作表也核对并导入（与页面“上传并导入”同一套代码），不用换业务再点一次。
 * 返回 [导入单数, 错误文字]。
 */
require_once __DIR__ . '/ProjectOnlineSheets.php';

/** 在函数作用域里再跑一遍导入流程（变量互不干扰），不输出页面。 */
function pic_run_pass(array $post)
{
    $GLOBALS['project_import_api'] = true;
    $backup = [$_POST, $_GET, $_FILES, $_SERVER['REQUEST_METHOD'] ?? null, $_SERVER['SCRIPT_NAME'] ?? null];
    $_SERVER['REQUEST_METHOD'] = 'POST'; $_SERVER['SCRIPT_NAME'] = '/project/import.php';
    $_POST = $post + ['csrf' => ps_csrf_token()]; $_GET = []; $_FILES = [];
    $error = ''; $imported = 0; $preview = [];
    try {
        ob_start();
        include __DIR__ . '/../project/import.php';
    } finally {
        ob_end_clean();
        [$_POST, $_GET, $_FILES] = [$backup[0], $backup[1], $backup[2]];
        if ($backup[3] === null) unset($_SERVER['REQUEST_METHOD']); else $_SERVER['REQUEST_METHOD'] = $backup[3];
        if ($backup[4] === null) unset($_SERVER['SCRIPT_NAME']); else $_SERVER['SCRIPT_NAME'] = $backup[4];
        unset($GLOBALS['project_import_api']);
    }
    return [$preview, $error, $imported];
}

function pic_chain_other_sheets($fileId, $otherBusiness, array $actor)
{
    // 第二遍会改写会话里的 project_import_* 状态（预览 / 业务 / 工作表报告…）：做完还原，页面继续显示第一遍的结果
    $keep = []; foreach ($_SESSION as $k => $v) if (strpos((string)$k, 'project_import_') === 0) $keep[$k] = $v;
    try {
        $before = ps_import_result_get((int)$fileId);
        pic_run_pass(['action' => 'repreview', 'business' => $otherBusiness, 'file_id' => (int)$fileId, 'all_sheets' => 1]);
        if (!array_filter((array)($_SESSION['project_import_preview'] ?? []), function ($row) { return !empty($row['base_valid']); })) return [0, '没有可导入的行'];
        [$preview, $error, $imported] = pic_run_pass(['action' => 'commit', 'business' => $otherBusiness, 'auto_import' => 1]);
        if ($before) pols_merge_report((int)$fileId, $before, $actor); // 撤销上传 / 对应订单按整个文件算
        return [(int)$imported, (string)$error];
    } finally {
        foreach (array_keys($_SESSION) as $k) if (strpos((string)$k, 'project_import_') === 0) unset($_SESSION[$k]);
        foreach ($keep as $k => $v) $_SESSION[$k] = $v;
    }
}

<?php
require_once __DIR__ . '/ProjectSettlement.php';

/** 本栏目只认已登录合作人员对应的员工 ID；财务管理员身份不自动取得权限。 */
function pg_member($actor)
{
    if (!$actor || ($actor['type'] ?? '') !== 'employee' || (int)($actor['employee_id'] ?? 0) < 1) return null;
    $q = db()->prepare('SELECT m.employee_id,m.governance_role,e.name FROM project_governance_members m JOIN employees e ON e.id=m.employee_id WHERE m.employee_id=? AND m.is_active=1 LIMIT 1');
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

function pg_private_dir()
{
    return dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'project_governance_private';
}

/** 仅接受图片/PDF，随机落盘到站点目录外；返回新建的绝对路径供异常时回收。 */
function pg_store_evidence($recordId, $actor, $file)
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) return null;
    if (($file['error'] ?? 0) !== UPLOAD_ERR_OK || (int)($file['size'] ?? 0) < 1 || (int)$file['size'] > 8 * 1024 * 1024) {
        throw new RuntimeException('举证文件须为不超过 8 MB 的图片或 PDF');
    }
    $tmp = (string)($file['tmp_name'] ?? '');
    if (!is_uploaded_file($tmp)) throw new RuntimeException('举证文件上传无效');
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($tmp);
    $extensions = ['image/png' => 'png', 'image/jpeg' => 'jpg', 'image/webp' => 'webp', 'application/pdf' => 'pdf'];
    if (!isset($extensions[$mime])) throw new RuntimeException('举证文件只支持 PNG、JPG、WebP 或 PDF');
    $dir = pg_private_dir();
    if (!is_dir($dir) && !mkdir($dir, 0700, true) && !is_dir($dir)) throw new RuntimeException('举证文件目录不可写');
    $stored = bin2hex(random_bytes(20)) . '.' . $extensions[$mime];
    $path = $dir . DIRECTORY_SEPARATOR . $stored;
    if (!move_uploaded_file($tmp, $path)) throw new RuntimeException('举证文件保存失败');
    @chmod($path, 0600);
    $name = trim(str_replace(["\r", "\n", '/', '\\'], '', (string)($file['name'] ?? '举证材料')));
    if ($name === '') $name = '举证材料.' . $extensions[$mime];
    try {
        db()->prepare('INSERT INTO project_governance_evidence (record_id,original_name,stored_name,mime_type,file_size,uploaded_by_employee_id) VALUES (?,?,?,?,?,?)')
            ->execute([$recordId, mb_substr($name, 0, 255), $stored, $mime, (int)$actor['employee_id']]);
    } catch (Throwable $e) {
        @unlink($path);
        throw $e;
    }
    return $path;
}

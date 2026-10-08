<?php

/** 举证文件目录：线上 open_basedir 只允许站点目录，存到 storage/private/governance（带 404 防护头，见 ps_private_store）。 */
function pg_private_dir()
{
    return ps_private_dir('governance');
}

/** 仅接受图片/PDF，随机命名存入私有目录；返回新建文件的绝对路径供异常时回收。 */
function pg_store_evidence($recordId, $actor, $file)
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) return null;
    if (($file['error'] ?? 0) !== UPLOAD_ERR_OK || (int)($file['size'] ?? 0) < 1 || (int)$file['size'] > 8 * 1024 * 1024) {
        throw new RuntimeException('举证文件须为不超过 8 MB 的图片或 PDF');
    }
    $tmp = (string)($file['tmp_name'] ?? '');
    if (!is_uploaded_file($tmp)) throw new RuntimeException('举证文件上传无效');
    return pg_save_evidence_file($recordId, $actor, $tmp, (string)($file['name'] ?? '举证材料'), true);
}

/** 落盘并登记一个举证文件；$uploaded=false 用于历史补录（复制本地文件）。 */
function pg_save_evidence_file($recordId, $actor, $source, $originalName, $uploaded = false)
{
    $size = (int)@filesize($source);
    if ($size < 1 || $size > 8 * 1024 * 1024) throw new RuntimeException('举证文件须为不超过 8 MB 的图片或 PDF');
    $mime = ps_detect_file_mime($source);
    $extensions = ['image/png' => 'png', 'image/jpeg' => 'jpg', 'image/webp' => 'webp', 'application/pdf' => 'pdf'];
    if (!isset($extensions[$mime])) throw new RuntimeException('举证文件只支持 PNG、JPG、WebP 或 PDF');
    $stored = bin2hex(random_bytes(20)) . '.' . $extensions[$mime];
    ps_private_store('governance', $source, $stored);
    $path = pg_private_dir() . '/' . $stored . '.php';
    if ($uploaded) @unlink($source);
    $name = trim(str_replace(["\r", "\n", '/', '\\'], '', $originalName));
    if ($name === '') $name = '举证材料.' . $extensions[$mime];
    [$employeeId, $adminId] = pg_actor_columns($actor);
    try {
        db()->prepare('INSERT INTO project_governance_evidence (record_id,original_name,stored_name,mime_type,file_size,uploaded_by_employee_id,uploaded_by_admin_id) VALUES (?,?,?,?,?,?,?)')
            ->execute([$recordId, mb_substr($name, 0, 255), $stored, $mime, $size, $employeeId, $adminId]);
    } catch (Throwable $e) {
        @unlink($path);
        throw $e;
    }
    return $path;
}

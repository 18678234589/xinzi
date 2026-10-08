<?php

function ps_private_dir($kind)
{
    $dir = dirname((dirname(__DIR__, 1))) . '/storage/private/' . preg_replace('/[^a-z_]/', '', $kind);
    if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) throw new RuntimeException('无法创建私有文件目录，请联系管理员检查 storage 目录权限');
    if (!is_file($dir . '/index.php')) @file_put_contents($dir . '/index.php', PS_PRIVATE_GUARD);
    return $dir;
}

/** 保存私有文件，返回存储名（不含 .php 后缀）。 */
function ps_private_store($kind, $sourcePath, $name)
{
    $data = @file_get_contents($sourcePath);
    if ($data === false) throw new RuntimeException('上传文件读取失败，请重新上传');
    $name = basename($name);
    if (@file_put_contents(ps_private_dir($kind) . '/' . $name . '.php', PS_PRIVATE_GUARD . $data, LOCK_EX) === false) throw new RuntimeException('文件保存失败，请联系管理员检查 storage 目录权限'
    );
    return $name;
}

/** 读取私有文件内容；不存在时返回 null。 */
function ps_private_read($kind, $name)
{
    $path = ps_private_dir($kind) . '/' . basename($name) . '.php';
    if (!is_file($path)) return null;
    $data = file_get_contents($path);
    // 兼容早期以 CRLF 源码写入的防护头（末尾多一个回车符），否则这些文件会被误判为“已不存在”
    return preg_match('/^<\?php http_response_code\(404\); exit; \?>\r?\n/', $data, $m) ? substr($data, strlen($m[0])) : null;
}

/** 删除私有文件；文件不存在时静默跳过。 */
function ps_private_delete($kind, $name)
{
    $name = (string)$name;
    if ($name === '' || strpos($name, '/') !== false) return;
    $path = ps_private_dir($kind) . '/' . basename($name) . '.php';
    if (is_file($path)) @unlink($path);
}

/** 私有文件复制到临时文件（供需要真实路径的解析器使用，如 xlsx 的 zip 读取），调用方负责删除。 */
function ps_private_temp_copy($kind, $name)
{
    $data = ps_private_read($kind, $name);
    if ($data === null) return null;
    $temp = tempnam(sys_get_temp_dir(), 'ps_');
    file_put_contents($temp, $data);
    return $temp;
}

/**
 * 按文件头识别图片 / PDF 类型；线上 PHP 7.4 未装 fileinfo 扩展，不能依赖 finfo。
 * 只识别 JPEG、PNG、WebP、PDF，其余返回 application/octet-stream。
 */
function ps_detect_mime($data)
{
    $head = substr((string)$data, 0, 16);
    if (strncmp($head, "\x89PNG\r\n\x1a\n", 8) === 0) return 'image/png';
    if (strncmp($head, "\xFF\xD8\xFF", 3) === 0) return 'image/jpeg';
    if (strncmp($head, 'RIFF', 4) === 0 && substr($head, 8, 4) === 'WEBP') return 'image/webp';
    if (strncmp($head, '%PDF-', 5) === 0) return 'application/pdf';
    return 'application/octet-stream';
}

function ps_detect_file_mime($path)
{
    $fp = @fopen($path, 'rb');
    if (!$fp) return 'application/octet-stream';
    $head = (string)fread($fp, 16);
    fclose($fp);
    return ps_detect_mime($head);
}

function ps_upload_proof($field)
{
    if (empty($_FILES[$field]['name']) || $_FILES[$field]['error'] !== UPLOAD_ERR_OK) throw new RuntimeException('请上传付款凭证');
    if ($_FILES[$field]['size'] > 5 * 1024 * 1024) throw new RuntimeException('凭证不能超过 5MB');
    $mime = ps_detect_file_mime($_FILES[$field]['tmp_name']);
    $ext = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'application/pdf' => 'pdf'][$mime] ?? null;
    if (!$ext) throw new RuntimeException('凭证仅支持 JPG、PNG 或 PDF');
    if (!is_uploaded_file($_FILES[$field]['tmp_name'])) throw new RuntimeException('凭证上传无效，请重新上传');
    return ps_private_store('proofs', $_FILES[$field]['tmp_name'], bin2hex(random_bytes(16)) . '.' . $ext);
}

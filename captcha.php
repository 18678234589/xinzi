<?php
/**
 * 图形验证码生成（登录双重验证第二因素）
 */
session_start();

// 生成4位随机验证码（数字+字母，避免易混淆字符）
$chars = '23456789ABCDEFGHJKLMNPQRSTUVWXYZ';
$code = '';
for ($i = 0; $i < 4; $i++) {
    $code .= $chars[mt_rand(0, strlen($chars) - 1)];
}
$_SESSION['captcha'] = $code;

// 输出验证码图片
$width  = 110;
$height = 40;
$im = imagecreatetruecolor($width, $height);

// 背景色
$bg = imagecolorallocate($im, 245, 247, 250);
imagefill($im, 0, 0, $bg);

// 干扰线
for ($i = 0; $i < 4; $i++) {
    $lineColor = imagecolorallocate($im, mt_rand(160, 210), mt_rand(160, 210), mt_rand(160, 210));
    imageline($im, mt_rand(0, $width), mt_rand(0, $height), mt_rand(0, $width), mt_rand(0, $height), $lineColor);
}

// 干扰点
for ($i = 0; $i < 80; $i++) {
    $pointColor = imagecolorallocate($im, mt_rand(120, 200), mt_rand(120, 200), mt_rand(120, 200));
    imagesetpixel($im, mt_rand(0, $width), mt_rand(0, $height), $pointColor);
}

// 验证码文字
for ($i = 0; $i < 4; $i++) {
    $textColor = imagecolorallocate($im, mt_rand(20, 100), mt_rand(20, 100), mt_rand(20, 100));
    imagestring($im, 5, 12 + $i * 24, mt_rand(8, 14), $code[$i], $textColor);
}

header('Content-Type: image/png');
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
imagepng($im);
imagedestroy($im);

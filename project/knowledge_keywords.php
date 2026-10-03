<?php
require_once __DIR__ . '/../includes/ProjectKnowledge.php';
$actor = ps_require_actor();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: private, max-age=60');
header('X-Content-Type-Options: nosniff');
$out = [];
foreach(pk_links() as $link) {
    try { $url = pk_url($link['url']); foreach(pk_keywords($link['keywords']) as $keyword) $out[] = ['keyword'=>$keyword,'url'=>$url,'title'=>$link['title']]; }
    catch (RuntimeException $e) { /* 手工污染的无效链接不展示。 */ }
}
echo json_encode(['links'=>$out],JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

<?php
/** Source assertions follow entry points through mechanical fragments. */
require_once __DIR__ . '/../tools/code_structure.php';
function split_test_source($path)
{
    $root = dirname(__DIR__);
    $manifest = json_decode(file_get_contents($root . '/tools/split_manifest.json'), true);
    if (!isset($manifest['sources'][$path])) return file_get_contents($root . '/' . $path);
    $visited = [];
    return split_expand($root, $path, $manifest, $visited);
}

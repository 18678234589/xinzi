<?php
require_once __DIR__ . '/code_structure.php';
$root = dirname(__DIR__);
$files = [];
$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
foreach ($iterator as $file) {
    $path = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));
    if (preg_match('~^(?:\.|vendor/|node_modules/|config/|storage/)~', $path) || substr($path, -4) !== '.php') continue;
    $files[$path] = $file->getPathname();
}
ksort($files);
$output = "# 代码地图\n\n由 `php tools/gen_code_map.php` 生成。先搜索函数名，再按行号读取目标片段。\n\n";
foreach ($files as $path => $absolute) {
    $source = file_get_contents($absolute);
    $decls = split_declarations($source);
    $output .= '## ' . $path . ' (' . (substr_count($source, "\n") + 1) . ' 行, '
        . round(strlen($source) / 1024, 1) . " KB)\n\n";
    foreach ($decls as $decl) {
        $name = ($decl['owner'] ? $decl['owner'] . '::' : '') . $decl['name'];
        $output .= '- `' . $name . '` L' . $decl['start'] . '–' . $decl['end'] . "\n";
    }
    preg_match_all('~(?:include|require)(?:_once)?\s+[^;\n]+;~', $source, $matches);
    foreach ($matches[0] as $include) {
        if (strlen($include) < 200) $output .= '- 加载：`' . $include . "`\n";
    }
    $output .= "\n";
}
if (!is_dir($root . '/docs')) mkdir($root . '/docs', 0755, true);
file_put_contents($root . '/docs/code-map.md', $output);
echo 'Mapped ' . count($files) . " PHP files -> docs/code-map.md\n";

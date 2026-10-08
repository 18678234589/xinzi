<?php
/** Verify original entry points, declarations, unchanged token streams and every fragment's syntax. */
require_once __DIR__ . '/code_structure.php';
$root = dirname(__DIR__);
if (in_array('exec', array_map('trim', explode(',', ini_get('disable_functions'))), true)) {
    fwrite(STDERR, "Run CLI verification with: php -d disable_functions= tools/verify_split.php\n");
    exit(2);
}
$manifest = json_decode(file_get_contents(__DIR__ . '/split_manifest.json'), true);
$sources = $manifest['sources'];
if (isset($argv[1]) && $argv[1] === '--entry') {
    $entry = $argv[2] ?? '';
    if (!isset($sources[$entry])) { fwrite(STDERR, "Unknown entry\n"); exit(2); }
    $sources = [$entry => $sources[$entry]];
}
$visited = [];
$failed = false;
$runtimeKey = PHP_VERSION_ID < 80000 ? 'runtime74' : 'runtime';
$tokenKey = PHP_VERSION_ID < 80000 ? 'tokens74' : 'tokens';
if (!empty($manifest[$runtimeKey])) {
    exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/split_runtime.php'), $runtimeOutput, $runtimeStatus);
    if ($runtimeStatus !== 0 || json_decode(implode("\n", $runtimeOutput), true) !== $manifest[$runtimeKey]) {
        fwrite(STDERR, "Runtime functions / class methods changed\n");
        $failed = true;
    }
}
foreach ($sources as $path => $baseline) {
    try {
        $source = split_expand($root, $path, $manifest, $visited);
        if (split_symbols($source) !== $baseline['symbols']) throw new RuntimeException('Declaration list changed');
        if (split_token_hash($source) !== $baseline[$tokenKey]) throw new RuntimeException('Token stream / HTML changed');
    } catch (Throwable $e) {
        fwrite(STDERR, $path . ': ' . $e->getMessage() . "\n");
        $failed = true;
    }
}
foreach (array_keys($visited) as $path) {
    exec(escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($root . '/' . $path) . ' 2>&1', $output, $status);
    if ($status !== 0) { fwrite(STDERR, implode("\n", $output) . "\n"); $failed = true; }
    $output = [];
}
echo count($sources) . ' entries, ' . count($visited) . ' files: '
    . ($failed ? 'FAIL' : 'declarations, unchanged tokens/HTML, syntax PASS') . "\n";
exit($failed ? 1 : 0);

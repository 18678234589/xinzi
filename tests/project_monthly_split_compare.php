<?php
/** Compare complete per-person/per-rule rows against a checkout from before the split. Read-only, isolated DB only. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
ini_set('session.save_path', sys_get_temp_dir());
if (($argv[1] ?? '') === '--snapshot') {
    require_once $argv[2] . '/includes/ProjectMonthly.php';
    require_once __DIR__ . '/require_isolated_database.php';
    require_isolated_test_database(db());
    $fixture = json_decode(file_get_contents(__DIR__ . '/fixtures/project_monthly_split.json'), true);
    $fixture['forecast'] = $argv[4] === 'forecast';
    echo json_encode(ps_monthly_results($argv[3], true, $fixture), JSON_UNESCAPED_UNICODE);
    exit;
}
$baseline = getenv('SPLIT_BASELINE_ROOT');
if (!$baseline || !is_file($baseline . '/includes/ProjectMonthly.php')) {
    fwrite(STDERR, "Set SPLIT_BASELINE_ROOT to a checkout before the split\n");
    exit(2);
}
$months = array_slice($argv, 1) ?: ['2026-08', '2026-09', '2026-10'];
$count = 0;
foreach ($months as $month) foreach (['fixture', 'forecast'] as $mode) {
    $snapshots = [];
    foreach ([$baseline, dirname(__DIR__)] as $root) {
        $command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__) . ' --snapshot '
            . escapeshellarg($root) . ' ' . escapeshellarg($month) . ' ' . escapeshellarg($mode);
        exec($command, $output, $status);
        $snapshot = json_decode(implode("\n", $output), true);
        $output = [];
        if ($status !== 0 || !is_array($snapshot)) throw new RuntimeException('Invalid monthly snapshot: ' . $month);
        $snapshots[] = $snapshot;
    }
    if ($snapshots[0] !== $snapshots[1]) throw new RuntimeException('Monthly rows changed: ' . $month . ' / ' . $mode);
    if (count($snapshots[1]) < 18) throw new RuntimeException('Incomplete monthly fixture coverage');
    $count += count($snapshots[1]);
    echo $month . ' / ' . $mode . ': ' . count($snapshots[1]) . " rows identical\n";
}
echo 'Monthly comparison PASS (' . $count . " rows)\n";

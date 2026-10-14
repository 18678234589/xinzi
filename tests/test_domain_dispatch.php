<?php
require_once __DIR__ . '/../includes/ProjectIntake.php';
require_once __DIR__ . '/../includes/BizcnDomains.php';
require_once __DIR__ . '/../includes/DnsProviders.php';
require_once __DIR__ . '/../includes/BizcnDnsRouting.php';

$domain = 'laibangwo.com';

// Mock actor
$actor = ['type' => 'admin', 'id' => 1, 'role' => 'admin'];

// 1. Check if public NS is retrieved
$publicNs = dns_public_ns($domain);
echo "Public NS: " . json_encode($publicNs) . "\n";

$nsInfo = [];
for ($i = 0; $i < count($publicNs); $i++) {
    $nsInfo['dns_host' . ($i + 1)] = $publicNs[$i];
}

$ctx = bdr_context($domain, $nsInfo);
echo "Context provider: " . $ctx['provider'] . " (" . $ctx['label'] . ")\n";
echo "Context zone ID: " . $ctx['zone']['id'] . "\n";

// 2. Fetch records
$records = bdr_records($domain, $ctx);
echo "Fetched records count: " . count($records['rows']) . "\n";
foreach (array_slice($records['rows'], 0, 5) as $r) {
    echo "  [{$r['type']}] {$r['name']} -> {$r['value']}\n";
}

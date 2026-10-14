<?php
require_once __DIR__ . '/../includes/ProjectIntake.php';
require_once __DIR__ . '/../includes/BizcnDomains.php';
require_once __DIR__ . '/../includes/DnsProviders.php';
require_once __DIR__ . '/../includes/BizcnDnsRouting.php';

$domain = 'laibangwo.com';
echo "Domain: $domain\n";

$publicNs = dns_public_ns($domain);
echo "Public NS: " . json_encode($publicNs) . "\n";

$provider = bdr_provider($publicNs);
echo "Provider: $provider\n";

$nsInfo = [];
foreach ($publicNs as $idx => $nsHost) {
    $nsInfo['dns_host' . ($idx + 1)] = $nsHost;
}

try {
    $ctx = bdr_context($domain, $nsInfo);
    echo "Context: " . json_encode($ctx, JSON_UNESCAPED_UNICODE) . "\n";

    $records = bdr_records($domain, $ctx);
    echo "Records count: " . count($records['rows']) . "\n";
    foreach (array_slice($records['rows'], 0, 5) as $r) {
        echo "  " . $r['type'] . " " . $r['name'] . " -> " . $r['value'] . "\n";
    }
} catch (Throwable $e) {
    echo "Error: " . $e->getMessage() . "\n";
}

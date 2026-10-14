<?php
/**
 * Test unified domain aggregation, searching, and DNS context dispatch.
 */
require_once __DIR__ . '/../includes/ProjectIntake.php';
require_once __DIR__ . '/../includes/BizcnDomains.php';
require_once __DIR__ . '/../includes/DnsProviders.php';
require_once __DIR__ . '/../includes/BizcnDnsRouting.php';
require_once __DIR__ . '/../includes/BizcnDomainAggregator.php';

echo "=== 1. Test bda_get_dns_domains ===\n";
$dnsDomains = bda_get_dns_domains();
echo "DNS Domains count: " . count($dnsDomains) . "\n";
assert(isset($dnsDomains['laibangwo.com']), "laibangwo.com must be present in DNS domains");
echo "  laibangwo.com source_label: " . $dnsDomains['laibangwo.com']['source_label'] . "\n";
echo "  laibangwo.com NS: " . json_encode($dnsDomains['laibangwo.com']['nameserver']) . "\n";

echo "\n=== 2. Test bda_get_renewal_domains ===\n";
$renewalDomains = bda_get_renewal_domains();
echo "Renewal domains count: " . count($renewalDomains) . "\n";
assert(count($renewalDomains) > 0, "Renewal domains must not be empty");

echo "\n=== 3. Test search 'laibangwo.com' ===\n";
$resLaibangwo = bda_unified_list('laibangwo.com', 1);
echo "Search 'laibangwo.com' count: " . $resLaibangwo['total'] . "\n";
assert($resLaibangwo['total'] >= 1, "Must find laibangwo.com");
assert($resLaibangwo['rows'][0]['domainname'] === 'laibangwo.com', "Top row must be laibangwo.com");
echo "  Top row: " . $resLaibangwo['rows'][0]['domainname'] . " (" . $resLaibangwo['rows'][0]['source_label'] . ")\n";

echo "\n=== 4. Test search renewal domain 'smartwaterhose' ===\n";
$resRenewal = bda_unified_list('smartwaterhose', 1);
echo "Search 'smartwaterhose' count: " . $resRenewal['total'] . "\n";
if ($resRenewal['total'] > 0) {
    echo "  Top row: " . $resRenewal['rows'][0]['domainname'] . " (" . $resRenewal['rows'][0]['source_label'] . ")\n";
}

echo "\n=== 5. Test filter 'all' page 1 ===\n";
$resAll = bda_unified_list('', 1, 'all');
echo "All page 1 rows: " . count($resAll['rows']) . " / total: " . $resAll['total'] . "\n";
echo "  Top row: " . $resAll['rows'][0]['domainname'] . " (" . $resAll['rows'][0]['source_label'] . ")\n";
assert($resAll['rows'][0]['domainname'] === 'laibangwo.com', "Top row must be pinned DNS domain laibangwo.com");

echo "\n=== 6. Test bdr_context for 'laibangwo.com' without bizcn error ===\n";
$ctx = bdr_context('laibangwo.com');
echo "Provider: " . $ctx['provider'] . " (" . $ctx['label'] . ")\n";
assert($ctx['provider'] === 'cloudflare', "Provider must be cloudflare");

echo "\n=== 7. Test bdr_records for 'laibangwo.com' ===\n";
$records = bdr_records('laibangwo.com', $ctx);
echo "Records fetched: " . count($records['rows']) . "\n";
assert(count($records['rows']) > 0, "Records must not be empty");

echo "\nALL TESTS PASSED!\n";

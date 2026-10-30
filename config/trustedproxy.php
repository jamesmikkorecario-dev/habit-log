<?php

$proxies = array_values(array_filter(array_map('trim', explode(',', env('TRUSTED_PROXIES', '')))));
foreach ($proxies as $proxy) {
    $parts = explode('/', $proxy);
    $ip = $parts[0];
    $bits = str_contains($ip, ':') ? 128 : 32;
    if (! filter_var($ip, FILTER_VALIDATE_IP) || count($parts) > 2
        || (isset($parts[1]) && (! ctype_digit($parts[1]) || (int) $parts[1] < 1 || (int) $parts[1] > $bits))) {
        throw new RuntimeException('TRUSTED_PROXIES must contain explicit IP addresses or bounded CIDRs.');
    }
}

return [
    // Explicit addresses/CIDRs of the deployment's TLS ingress only. An empty
    // list also disables framework platform-name-based implicit proxy trust.
    'proxies' => $proxies,
];

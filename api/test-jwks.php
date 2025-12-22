<?php
/**
 * JWKS Endpoint Test - Standalone version
 */

header('Content-Type: application/json');

// Load public key
$publicKeyPath = __DIR__ . '/../keys/public.pem';

if (!file_exists($publicKeyPath)) {
    echo json_encode(['error' => 'Public key not found at: ' . $publicKeyPath]);
    exit();
}

$publicKeyPem = file_get_contents($publicKeyPath);
$publicKey = openssl_pkey_get_public($publicKeyPem);

if ($publicKey === false) {
    echo json_encode(['error' => 'Failed to load public key']);
    exit();
}

$keyDetails = openssl_pkey_get_details($publicKey);

if (!isset($keyDetails['rsa'])) {
    echo json_encode(['error' => 'Not an RSA key']);
    exit();
}

$modulus = $keyDetails['rsa']['n'];
$exponent = $keyDetails['rsa']['e'];

$n = rtrim(strtr(base64_encode($modulus), '+/', '-_'), '=');
$e = rtrim(strtr(base64_encode($exponent), '+/', '-_'), '=');

$jwks = [
    'keys' => [
        [
            'kty' => 'RSA',
            'use' => 'sig',
            'alg' => 'RS256',
            'kid' => 'teamselevated-key-1',
            'n' => $n,
            'e' => $e
        ]
    ]
];

echo json_encode($jwks, JSON_PRETTY_PRINT);

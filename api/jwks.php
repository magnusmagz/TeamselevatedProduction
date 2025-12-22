<?php
/**
 * JWKS (JSON Web Key Set) Endpoint
 *
 * This endpoint exposes the public key in JWKS format for Neon RLS to verify JWTs.
 * Add this URL to your Neon Console under RLS settings.
 *
 * Example URL: https://your-backend.com/api/jwks.php
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
header('Cache-Control: public, max-age=3600'); // Cache for 1 hour

// Handle preflight OPTIONS request
if (isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

try {
    // Load public key
    $publicKeyPath = __DIR__ . '/../keys/public.pem';

    if (!file_exists($publicKeyPath)) {
        http_response_code(500);
        echo json_encode([
            'error' => 'Public key not found',
            'message' => 'Run setup/generate-keys.php to generate keys'
        ]);
        exit();
    }

    $publicKeyPem = file_get_contents($publicKeyPath);
    $publicKey = openssl_pkey_get_public($publicKeyPem);

    if ($publicKey === false) {
        throw new Exception('Failed to load public key');
    }

    // Get key details
    $keyDetails = openssl_pkey_get_details($publicKey);

    if (!isset($keyDetails['rsa'])) {
        throw new Exception('Not an RSA key');
    }

    // Extract RSA components
    $modulus = $keyDetails['rsa']['n'];
    $exponent = $keyDetails['rsa']['e'];

    // Convert to base64url encoding (JWT standard)
    $n = rtrim(strtr(base64_encode($modulus), '+/', '-_'), '=');
    $e = rtrim(strtr(base64_encode($exponent), '+/', '-_'), '=');

    // Build JWKS response
    $jwks = [
        'keys' => [
            [
                'kty' => 'RSA',              // Key type
                'use' => 'sig',              // Usage: signature
                'alg' => 'RS256',            // Algorithm
                'kid' => 'teamselevated-key-1', // Key ID (must match JWT header)
                'n' => $n,                   // Modulus
                'e' => $e                    // Exponent
            ]
        ]
    ];

    // Return JWKS
    http_response_code(200);
    echo json_encode($jwks, JSON_PRETTY_PRINT);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'error' => 'JWKS generation failed',
        'message' => $e->getMessage()
    ]);
}

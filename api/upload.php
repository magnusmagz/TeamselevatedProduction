<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../lib/Cors.php';
Cors::handle();

// Handle preflight OPTIONS request

require_once __DIR__ . '/../lib/JWT.php';

// Only allow POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

// Verify authentication
$headers = getallheaders();
$authHeader = $headers['Authorization'] ?? '';

if (empty($authHeader) || !preg_match('/Bearer\s+(.*)$/i', $authHeader, $matches)) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'No authorization token provided']);
    exit;
}

$token = $matches[1];

try {
    // Use verify() (signature + expiry check). decode() is debug-only and skips
    // verification. JWT::verify() returns a stdClass payload, so read user_id
    // with object syntax — the prior `$payload['user_id']` array access failed
    // against the stdClass object, breaking image uploads with a 401/null user.
    $payload = JWT::verify($token);
    if ($payload === false) {
        http_response_code(401);
        echo json_encode(['success' => false, 'error' => 'Invalid or expired token']);
        exit;
    }
    $userId = is_object($payload)
        ? ($payload->user_id ?? null)
        : ($payload['user_id'] ?? null);
    if (!$userId) {
        http_response_code(401);
        echo json_encode(['success' => false, 'error' => 'Invalid token: missing user_id']);
        exit;
    }
} catch (Exception $e) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Invalid token: ' . $e->getMessage()]);
    exit;
}

// Check if file was uploaded
if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'No file uploaded or upload error']);
    exit;
}

$file = $_FILES['file'];
$type = $_POST['type'] ?? 'general';

// Validate file type
$imageTypes = ['image/jpeg', 'image/jpg', 'image/png', 'image/gif', 'image/webp'];
$documentTypes = ['application/pdf', 'application/msword',
    'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
    'application/vnd.ms-excel',
    'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
    'text/plain', 'text/csv'];

$finfo = finfo_open(FILEINFO_MIME_TYPE);
$mimeType = finfo_file($finfo, $file['tmp_name']);
finfo_close($finfo);

// Allow document types for upload categories that may include PDFs / office docs
$documentTypeWhitelist = ['club-documents', 'tournament-insurance', 'tournament-rules'];
$allowsDocuments = in_array($type, $documentTypeWhitelist, true);
if ($allowsDocuments) {
    $allowedTypes = array_merge($imageTypes, $documentTypes);
} else {
    $allowedTypes = $imageTypes;
}

if (!in_array($mimeType, $allowedTypes)) {
    $label = $allowsDocuments ? 'images, PDFs, and office documents' : 'images';
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => "Invalid file type. Only $label are allowed."]);
    exit;
}

// Validate file size (max 10MB for documents, 5MB for images)
$maxSize = $allowsDocuments ? 10 * 1024 * 1024 : 5 * 1024 * 1024;
if ($file['size'] > $maxSize) {
    http_response_code(400);
    $limitLabel = $allowsDocuments ? '10MB' : '5MB';
    echo json_encode(['success' => false, 'error' => "File size exceeds $limitLabel limit"]);
    exit;
}

// Create uploads directory if it doesn't exist
$uploadDir = __DIR__ . '/../uploads/';
if (!is_dir($uploadDir)) {
    mkdir($uploadDir, 0755, true);
}

// Create subdirectory based on type
$typeDir = $uploadDir . $type . '/';
if (!is_dir($typeDir)) {
    mkdir($typeDir, 0755, true);
}

// Generate unique filename
$extension = pathinfo($file['name'], PATHINFO_EXTENSION);
$filename = uniqid('upload_' . time() . '_') . '.' . $extension;
$filepath = $typeDir . $filename;

// Move uploaded file
if (!move_uploaded_file($file['tmp_name'], $filepath)) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Failed to save file']);
    exit;
}

// Generate URL for the uploaded file
$baseUrl = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'];
$fileUrl = $baseUrl . '/uploads/' . $type . '/' . $filename;

// Return success response
http_response_code(200);
echo json_encode([
    'success' => true,
    'url' => $fileUrl,
    'filename' => $filename,
    'type' => $type,
    'size' => $file['size']
]);

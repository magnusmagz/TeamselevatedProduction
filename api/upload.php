<?php
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Content-Type: application/json');

// Handle preflight OPTIONS request
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

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
    $jwt = new JWT();
    $payload = $jwt->decode($token);
    $userId = $payload['user_id'];
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

// Allow document types for club-documents uploads
if ($type === 'club-documents') {
    $allowedTypes = array_merge($imageTypes, $documentTypes);
} else {
    $allowedTypes = $imageTypes;
}

if (!in_array($mimeType, $allowedTypes)) {
    $label = $type === 'club-documents' ? 'images, PDFs, and office documents' : 'images';
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => "Invalid file type. Only $label are allowed."]);
    exit;
}

// Validate file size (max 10MB for documents, 5MB for images)
$maxSize = $type === 'club-documents' ? 10 * 1024 * 1024 : 5 * 1024 * 1024;
if ($file['size'] > $maxSize) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'File size exceeds 5MB limit']);
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
echo json_encode([
    'success' => true,
    'url' => $fileUrl,
    'filename' => $filename,
    'type' => $type,
    'size' => $file['size']
]);

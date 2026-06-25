<?php
// ===== CONFIGURATION =====
$allowed_hosts    = ['gravityhomes.in', 'www.gravityhomes.in'];
$recaptchaSecret  = '6LcbxbIiAAAAAEA2CsEj8ELhu9U8owpPnRk_urC8';
$leadRatApiUrl    = 'https://connect.leadrat.com/api/v1/integration/Website';
$leadratApiKey    = 'ZWVlMWQ4YWItOTdmZC00NjdhLTk4ODAtMzFiOGZhYmFiOGVi';

// ===== CORS CHECK =====
$origin  = parse_url($_SERVER['HTTP_ORIGIN'] ?? '', PHP_URL_HOST);
$referer = parse_url($_SERVER['HTTP_REFERER'] ?? '', PHP_URL_HOST);

if (!in_array($origin, $allowed_hosts) && !in_array($referer, $allowed_hosts)) {
    http_response_code(403);
    echo json_encode(['status' => 'error', 'msg' => 'Forbidden: Invalid host']);
    exit;
}

// ===== CORS HEADERS =====
header("Access-Control-Allow-Origin: {$_SERVER['HTTP_ORIGIN']}");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");
header('Content-Type: application/json');

// Preflight requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// ===== DECODE JSON INPUT =====
$data = json_decode(file_get_contents("php://input"), true);
file_put_contents("php_debug.log", "=== Incoming Request ===\n" . print_r($data, true) . "\n", FILE_APPEND);

// ===== reCAPTCHA VALIDATION =====
$recaptchaToken = $data['token'] ?? '';

$ch = curl_init('https://www.google.com/recaptcha/api/siteverify');
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query([
    'secret'   => $recaptchaSecret,
    'response' => $recaptchaToken
]));
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
$verifyResponse = curl_exec($ch);
$recaptchaError = curl_error($ch);
curl_close($ch);

file_put_contents("php_debug.log", "=== reCAPTCHA Response ===\n" . $verifyResponse . " | Error: $recaptchaError\n", FILE_APPEND);

$responseData = json_decode($verifyResponse, true);
if (!$responseData['success'] || ($responseData['score'] ?? 0) < 0.5) {
    echo json_encode(['status' => 'error', 'msg' => 'reCAPTCHA validation failed', 'recaptcha' => $responseData]);
    exit;
}

// ===== COLLECT FORM DATA =====
$name    = $data['name']    ?? '';
$email   = $data['email']   ?? '';
$mobile  = $data['mobile']  ?? '';
$message = $data['message'] ?? '';

if (!$name || !$mobile || !$message) {
    echo json_encode(['status' => 'error', 'msg' => 'Missing required fields']);
    exit;
}

// ===== BUILD LEADRAT PAYLOAD =====
$pathname = $data['pathname'] ?? '';

$lead = [[
  "name"   => $name,
  "mobile" => $mobile,
  "email"  => $email,
  "notes"  => $message . ($pathname ? " | Source page: $pathname" : ""),
  "source" => "Website",
  "subSource" => $pathname
]];

// ===== SUBMIT TO LEADRAT =====
$ch = curl_init($leadRatApiUrl);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => json_encode($lead),
    CURLOPT_HTTPHEADER     => [
        'API-Key: ' . $leadratApiKey,
        'Content-Type: application/json'
    ],
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_MAXREDIRS      => 10,
    CURLOPT_TIMEOUT        => 0,
    CURLOPT_HTTP_VERSION   => CURL_HTTP_VERSION_1_1,
    CURLOPT_CUSTOMREQUEST  => 'POST'
]);

$crmResponse = curl_exec($ch);
$httpCode    = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlError   = curl_error($ch);
curl_close($ch);

// ===== DEBUG LOG =====
file_put_contents("php_debug.log", "=== LeadRat Payload ===\n" . json_encode($lead, JSON_PRETTY_PRINT) . "\n", FILE_APPEND);
file_put_contents("php_debug.log", "=== LeadRat Response ===\n" . print_r($crmResponse, true) . " | HTTP Code: $httpCode | Curl Error: $curlError\n", FILE_APPEND);

// ===== RETURN RESULT =====
if ($httpCode === 200) {
    echo json_encode([
        'status'       => 'success',
        'msg'          => 'Lead submitted successfully',
        'crm_response' => $crmResponse
    ]);
} else {
    echo json_encode([
        'status'       => 'error',
        'msg'          => 'Failed to submit lead',
        'httpCode'     => $httpCode,
        'crm_response' => $crmResponse,
        'curl_error'   => $curlError
    ]);
}
?>

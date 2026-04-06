<?php
/**
 * CloudPe CMP Console Share API
 *
 * Public JSON API endpoint for console share token operations.
 * NO WHMCS authentication required - token-based access only.
 *
 * Actions:
 *   ?action=status&token=xxx  - Check token validity (non-consuming)
 *   ?action=access&token=xxx  - Get console URL (records usage)
 *
 * @version 1.0.0
 */

ob_start();

$whmcsRoot = dirname(dirname(dirname(__DIR__)));
require_once $whmcsRoot . '/init.php';

use WHMCS\Database\Capsule;

require_once __DIR__ . '/lib/CloudPeCmpAPI.php';
require_once __DIR__ . '/lib/CloudPeCmpHelper.php';

ob_end_clean();

header('Content-Type: application/json');
header('X-Content-Type-Options: nosniff');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET');

function jsonResponse(bool $success, string $message, array $data = []): void
{
    echo json_encode(array_merge(['success' => $success, 'message' => $message], $data));
    exit;
}

function jsonError(int $httpCode, string $errorCode, string $message, ?string $vmStatus = null): void
{
    http_response_code($httpCode);
    $response = [
        'success' => false,
        'error' => $message,
        'error_code' => $errorCode,
        'message' => $message,
    ];
    if ($vmStatus !== null) {
        $response['vm_status'] = $vmStatus;
    }
    echo json_encode($response);
    exit;
}

function checkRateLimit(string $ip, int $limit = 60, int $window = 60): bool
{
    $cacheDir = sys_get_temp_dir();
    $cacheFile = $cacheDir . '/cloudpe_cmp_ratelimit_' . md5($ip);
    $now = time();

    $data = ['count' => 0, 'reset' => $now + $window];
    if (file_exists($cacheFile)) {
        $data = json_decode(file_get_contents($cacheFile), true) ?: $data;
        if ($data['reset'] < $now) {
            $data = ['count' => 0, 'reset' => $now + $window];
        }
    }

    if ($data['count'] >= $limit) {
        return false;
    }

    $data['count']++;
    file_put_contents($cacheFile, json_encode($data));
    return true;
}

$action = $_REQUEST['action'] ?? 'access';
$token = trim($_REQUEST['token'] ?? '');
$clientIp = $_SERVER['REMOTE_ADDR'] ?? 'unknown';

if (!checkRateLimit($clientIp)) {
    jsonError(429, 'RATE_LIMITED', 'Too many requests. Please wait before trying again.');
}

if (empty($token) || strlen($token) !== 64 || !ctype_xdigit($token)) {
    jsonError(404, 'TOKEN_NOT_FOUND', 'This console share link does not exist or has been deleted.');
}

$share = CloudPeCmpHelper::findShareByToken($token);

if (!$share) {
    jsonError(404, 'TOKEN_NOT_FOUND', 'This console share link does not exist or has been deleted.');
}

if ($share->revoked) {
    jsonError(403, 'TOKEN_REVOKED', 'This console share link has been revoked by the owner.');
}

if (strtotime($share->expires_at) < time()) {
    jsonError(403, 'TOKEN_EXPIRED', 'This console share link has expired. Please request a new link.');
}

$service = Capsule::table('tblhosting')->where('id', $share->service_id)->first();
if (!$service || $service->domainstatus !== 'Active') {
    jsonError(404, 'SERVICE_NOT_ACTIVE', 'The associated service is not active.');
}

$vmName = $service->domain ?: 'VM-' . $share->service_id;

if ($action === 'status') {
    echo json_encode([
        'valid' => true,
        'vm_name' => $vmName,
        'expires_at' => $share->expires_at,
        'console_type' => $share->console_type,
    ]);
    exit;
}

$server = Capsule::table('tblservers')->where('id', $service->server)->first();
if (!$server) {
    jsonError(500, 'SERVER_ERROR', 'Server configuration not found.');
}

try {
    $api = new CloudPeCmpAPI([
        'serverhostname' => $server->hostname,
        'serverpassword' => decrypt($server->password),
        'serveraccesshash' => $server->accesshash,
        'serversecure' => $server->secure,
    ]);
} catch (Exception $e) {
    jsonError(500, 'API_ERROR', 'Failed to connect to cloud infrastructure.');
}

$vmResult = $api->getInstance($share->vm_id);
if (!$vmResult['success']) {
    jsonError(404, 'VM_NOT_FOUND', 'The virtual machine no longer exists.');
}

$vmStatus = strtoupper($vmResult['instance']['status'] ?? 'UNKNOWN');
$vmName = $vmResult['instance']['name'] ?? $vmName;

if ($vmStatus !== 'ACTIVE') {
    $statusMessages = [
        'SHUTOFF' => 'stopped',
        'STOPPED' => 'stopped',
        'SUSPENDED' => 'suspended',
        'SHELVED' => 'shelved',
        'SHELVED_OFFLOADED' => 'shelved',
        'ERROR' => 'in an error state',
        'BUILD' => 'still being created',
        'BUILDING' => 'still being created',
        'PAUSED' => 'paused',
    ];
    $statusDesc = $statusMessages[$vmStatus] ?? strtolower($vmStatus);
    jsonError(503, 'VM_NOT_ACTIVE', "The virtual machine is currently {$statusDesc}. Console access requires the VM to be running.", $vmStatus);
}

$consoleResult = $api->getConsoleUrl($share->vm_id);
if (!$consoleResult['success'] || empty($consoleResult['url'])) {
    jsonError(500, 'CONSOLE_ERROR', 'Unable to connect to the VM console. Please try again later.');
}

CloudPeCmpHelper::recordShareUsage($share->id, $clientIp);

logModuleCall('cloudpe_cmp', 'CONSOLE_SHARE_ACCESS', [
    'share_id' => $share->id,
    'vm_id' => $share->vm_id,
    'ip' => $clientIp,
], 'Console URL provided', 'Success');

echo json_encode([
    'success' => true,
    'console_url' => $consoleResult['url'],
    'console_type' => $share->console_type,
    'vm_name' => $vmName,
    'expires_at' => $share->expires_at,
]);

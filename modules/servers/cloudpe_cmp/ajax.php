<?php
/**
 * CloudPe CMP AJAX Endpoint
 *
 * Standalone endpoint for client area VM actions.
 * Bypasses WHMCS modop=custom routing which interferes with JSON responses.
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

function jsonResponse(bool $success, string $message, array $data = []): void
{
    echo json_encode(array_merge([
        'success' => $success,
        'message' => $message,
    ], $data));
    exit;
}

function logAction(string $action, $request, $response, string $status = ''): void
{
    logModuleCall('cloudpe_cmp', 'AJAX_' . $action, $request, $response, $status);
}

$action = $_REQUEST['action'] ?? '';
$serviceId = (int)($_REQUEST['service_id'] ?? 0);

$validActions = ['start', 'stop', 'restart', 'console', 'password', 'console_output', 'console_share_create', 'console_share_list', 'console_share_revoke'];
if (!in_array($action, $validActions)) {
    jsonResponse(false, 'Invalid action');
}

if ($serviceId <= 0) {
    jsonResponse(false, 'Invalid service ID');
}

$clientId = (int)($_SESSION['uid'] ?? 0);
if ($clientId <= 0) {
    jsonResponse(false, 'Please log in to continue');
}

$service = Capsule::table('tblhosting')
    ->join('tblproducts', 'tblhosting.packageid', '=', 'tblproducts.id')
    ->where('tblhosting.id', $serviceId)
    ->where('tblhosting.userid', $clientId)
    ->where('tblproducts.servertype', 'cloudpe_cmp')
    ->select(
        'tblhosting.id',
        'tblhosting.userid',
        'tblhosting.server',
        'tblhosting.packageid',
        'tblhosting.domainstatus'
    )
    ->first();

if (!$service) {
    jsonResponse(false, 'Service not found or access denied');
}

if ($service->domainstatus !== 'Active') {
    jsonResponse(false, 'Service is not active');
}

$server = Capsule::table('tblservers')->where('id', $service->server)->first();
if (!$server) {
    jsonResponse(false, 'Server configuration not found');
}

$vmId = getCustomFieldValue($serviceId, $service->packageid, 'VM ID');
if (empty($vmId)) {
    jsonResponse(false, 'VM not provisioned yet');
}

$params = [
    'serverhostname' => $server->hostname,
    'serverpassword' => decrypt($server->password),
    'serveraccesshash' => $server->accesshash,
    'serversecure' => $server->secure,
];

try {
    $api = new CloudPeCmpAPI($params);
    $helper = new CloudPeCmpHelper();

    logAction($action, ['service_id' => $serviceId, 'vm_id' => $vmId, 'client_id' => $clientId], 'Starting action');

    switch ($action) {
        case 'start':
            $result = $api->startInstance($vmId);
            if (!$result['success']) {
                jsonResponse(false, 'Failed to start VM: ' . ($result['error'] ?? 'Unknown error'));
            }

            $waitResult = $api->waitForInstanceStatus($vmId, 'ACTIVE', 30);
            $newStatus = 'UNKNOWN';
            if ($waitResult['success'] && !empty($waitResult['instance'])) {
                $newStatus = $waitResult['instance']['status'] ?? 'ACTIVE';
                syncServiceIPs($api, $vmId, $serviceId, $service->packageid, $helper);
            }

            jsonResponse(true, 'VM started successfully', ['status' => $newStatus]);
            break;

        case 'stop':
            $result = $api->stopInstance($vmId);
            if (!$result['success']) {
                jsonResponse(false, 'Failed to stop VM: ' . ($result['error'] ?? 'Unknown error'));
            }

            $waitResult = $api->waitForInstanceStatus($vmId, 'SHUTOFF', 30);
            $newStatus = $waitResult['success'] ? 'SHUTOFF' : 'UNKNOWN';

            jsonResponse(true, 'VM stopped successfully', ['status' => $newStatus]);
            break;

        case 'restart':
            $result = $api->rebootInstance($vmId);
            if (!$result['success']) {
                jsonResponse(false, 'Failed to restart VM: ' . ($result['error'] ?? 'Unknown error'));
            }

            $waitResult = $api->waitForInstanceStatus($vmId, 'ACTIVE', 30);
            $newStatus = 'UNKNOWN';
            if ($waitResult['success'] && !empty($waitResult['instance'])) {
                $newStatus = $waitResult['instance']['status'] ?? 'ACTIVE';
                syncServiceIPs($api, $vmId, $serviceId, $service->packageid, $helper);
            }

            jsonResponse(true, 'VM restarted successfully', ['status' => $newStatus]);
            break;

        case 'console':
            $result = $api->getConsoleUrl($vmId);
            if (!$result['success'] || empty($result['url'])) {
                jsonResponse(false, 'Failed to get console URL: ' . ($result['error'] ?? 'No URL returned'));
            }

            jsonResponse(true, 'Console ready', ['url' => $result['url']]);
            break;

        case 'password':
            // Accept user-supplied password from the Reset Password modal;
            // fall back to a generated strong one for legacy callers.
            $newPassword = trim((string)($_POST['new_password'] ?? ''));
            if ($newPassword === '') {
                $newPassword = $helper->generatePassword();
            } else {
                $errs = [];
                if (strlen($newPassword) < 12)                     $errs[] = 'at least 12 characters';
                if (!preg_match('/[A-Z]/', $newPassword))          $errs[] = 'an uppercase letter';
                if (!preg_match('/[a-z]/', $newPassword))          $errs[] = 'a lowercase letter';
                if (!preg_match('/[0-9]/', $newPassword))          $errs[] = 'a number';
                if (!preg_match('/[^A-Za-z0-9]/', $newPassword))   $errs[] = 'a special character';
                if ($errs) jsonResponse(false, 'Password must contain ' . implode(', ', $errs) . '.');
            }
            $result = $api->changePassword($vmId, $newPassword);

            if (!$result['success']) {
                jsonResponse(false, 'Failed to reset password: ' . ($result['error'] ?? 'Unknown error'));
            }

            Capsule::table('tblhosting')->where('id', $serviceId)->update([
                'password' => encrypt($newPassword),
            ]);

            jsonResponse(true, 'Password reset successfully. Reload page to view new password.');
            break;

        case 'console_output':
            $length = (int)($_REQUEST['length'] ?? 100);
            $result = $api->getConsoleOutput($vmId, $length);

            if (!$result['success']) {
                jsonResponse(false, 'Failed to get console output: ' . ($result['error'] ?? 'Unknown error'));
            }

            jsonResponse(true, 'Console output retrieved', [
                'output' => $result['output'],
                'length' => $result['length']
            ]);
            break;

        case 'console_share_create':
            CloudPeCmpHelper::ensureConsoleSharesTable();

            $name = trim($_REQUEST['name'] ?? '');
            $expiry = $_REQUEST['expiry'] ?? '24h';
            $consoleType = $_REQUEST['console_type'] ?? 'novnc';

            $expiryDurations = CloudPeCmpHelper::getExpiryDurations();
            if (!isset($expiryDurations[$expiry])) {
                jsonResponse(false, 'Invalid expiry duration');
            }

            $vmResult = $api->getInstance($vmId);
            if (!$vmResult['success'] || strtoupper($vmResult['instance']['status'] ?? '') !== 'ACTIVE') {
                jsonResponse(false, 'VM must be running (ACTIVE) to create a console share');
            }

            $tokenData = CloudPeCmpHelper::generateShareToken();
            $expiresAt = date('Y-m-d H:i:s', time() + $expiryDurations[$expiry]);

            $shareId = Capsule::table('mod_cloudpe_cmp_console_shares')->insertGetId([
                'token_hash' => $tokenData['hash'],
                'service_id' => $serviceId,
                'vm_id' => $vmId,
                'created_by_user_id' => $clientId,
                'name' => $name ?: null,
                'expires_at' => $expiresAt,
                'console_type' => $consoleType,
                'created_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s'),
            ]);

            $systemUrl = rtrim($GLOBALS['CONFIG']['SystemURL'] ?? '', '/');
            $shareUrl = $systemUrl . '/modules/servers/cloudpe_cmp/console_share.php?token=' . $tokenData['token'];

            jsonResponse(true, 'Console share created', [
                'id' => $shareId,
                'name' => $name,
                'token' => $tokenData['token'],
                'share_url' => $shareUrl,
                'expires_at' => $expiresAt,
                'console_type' => $consoleType,
            ]);
            break;

        case 'console_share_list':
            CloudPeCmpHelper::ensureConsoleSharesTable();

            $includeRevoked = filter_var($_REQUEST['include_revoked'] ?? false, FILTER_VALIDATE_BOOLEAN);

            $query = Capsule::table('mod_cloudpe_cmp_console_shares')
                ->where('service_id', $serviceId)
                ->where('vm_id', $vmId);

            if (!$includeRevoked) {
                $query->where('revoked', false);
            }

            $shares = $query->orderBy('created_at', 'desc')->get();

            $shareList = [];
            $now = time();
            foreach ($shares as $share) {
                $expiresAt = strtotime($share->expires_at);
                $shareList[] = [
                    'id' => $share->id,
                    'name' => $share->name,
                    'expires_at' => $share->expires_at,
                    'console_type' => $share->console_type,
                    'use_count' => $share->use_count,
                    'last_used_at' => $share->last_used_at,
                    'created_at' => $share->created_at,
                    'revoked' => (bool)$share->revoked,
                    'is_expired' => $expiresAt < $now,
                ];
            }

            jsonResponse(true, 'Console shares retrieved', ['shares' => $shareList]);
            break;

        case 'console_share_revoke':
            CloudPeCmpHelper::ensureConsoleSharesTable();

            $shareId = (int)($_REQUEST['share_id'] ?? 0);
            $reason = trim($_REQUEST['reason'] ?? 'Revoked by user');

            if ($shareId <= 0) {
                jsonResponse(false, 'Invalid share ID');
            }

            $share = Capsule::table('mod_cloudpe_cmp_console_shares')
                ->where('id', $shareId)
                ->where('service_id', $serviceId)
                ->first();

            if (!$share) {
                jsonResponse(false, 'Share not found');
            }

            if ($share->revoked) {
                jsonResponse(false, 'Share already revoked');
            }

            Capsule::table('mod_cloudpe_cmp_console_shares')
                ->where('id', $shareId)
                ->update([
                    'revoked' => true,
                    'revoked_at' => date('Y-m-d H:i:s'),
                    'revoked_reason' => substr($reason, 0, 255),
                    'updated_at' => date('Y-m-d H:i:s'),
                ]);

            jsonResponse(true, 'Console share revoked');
            break;
    }
} catch (Exception $e) {
    logAction($action, ['vm_id' => $vmId], $e->getMessage(), 'Exception');
    jsonResponse(false, 'Error: ' . $e->getMessage());
}

function getCustomFieldValue(int $serviceId, int $productId, string $fieldName): string
{
    $field = Capsule::table('tblcustomfields')
        ->where('relid', $productId)
        ->where('type', 'product')
        ->where('fieldname', $fieldName)
        ->first();

    if (!$field) return '';

    $value = Capsule::table('tblcustomfieldsvalues')
        ->where('fieldid', $field->id)
        ->where('relid', $serviceId)
        ->first();

    return $value->value ?? '';
}

function updateCustomFieldValue(int $serviceId, int $productId, string $fieldName, string $value): void
{
    $field = Capsule::table('tblcustomfields')
        ->where('relid', $productId)
        ->where('type', 'product')
        ->where('fieldname', $fieldName)
        ->first();

    if (!$field) return;

    Capsule::table('tblcustomfieldsvalues')
        ->updateOrInsert(
            ['fieldid' => $field->id, 'relid' => $serviceId],
            ['value' => $value]
        );
}

function syncServiceIPs(CloudPeCmpAPI $api, string $vmId, int $serviceId, int $productId, CloudPeCmpHelper $helper): void
{
    $result = $api->getInstance($vmId);
    if (!$result['success']) return;

    $ipData = $result['instance']['ip_addresses'] ?? $result['instance']['addresses'] ?? [];
    $ips = $helper->extractIPs($ipData);

    updateCustomFieldValue($serviceId, $productId, 'Public IPv4', $ips['ipv4']);
    updateCustomFieldValue($serviceId, $productId, 'Public IPv6', $ips['ipv6']);

    $dedicatedIp = $ips['ipv4'] ?: $ips['ipv6'];
    Capsule::table('tblhosting')->where('id', $serviceId)->update([
        'dedicatedip' => $dedicatedIp,
        'assignedips' => trim($ips['ipv4'] . "\n" . $ips['ipv6']),
    ]);
}

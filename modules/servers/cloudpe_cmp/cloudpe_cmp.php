<?php
/**
 * CloudPe CMP WHMCS Provisioning Module
 *
 * Provisions virtual machines on CloudPe CMP (FastAPI backend)
 * using API Key authentication.
 *
 * @version 1.0.3
 */

if (!defined("WHMCS")) {
    die("This file cannot be accessed directly");
}

require_once __DIR__ . '/lib/CloudPeCmpAPI.php';
require_once __DIR__ . '/lib/CloudPeCmpHelper.php';

use WHMCS\Database\Capsule;

function cloudpe_cmp_MetaData(): array
{
    return [
        'DisplayName' => 'CloudPe CMP',
        'APIVersion' => '1.1',
        'RequiresServer' => true,
    ];
}

function cloudpe_cmp_ConfigOptions(): array
{
    // Product Module Settings -> Configuration
    // We render these as WHMCS dropdowns so the admin can pick a default
    // value for each order item directly. The Loader callbacks query the
    // CMP API using the server credentials bound to the product.
    return [
        'flavor' => [
            'FriendlyName' => 'Default Flavor',
            'Type' => 'dropdown',
            'Loader' => 'cloudpe_cmp_FlavorLoader',
            'SimpleMode' => true,
            'Description' => 'Default VM size (can be overridden by a "Server Size" Configurable Option).',
        ],
        'image' => [
            'FriendlyName' => 'Default Image',
            'Type' => 'dropdown',
            'Loader' => 'cloudpe_cmp_ImageLoader',
            'SimpleMode' => true,
            'Description' => 'Default OS image (can be overridden by an "Operating System" Configurable Option).',
        ],
        'region' => [
            'FriendlyName' => 'Region',
            'Type' => 'dropdown',
            'Loader' => 'cloudpe_cmp_RegionLoader',
            'SimpleMode' => true,
            'Description' => 'Region for VM deployment.',
        ],
        'billing_period' => [
            'FriendlyName' => 'Billing Period',
            'Type' => 'dropdown',
            'Options' => 'monthly|Monthly,hourly|Hourly',
            'Default' => 'monthly',
            'SimpleMode' => true,
            'Description' => 'Default billing period.',
        ],
        'security_group' => [
            'FriendlyName' => 'Default Security Group',
            'Type' => 'dropdown',
            'Loader' => 'cloudpe_cmp_SecurityGroupLoader',
            'SimpleMode' => true,
            'Description' => 'Default firewall rules applied to the VM.',
        ],
        'min_volume_size' => [
            'FriendlyName' => 'Minimum Volume Size (GB)',
            'Type' => 'text',
            'Size' => 10,
            'Default' => '30',
            'SimpleMode' => true,
            'Description' => 'Minimum disk size in GB. Orders below this will be bumped up.',
        ],
        'volume_type' => [
            'FriendlyName' => 'Storage Policy',
            'Type' => 'dropdown',
            'Loader' => 'cloudpe_cmp_VolumeTypeLoader',
            'SimpleMode' => true,
            'Description' => 'Volume type (e.g. General Purpose).',
        ],
        'project' => [
            'FriendlyName' => 'Project',
            'Type' => 'dropdown',
            'Loader' => 'cloudpe_cmp_ProjectLoader',
            'SimpleMode' => true,
            'Description' => 'Project the VM is created under. Leave blank to use the server default (Access Hash).',
        ],
        'default_disk_size' => [
            'FriendlyName' => 'Default Disk Size',
            'Type' => 'dropdown',
            'Loader' => 'cloudpe_cmp_DiskSizeLoader',
            'SimpleMode' => true,
            'Description' => 'Default disk size when no "Disk Space" Configurable Option is selected.',
        ],
    ];
}

// =========================================================================
// Loader Functions
// =========================================================================

/**
 * Read a setting the Admin Addon has saved for a given server.
 *
 * Used by loaders below so the product dropdowns prefer the curated
 * selection (plus friendly display names) admins configured via the
 * CloudPe CMP Manager, falling back to a full live API list when no
 * curated selection exists yet.
 *
 * @param int $serverId WHMCS server ID (params['serverid'])
 * @param string $key   setting_key in mod_cloudpe_cmp_settings
 * @return mixed        decoded value or null
 */
function cloudpe_cmp_getAdminSetting(int $serverId, string $key)
{
    if ($serverId <= 0) {
        return null;
    }
    try {
        $row = Capsule::table('mod_cloudpe_cmp_settings')
            ->where('server_id', $serverId)
            ->where('setting_key', $key)
            ->first();
        if (!$row) {
            return null;
        }
        $decoded = json_decode($row->setting_value, true);
        return $decoded === null ? $row->setting_value : $decoded;
    } catch (Exception $e) {
        return null;
    }
}

function cloudpe_cmp_FlavorLoader(array $params): array
{
    $serverId = (int)($params['serverid'] ?? 0);
    // Prefer the curated selection saved via the Admin Addon so the
    // product dropdown stays aligned with what the admin chose to
    // expose (and uses the friendly Display Names).
    $selected = cloudpe_cmp_getAdminSetting($serverId, 'selected_flavors');
    $names    = cloudpe_cmp_getAdminSetting($serverId, 'flavor_names') ?: [];
    if (is_array($selected) && !empty($selected)) {
        $options = [];
        foreach ($selected as $id) {
            $options[$id] = $names[$id] ?? $id;
        }
        return $options;
    }

    try {
        $api = new CloudPeCmpAPI($params);
        $region = trim($params['configoption3'] ?? '');
        $result = $api->listFlavors($region);
        if ($result['success']) {
            $options = [];
            foreach ($result['flavors'] as $flavor) {
                $vcpu = $flavor['vcpu'] ?? $flavor['vcpus'] ?? '?';
                $ram = $flavor['memory_gb'] ?? round(($flavor['ram'] ?? 0) / 1024, 1);
                $label = ($flavor['name'] ?? $flavor['id']) . " ({$vcpu} vCPU, {$ram} GB RAM)";
                $options[$flavor['id']] = $label;
            }
            return $options;
        }
    } catch (Exception $e) {}
    return ['error' => 'Failed to load flavors'];
}

function cloudpe_cmp_ImageLoader(array $params): array
{
    $serverId = (int)($params['serverid'] ?? 0);
    $selected = cloudpe_cmp_getAdminSetting($serverId, 'selected_images');
    $names    = cloudpe_cmp_getAdminSetting($serverId, 'image_names') ?: [];
    if (is_array($selected) && !empty($selected)) {
        $options = [];
        foreach ($selected as $id) {
            $options[$id] = $names[$id] ?? $id;
        }
        return $options;
    }

    try {
        $api = new CloudPeCmpAPI($params);
        $region = trim($params['configoption3'] ?? '');
        $result = $api->listImages($region);
        if ($result['success']) {
            $options = [];
            foreach ($result['images'] as $image) {
                $options[$image['id']] = $image['name'] ?? $image['id'];
            }
            return $options;
        }
    } catch (Exception $e) {}
    return ['error' => 'Failed to load images'];
}

function cloudpe_cmp_RegionLoader(array $params): array
{
    try {
        $api = new CloudPeCmpAPI($params);
        $result = $api->listRegions('vm');
        if ($result['success']) {
            $options = [];
            foreach ($result['regions'] as $region) {
                $slug = $region['slug'] ?? $region['id'] ?? '';
                $name = $region['name'] ?? $slug;
                $options[$slug] = $name;
            }
            return $options;
        }
    } catch (Exception $e) {}
    return ['error' => 'Failed to load regions'];
}

function cloudpe_cmp_SecurityGroupLoader(array $params): array
{
    $serverId = (int)($params['serverid'] ?? 0);
    $selected = cloudpe_cmp_getAdminSetting($serverId, 'selected_security_groups');
    $names    = cloudpe_cmp_getAdminSetting($serverId, 'security_group_names') ?: [];
    if (is_array($selected) && !empty($selected)) {
        $options = [];
        foreach ($selected as $id) {
            $options[$id] = $names[$id] ?? $id;
        }
        return $options;
    }

    try {
        $api = new CloudPeCmpAPI($params);
        $projectId = trim($params['serveraccesshash'] ?? '');
        $region = trim($params['configoption3'] ?? '');
        if (empty($projectId)) {
            return ['error' => 'Project ID not configured (set Access Hash)'];
        }
        $result = $api->listSecurityGroups($projectId, $region);
        if ($result['success']) {
            $options = [];
            foreach ($result['security_groups'] as $sg) {
                $options[$sg['id']] = $sg['name'] ?? $sg['id'];
            }
            return $options;
        }
    } catch (Exception $e) {}
    return ['error' => 'Failed to load security groups'];
}

function cloudpe_cmp_VolumeTypeLoader(array $params): array
{
    try {
        $api = new CloudPeCmpAPI($params);
        $region = trim($params['configoption3'] ?? '');
        $result = $api->listVolumeTypes($region);
        if ($result['success']) {
            $options = [];
            foreach ($result['volume_types'] as $vt) {
                $name = $vt['name'] ?? $vt['id'] ?? '';
                $options[$name] = $name;
            }
            return $options;
        }
    } catch (Exception $e) {}
    return ['error' => 'Failed to load volume types'];
}

/**
 * Project dropdown loader. Prefers admin-curated selection from the
 * CloudPe CMP Manager; otherwise falls back to a live /projects query.
 */
function cloudpe_cmp_ProjectLoader(array $params): array
{
    $serverId = (int)($params['serverid'] ?? 0);
    $selected = cloudpe_cmp_getAdminSetting($serverId, 'selected_projects');
    $names    = cloudpe_cmp_getAdminSetting($serverId, 'project_names') ?: [];
    if (is_array($selected) && !empty($selected)) {
        $options = [];
        foreach ($selected as $id) {
            $options[$id] = $names[$id] ?? $id;
        }
        return $options;
    }

    try {
        $api = new CloudPeCmpAPI($params);
        $result = $api->listProjects();
        if ($result['success']) {
            $options = [];
            foreach ($result['projects'] as $p) {
                $id = $p['id'] ?? '';
                if (!$id) continue;
                $options[$id] = $p['name'] ?? $id;
            }
            if (!empty($options)) {
                return $options;
            }
        }
    } catch (Exception $e) {}
    // Empty fallback - admin can leave blank and use server Access Hash
    return ['' => '(use server default)'];
}

/**
 * Disk size dropdown loader. Sources sizes from the admin module's
 * saved Disk Sizes tab so the product config uses the same curated
 * list. Falls back to sensible defaults.
 */
function cloudpe_cmp_DiskSizeLoader(array $params): array
{
    $serverId = (int)($params['serverid'] ?? 0);
    $disks = cloudpe_cmp_getAdminSetting($serverId, 'disk_sizes');
    $options = [];
    if (is_array($disks) && !empty($disks)) {
        foreach ($disks as $disk) {
            $size  = (int)($disk['size_gb'] ?? $disk['size'] ?? 0);
            if (!$size) continue;
            $label = $disk['label'] ?? ($size . ' GB');
            $options[(string)$size] = $label;
        }
    }
    if (!empty($options)) {
        return $options;
    }
    return [
        '30'  => '30 GB',
        '50'  => '50 GB',
        '100' => '100 GB',
        '200' => '200 GB',
    ];
}

// =========================================================================
// Connection Test
// =========================================================================

function cloudpe_cmp_TestConnection(array $params): array
{
    try {
        $api = new CloudPeCmpAPI($params);
        $result = $api->testConnection();

        if ($result['success']) {
            return ['success' => true, 'error' => ''];
        }
        return ['success' => false, 'error' => $result['error'] ?? 'Connection failed'];
    } catch (Exception $e) {
        return ['success' => false, 'error' => $e->getMessage()];
    }
}

// =========================================================================
// Provisioning Functions
// =========================================================================

function cloudpe_cmp_CreateAccount(array $params): string
{
    try {
        $api = new CloudPeCmpAPI($params);
        $helper = new CloudPeCmpHelper();

        logModuleCall('cloudpe_cmp', 'CreateAccount', $params, '', 'Starting VM creation');

        // Get config options
        $defaultFlavorId  = trim($params['configoption1'] ?? '');
        $defaultImageId   = trim($params['configoption2'] ?? '');
        $region           = trim($params['configoption3'] ?? '');
        $billingPeriod    = $params['configoption4'] ?: 'monthly';
        $securityGroupId  = trim($params['configoption5'] ?? '');
        $minVolumeSize    = (int)($params['configoption6'] ?? 30);
        $volumeType       = trim($params['configoption7'] ?? '');
        // New dropdowns (configoption8=project override, configoption9=default disk size)
        $projectOverride  = trim($params['configoption8'] ?? '');
        $defaultDiskSize  = (int)($params['configoption9'] ?? 0);

        // Project ID resolution: per-product override > server-level Access Hash
        $projectId = $projectOverride !== '' ? $projectOverride : trim($params['serveraccesshash'] ?? '');

        // Get flavor from Configurable Options or default
        $flavorId = trim(
            $params['configoptions']['Server Size'] ??
            $params['configoptions']['Flavor'] ??
            $params['configoptions']['Plan'] ??
            $defaultFlavorId
        );

        // Get image from Configurable Options or default
        $imageId = trim(
            $params['configoptions']['Operating System'] ??
            $params['configoptions']['Image'] ??
            $params['configoptions']['OS'] ??
            $defaultImageId
        );

        // Get volume size: Configurable Options -> product default_disk_size -> min_volume_size
        $volumeSize = (int)(
            $params['configoptions']['Disk Space']
            ?? $params['configoptions']['Volume Size']
            ?? $defaultDiskSize
            ?? $minVolumeSize
        );
        if ($volumeSize < $minVolumeSize) $volumeSize = $minVolumeSize;
        if ($volumeSize < 30) $volumeSize = 30;

        // Validate
        if (empty($flavorId)) {
            return 'Configuration Error: No flavor/server size specified.';
        }
        if (empty($imageId)) {
            return 'Configuration Error: No OS image specified.';
        }
        if (empty($region)) {
            return 'Configuration Error: No region specified.';
        }
        if (empty($projectId)) {
            return 'Configuration Error: No project ID specified (set Access Hash on server).';
        }

        $hostname = $helper->generateHostname($params);
        $password = $helper->generatePassword();

        $instanceData = [
            'name' => $hostname,
            'flavor' => $flavorId,
            'image' => $imageId,
            'region' => $region,
            'project_id' => $projectId,
            'boot_volume_size_gb' => $volumeSize,
            'billing_period' => $billingPeriod,
        ];

        if (!empty($volumeType)) {
            $instanceData['volume_type'] = $volumeType;
        }

        if (!empty($securityGroupId)) {
            // CMP accepts either a single ID or an array of IDs
            $instanceData['security_group_ids'] = [$securityGroupId];
        }

        logModuleCall('cloudpe_cmp', 'CreateAccount', $instanceData, '', 'Sending create request');

        $result = $api->createInstance($instanceData);

        if (!$result['success']) {
            logModuleCall('cloudpe_cmp', 'CreateAccount', $instanceData, $result, 'Create failed');
            return 'Failed to create VM: ' . ($result['error'] ?? 'Unknown error');
        }

        $instanceId = $result['instance']['id'] ?? '';

        if (empty($instanceId)) {
            return 'Failed to create VM: No instance ID returned';
        }

        // Store admin password from API response if returned
        $adminPassword = $result['instance']['admin_password'] ?? $password;

        logModuleCall('cloudpe_cmp', 'CreateAccount', $instanceData, $result, 'VM created: ' . $instanceId);

        // Wait for VM to become active
        $maxWait = 120;
        $waited = 0;
        $vmData = null;

        while ($waited < $maxWait) {
            sleep(5);
            $waited += 5;

            $statusResult = $api->getInstance($instanceId, true);
            if ($statusResult['success']) {
                $status = strtoupper($statusResult['instance']['status'] ?? '');
                if ($status === 'ACTIVE') {
                    $vmData = $statusResult['instance'];
                    break;
                }
                if ($status === 'ERROR') {
                    return 'VM creation failed with ERROR status';
                }
            }
        }

        // Extract IPs
        $ips = ['ipv4' => '', 'ipv6' => ''];
        if ($vmData) {
            $ipData = $vmData['ip_addresses'] ?? $vmData['addresses'] ?? [];
            $ips = $helper->extractIPs($ipData);
        }

        // Update custom fields
        cloudpe_cmp_updateCustomField($params['serviceid'], $params['pid'], 'VM ID', $instanceId);
        cloudpe_cmp_updateCustomField($params['serviceid'], $params['pid'], 'Public IPv4', $ips['ipv4']);
        cloudpe_cmp_updateCustomField($params['serviceid'], $params['pid'], 'Public IPv6', $ips['ipv6']);

        // Update service
        $dedicatedIp = $ips['ipv4'] ?: $ips['ipv6'];
        Capsule::table('tblhosting')->where('id', $params['serviceid'])->update([
            'dedicatedip' => $dedicatedIp,
            'assignedips' => trim($ips['ipv4'] . "\n" . $ips['ipv6']),
            'password' => encrypt($adminPassword),
        ]);

        logModuleCall('cloudpe_cmp', 'CreateAccount', $instanceData, ['instance_id' => $instanceId, 'ips' => $ips], 'Complete');

        return 'success';

    } catch (Exception $e) {
        logModuleCall('cloudpe_cmp', 'CreateAccount', $params, $e->getMessage(), 'Exception');
        return 'Error: ' . $e->getMessage();
    }
}

function cloudpe_cmp_SuspendAccount(array $params): string
{
    try {
        $api = new CloudPeCmpAPI($params);
        $instanceId = cloudpe_cmp_getCustomField($params['serviceid'], $params['pid'], 'VM ID');

        if (empty($instanceId)) {
            return 'No VM ID found';
        }

        logModuleCall('cloudpe_cmp', 'SuspendAccount', ['instance_id' => $instanceId], '', 'Suspending');

        $result = $api->suspendInstance($instanceId);

        logModuleCall('cloudpe_cmp', 'SuspendAccount', ['instance_id' => $instanceId], $result, $result['success'] ? 'Success' : 'Failed');

        return $result['success'] ? 'success' : ('Failed: ' . ($result['error'] ?? 'Unknown error'));

    } catch (Exception $e) {
        return 'Error: ' . $e->getMessage();
    }
}

function cloudpe_cmp_UnsuspendAccount(array $params): string
{
    try {
        $api = new CloudPeCmpAPI($params);
        $instanceId = cloudpe_cmp_getCustomField($params['serviceid'], $params['pid'], 'VM ID');

        if (empty($instanceId)) {
            return 'No VM ID found';
        }

        logModuleCall('cloudpe_cmp', 'UnsuspendAccount', ['instance_id' => $instanceId], '', 'Resuming');

        $result = $api->resumeInstance($instanceId);

        logModuleCall('cloudpe_cmp', 'UnsuspendAccount', ['instance_id' => $instanceId], $result, $result['success'] ? 'Success' : 'Failed');

        return $result['success'] ? 'success' : ('Failed: ' . ($result['error'] ?? 'Unknown error'));

    } catch (Exception $e) {
        return 'Error: ' . $e->getMessage();
    }
}

function cloudpe_cmp_TerminateAccount(array $params): string
{
    try {
        $api = new CloudPeCmpAPI($params);
        $instanceId = cloudpe_cmp_getCustomField($params['serviceid'], $params['pid'], 'VM ID');

        if (empty($instanceId)) {
            return 'success'; // Already deleted
        }

        logModuleCall('cloudpe_cmp', 'TerminateAccount', ['instance_id' => $instanceId], '', 'Starting termination');

        $result = $api->deleteInstance($instanceId);

        if (!$result['success'] && ($result['httpCode'] ?? 0) != 404) {
            logModuleCall('cloudpe_cmp', 'TerminateAccount', ['instance_id' => $instanceId], $result, 'Delete failed');
            return 'Failed: ' . ($result['error'] ?? 'Unknown error');
        }

        // Clear custom fields
        cloudpe_cmp_updateCustomField($params['serviceid'], $params['pid'], 'VM ID', '');
        cloudpe_cmp_updateCustomField($params['serviceid'], $params['pid'], 'Public IPv4', '');
        cloudpe_cmp_updateCustomField($params['serviceid'], $params['pid'], 'Public IPv6', '');

        Capsule::table('tblhosting')->where('id', $params['serviceid'])->update([
            'dedicatedip' => '',
            'assignedips' => '',
        ]);

        logModuleCall('cloudpe_cmp', 'TerminateAccount', ['instance_id' => $instanceId], $result, 'Complete');

        return 'success';

    } catch (Exception $e) {
        return 'Error: ' . $e->getMessage();
    }
}

/**
 * Upgrade/Downgrade - Called when configurable options change
 */
function cloudpe_cmp_ChangePackage(array $params): string
{
    try {
        $api = new CloudPeCmpAPI($params);
        $instanceId = cloudpe_cmp_getCustomField($params['serviceid'], $params['pid'], 'VM ID');

        if (empty($instanceId)) {
            return 'No VM ID found';
        }

        logModuleCall('cloudpe_cmp', 'ChangePackage', $params, '', 'Starting upgrade/downgrade');

        // Get current instance info
        $instanceResult = $api->getInstance($instanceId, true);
        if (!$instanceResult['success']) {
            return 'Failed to get current VM info: ' . ($instanceResult['error'] ?? 'Unknown error');
        }

        $currentInstance = $instanceResult['instance'];
        $currentFlavorId = $currentInstance['flavor']['id'] ?? $currentInstance['flavor'] ?? '';

        // Get new values from configurable options or product settings
        $defaultFlavorId = trim($params['configoption1'] ?? '');
        $newFlavorId = trim(
            $params['configoptions']['Server Size'] ??
            $params['configoptions']['Flavor'] ??
            $params['configoptions']['Plan'] ??
            $defaultFlavorId
        );

        $minVolumeSize = (int)($params['configoption6'] ?? 30);
        $newVolumeSize = (int)($params['configoptions']['Disk Space'] ?? $params['configoptions']['Volume Size'] ?? 0);
        $projectId = trim($params['serveraccesshash'] ?? '');

        $results = [];
        $errors = [];

        // Handle flavor change (resize) via rebuild action
        if (!empty($newFlavorId) && $newFlavorId !== $currentFlavorId) {
            logModuleCall('cloudpe_cmp', 'ChangePackage', [
                'instance_id' => $instanceId,
                'old_flavor' => $currentFlavorId,
                'new_flavor' => $newFlavorId
            ], '', 'Resizing VM');

            // CMP API handles resize through instance update or action
            $resizeResult = $api->updateInstance($instanceId, ['flavor' => $newFlavorId]);

            if ($resizeResult['success']) {
                // Wait for resize to complete
                $waitResult = $api->waitForInstanceStatus($instanceId, 'ACTIVE', 120);
                if ($waitResult['success']) {
                    $results[] = 'VM resized successfully';
                } else {
                    $errors[] = 'Resize may still be in progress: ' . ($waitResult['error'] ?? 'Timeout');
                }
            } else {
                $errors[] = 'Failed to start resize: ' . ($resizeResult['error'] ?? 'Unknown error');
            }
        }

        // Handle volume resize (only increase supported)
        if ($newVolumeSize > 0 && $newVolumeSize >= $minVolumeSize && !empty($projectId)) {
            $volumesResult = $api->listVolumes($projectId, '', $instanceId);

            if ($volumesResult['success'] && !empty($volumesResult['volumes'])) {
                $volume = $volumesResult['volumes'][0] ?? null;
                $volumeId = $volume['id'] ?? '';
                $currentSize = (int)($volume['size_gb'] ?? $volume['size'] ?? 0);

                if (!empty($volumeId) && $newVolumeSize > $currentSize) {
                    logModuleCall('cloudpe_cmp', 'ChangePackage', [
                        'volume_id' => $volumeId,
                        'old_size' => $currentSize,
                        'new_size' => $newVolumeSize
                    ], '', 'Extending volume');

                    $extendResult = $api->extendVolume($volumeId, $newVolumeSize);

                    if ($extendResult['success']) {
                        $results[] = "Disk extended from {$currentSize}GB to {$newVolumeSize}GB";
                    } else {
                        $errors[] = 'Failed to extend disk: ' . ($extendResult['error'] ?? 'Unknown error');
                    }
                } elseif (!empty($volumeId) && $newVolumeSize < $currentSize) {
                    $results[] = "Disk size unchanged (shrinking not supported). Current: {$currentSize}GB";
                }
            }
        }

        // Return results
        if (!empty($errors)) {
            $message = implode('; ', $errors);
            if (!empty($results)) {
                $message = implode('; ', $results) . ' | Errors: ' . $message;
            }
            logModuleCall('cloudpe_cmp', 'ChangePackage', $params, $errors, 'Completed with errors');
            return $message;
        }

        if (!empty($results)) {
            logModuleCall('cloudpe_cmp', 'ChangePackage', $params, $results, 'Success');
        }

        return 'success';

    } catch (Exception $e) {
        logModuleCall('cloudpe_cmp', 'ChangePackage', $params, $e->getMessage(), 'Exception');
        return 'Error: ' . $e->getMessage();
    }
}

// =========================================================================
// Admin Services Tab
// =========================================================================

function cloudpe_cmp_AdminServicesTabFields(array $params): array
{
    try {
        $instanceId = cloudpe_cmp_getCustomField($params['serviceid'], $params['pid'], 'VM ID');

        if (empty($instanceId)) {
            return [
                'VM Status' => '<span class="label label-warning">Not Provisioned</span>',
            ];
        }

        $api = new CloudPeCmpAPI($params);
        $helper = new CloudPeCmpHelper();

        $result = $api->getInstance($instanceId, true);

        if (!$result['success']) {
            return [
                'VM Status' => '<span class="label label-danger">Error: ' . htmlspecialchars($result['error'] ?? 'Unknown') . '</span>',
                'VM ID' => $instanceId,
            ];
        }

        $instance = $result['instance'];
        $status = strtoupper($instance['status'] ?? 'Unknown');
        $ipData = $instance['ip_addresses'] ?? $instance['addresses'] ?? [];
        $ips = $helper->extractIPs($ipData);

        // Flavor info
        $flavorInfo = 'Unknown';
        $flavor = $instance['flavor'] ?? [];
        if (is_array($flavor)) {
            $vcpu = $flavor['vcpu'] ?? $flavor['vcpus'] ?? '?';
            $ram = $flavor['memory_gb'] ?? round(($flavor['ram'] ?? 0) / 1024, 1);
            $flavorName = $flavor['name'] ?? '';
            $flavorInfo = "{$vcpu} vCPU, {$ram} GB RAM";
            if ($flavorName) {
                $flavorInfo .= " <small class=\"text-muted\">({$flavorName})</small>";
            }
        }

        // Image info
        $imageName = 'Unknown';
        $image = $instance['image'] ?? [];
        if (is_array($image)) {
            $imageName = $image['name'] ?? 'Unknown';
        } elseif (is_string($image)) {
            $imageName = $image;
        }

        // Disk info from instance data or volumes
        $diskInfo = 'Unknown';
        if (!empty($instance['boot_volume_size_gb'])) {
            $diskInfo = $instance['boot_volume_size_gb'] . ' GB';
        }

        return [
            'VM Status' => $helper->getStatusLabel($status),
            'VM ID' => '<code>' . htmlspecialchars($instanceId) . '</code>',
            'Hostname' => htmlspecialchars($instance['name'] ?? ''),
            'Operating System' => htmlspecialchars($imageName),
            'CPU & RAM' => $flavorInfo,
            'Disk' => $diskInfo,
            'IPv4' => $ips['ipv4'] ?: '<span class="text-muted">Not assigned</span>',
            'IPv6' => $ips['ipv6'] ?: '<span class="text-muted">Not assigned</span>',
            'Created' => $instance['created_at'] ?? '',
        ];

    } catch (Exception $e) {
        return [
            'Error' => '<span class="label label-danger">' . htmlspecialchars($e->getMessage()) . '</span>',
        ];
    }
}

// =========================================================================
// Admin Actions
// =========================================================================

function cloudpe_cmp_AdminCustomButtonArray(): array
{
    return [
        'Start VM' => 'AdminStart',
        'Stop VM' => 'AdminStop',
        'Restart VM' => 'AdminRestart',
        'VNC Console' => 'AdminConsole',
        'Change Password' => 'AdminChangePassword',
        'Apply Upgrade' => 'AdminUpgrade',
        'Sync Status' => 'AdminSync',
    ];
}

function cloudpe_cmp_AdminStart(array $params): string
{
    try {
        $api = new CloudPeCmpAPI($params);
        $helper = new CloudPeCmpHelper();
        $instanceId = cloudpe_cmp_getCustomField($params['serviceid'], $params['pid'], 'VM ID');

        if (empty($instanceId)) return 'No VM ID found';

        logModuleCall('cloudpe_cmp', 'AdminStart', ['instance_id' => $instanceId], '', 'Starting');
        $result = $api->startInstance($instanceId);

        if (!$result['success']) {
            return 'Failed: ' . ($result['error'] ?? 'Unknown error');
        }

        // Wait and sync IPs
        $waitResult = $api->waitForInstanceStatus($instanceId, 'ACTIVE', 30);
        if ($waitResult['success'] && !empty($waitResult['instance'])) {
            cloudpe_cmp_syncIPs($params, $waitResult['instance'], $helper);
        }

        return 'success';
    } catch (Exception $e) {
        return 'Error: ' . $e->getMessage();
    }
}

function cloudpe_cmp_AdminStop(array $params): string
{
    try {
        $api = new CloudPeCmpAPI($params);
        $instanceId = cloudpe_cmp_getCustomField($params['serviceid'], $params['pid'], 'VM ID');

        if (empty($instanceId)) return 'No VM ID found';

        logModuleCall('cloudpe_cmp', 'AdminStop', ['instance_id' => $instanceId], '', 'Stopping');
        $result = $api->stopInstance($instanceId);

        if (!$result['success']) {
            return 'Failed: ' . ($result['error'] ?? 'Unknown error');
        }

        $api->waitForInstanceStatus($instanceId, 'SHUTOFF', 30);
        return 'success';
    } catch (Exception $e) {
        return 'Error: ' . $e->getMessage();
    }
}

function cloudpe_cmp_AdminRestart(array $params): string
{
    try {
        $api = new CloudPeCmpAPI($params);
        $helper = new CloudPeCmpHelper();
        $instanceId = cloudpe_cmp_getCustomField($params['serviceid'], $params['pid'], 'VM ID');

        if (empty($instanceId)) return 'No VM ID found';

        logModuleCall('cloudpe_cmp', 'AdminRestart', ['instance_id' => $instanceId], '', 'Restarting');
        $result = $api->rebootInstance($instanceId);

        if (!$result['success']) {
            return 'Failed: ' . ($result['error'] ?? 'Unknown error');
        }

        $waitResult = $api->waitForInstanceStatus($instanceId, 'ACTIVE', 30);
        if ($waitResult['success'] && !empty($waitResult['instance'])) {
            cloudpe_cmp_syncIPs($params, $waitResult['instance'], $helper);
        }

        return 'success';
    } catch (Exception $e) {
        return 'Error: ' . $e->getMessage();
    }
}

function cloudpe_cmp_AdminConsole(array $params): string
{
    try {
        $api = new CloudPeCmpAPI($params);
        $instanceId = cloudpe_cmp_getCustomField($params['serviceid'], $params['pid'], 'VM ID');

        if (empty($instanceId)) return 'No VM ID found';

        $result = $api->getConsoleUrl($instanceId);

        if ($result['success'] && !empty($result['url'])) {
            header('Location: ' . $result['url']);
            exit;
        }

        return 'Console URL not received: ' . ($result['error'] ?? 'No URL in response');
    } catch (Exception $e) {
        return 'Error: ' . $e->getMessage();
    }
}

function cloudpe_cmp_AdminChangePassword(array $params): string
{
    try {
        $api = new CloudPeCmpAPI($params);
        $helper = new CloudPeCmpHelper();
        $instanceId = cloudpe_cmp_getCustomField($params['serviceid'], $params['pid'], 'VM ID');

        if (empty($instanceId)) return 'No VM ID found';

        $newPassword = $helper->generatePassword();
        $result = $api->changePassword($instanceId, $newPassword);

        if ($result['success']) {
            Capsule::table('tblhosting')->where('id', $params['serviceid'])->update([
                'password' => encrypt($newPassword),
            ]);
            return 'success';
        }

        return 'Failed: ' . ($result['error'] ?? 'Unknown error');
    } catch (Exception $e) {
        return 'Error: ' . $e->getMessage();
    }
}

function cloudpe_cmp_AdminUpgrade(array $params): string
{
    return cloudpe_cmp_ChangePackage($params);
}

function cloudpe_cmp_AdminSync(array $params): string
{
    try {
        $api = new CloudPeCmpAPI($params);
        $helper = new CloudPeCmpHelper();
        $instanceId = cloudpe_cmp_getCustomField($params['serviceid'], $params['pid'], 'VM ID');

        if (empty($instanceId)) return 'No VM ID found';

        $result = $api->getInstance($instanceId, true);

        if (!$result['success']) {
            return 'Failed: ' . ($result['error'] ?? 'Unknown error');
        }

        cloudpe_cmp_syncIPs($params, $result['instance'], $helper);

        return 'success';
    } catch (Exception $e) {
        return 'Error: ' . $e->getMessage();
    }
}

// =========================================================================
// Client Area Functions
// =========================================================================

function cloudpe_cmp_ClientAreaAllowedFunctions(): array
{
    return [
        'ClientStart' => 'Start',
        'ClientStop' => 'Stop',
        'ClientRestart' => 'Restart',
        'ClientConsole' => 'Console',
        'ClientChangePassword' => 'Reset Password',
    ];
}

function cloudpe_cmp_isAjax(): bool
{
    return !empty($_SERVER['HTTP_X_REQUESTED_WITH']) &&
           strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';
}

function cloudpe_cmp_jsonResponse(bool $success, string $message, array $data = []): void
{
    header('Content-Type: application/json');
    echo json_encode(array_merge([
        'success' => $success,
        'message' => $message,
    ], $data));
    exit;
}

function cloudpe_cmp_ClientStart(array $params): string
{
    $isAjax = cloudpe_cmp_isAjax();

    try {
        $api = new CloudPeCmpAPI($params);
        $helper = new CloudPeCmpHelper();
        $instanceId = cloudpe_cmp_getCustomField($params['serviceid'], $params['pid'], 'VM ID');

        if (empty($instanceId)) {
            if ($isAjax) cloudpe_cmp_jsonResponse(false, 'No VM ID found');
            return 'No VM ID found';
        }

        $result = $api->startInstance($instanceId);

        if (!$result['success']) {
            $error = $result['error'] ?? 'Unknown error';
            if ($isAjax) cloudpe_cmp_jsonResponse(false, 'Failed to start VM: ' . $error);
            return 'Failed: ' . $error;
        }

        $waitResult = $api->waitForInstanceStatus($instanceId, 'ACTIVE', 30);
        $newStatus = 'UNKNOWN';
        if ($waitResult['success'] && !empty($waitResult['instance'])) {
            $newStatus = $waitResult['instance']['status'] ?? 'ACTIVE';
            cloudpe_cmp_syncIPs($params, $waitResult['instance'], $helper);
        }

        if ($isAjax) cloudpe_cmp_jsonResponse(true, 'VM started successfully', ['status' => $newStatus]);
        return 'success';
    } catch (Exception $e) {
        if ($isAjax) cloudpe_cmp_jsonResponse(false, 'Error: ' . $e->getMessage());
        return 'Error: ' . $e->getMessage();
    }
}

function cloudpe_cmp_ClientStop(array $params): string
{
    $isAjax = cloudpe_cmp_isAjax();

    try {
        $api = new CloudPeCmpAPI($params);
        $instanceId = cloudpe_cmp_getCustomField($params['serviceid'], $params['pid'], 'VM ID');

        if (empty($instanceId)) {
            if ($isAjax) cloudpe_cmp_jsonResponse(false, 'No VM ID found');
            return 'No VM ID found';
        }

        $result = $api->stopInstance($instanceId);

        if (!$result['success']) {
            $error = $result['error'] ?? 'Unknown error';
            if ($isAjax) cloudpe_cmp_jsonResponse(false, 'Failed to stop VM: ' . $error);
            return 'Failed: ' . $error;
        }

        $waitResult = $api->waitForInstanceStatus($instanceId, 'SHUTOFF', 30);
        $newStatus = $waitResult['success'] ? 'SHUTOFF' : 'UNKNOWN';

        if ($isAjax) cloudpe_cmp_jsonResponse(true, 'VM stopped successfully', ['status' => $newStatus]);
        return 'success';
    } catch (Exception $e) {
        if ($isAjax) cloudpe_cmp_jsonResponse(false, 'Error: ' . $e->getMessage());
        return 'Error: ' . $e->getMessage();
    }
}

function cloudpe_cmp_ClientRestart(array $params): string
{
    $isAjax = cloudpe_cmp_isAjax();

    try {
        $api = new CloudPeCmpAPI($params);
        $helper = new CloudPeCmpHelper();
        $instanceId = cloudpe_cmp_getCustomField($params['serviceid'], $params['pid'], 'VM ID');

        if (empty($instanceId)) {
            if ($isAjax) cloudpe_cmp_jsonResponse(false, 'No VM ID found');
            return 'No VM ID found';
        }

        $result = $api->rebootInstance($instanceId);

        if (!$result['success']) {
            $error = $result['error'] ?? 'Unknown error';
            if ($isAjax) cloudpe_cmp_jsonResponse(false, 'Failed to restart VM: ' . $error);
            return 'Failed: ' . $error;
        }

        $waitResult = $api->waitForInstanceStatus($instanceId, 'ACTIVE', 30);
        $newStatus = 'UNKNOWN';
        if ($waitResult['success'] && !empty($waitResult['instance'])) {
            $newStatus = $waitResult['instance']['status'] ?? 'ACTIVE';
            cloudpe_cmp_syncIPs($params, $waitResult['instance'], $helper);
        }

        if ($isAjax) cloudpe_cmp_jsonResponse(true, 'VM restarted successfully', ['status' => $newStatus]);
        return 'success';
    } catch (Exception $e) {
        if ($isAjax) cloudpe_cmp_jsonResponse(false, 'Error: ' . $e->getMessage());
        return 'Error: ' . $e->getMessage();
    }
}

function cloudpe_cmp_ClientConsole(array $params): string
{
    $isAjax = cloudpe_cmp_isAjax();

    try {
        $api = new CloudPeCmpAPI($params);
        $instanceId = cloudpe_cmp_getCustomField($params['serviceid'], $params['pid'], 'VM ID');

        if (empty($instanceId)) {
            if ($isAjax) cloudpe_cmp_jsonResponse(false, 'No VM ID found');
            return 'No VM ID found';
        }

        $result = $api->getConsoleUrl($instanceId);

        if ($result['success'] && !empty($result['url'])) {
            if ($isAjax) cloudpe_cmp_jsonResponse(true, 'Console ready', ['url' => $result['url']]);
            header('Location: ' . $result['url']);
            exit;
        }

        $error = $result['error'] ?? 'No URL in response';
        if ($isAjax) cloudpe_cmp_jsonResponse(false, 'Console URL not received: ' . $error);
        return 'Console URL not received: ' . $error;
    } catch (Exception $e) {
        if ($isAjax) cloudpe_cmp_jsonResponse(false, 'Error: ' . $e->getMessage());
        return 'Error: ' . $e->getMessage();
    }
}

function cloudpe_cmp_ClientChangePassword(array $params): string
{
    $isAjax = cloudpe_cmp_isAjax();

    try {
        $api = new CloudPeCmpAPI($params);
        $helper = new CloudPeCmpHelper();
        $instanceId = cloudpe_cmp_getCustomField($params['serviceid'], $params['pid'], 'VM ID');

        if (empty($instanceId)) {
            if ($isAjax) cloudpe_cmp_jsonResponse(false, 'No VM ID found');
            return 'No VM ID found';
        }

        $newPassword = $helper->generatePassword();
        $result = $api->changePassword($instanceId, $newPassword);

        if ($result['success']) {
            Capsule::table('tblhosting')->where('id', $params['serviceid'])->update([
                'password' => encrypt($newPassword),
            ]);
            if ($isAjax) cloudpe_cmp_jsonResponse(true, 'Password reset successfully. Reload page to view new password.');
            return 'success';
        }

        $error = $result['error'] ?? 'Unknown error';
        if ($isAjax) cloudpe_cmp_jsonResponse(false, 'Failed to reset password: ' . $error);
        return 'Failed: ' . $error;
    } catch (Exception $e) {
        if ($isAjax) cloudpe_cmp_jsonResponse(false, 'Error: ' . $e->getMessage());
        return 'Error: ' . $e->getMessage();
    }
}

function cloudpe_cmp_ClientArea(array $params): array
{
    try {
        $instanceId = cloudpe_cmp_getCustomField($params['serviceid'], $params['pid'], 'VM ID');

        if (empty($instanceId)) {
            return [
                'templatefile' => 'templates/no_vm',
                'vars' => [
                    'message' => 'VM not yet provisioned',
                ],
            ];
        }

        $api = new CloudPeCmpAPI($params);
        $helper = new CloudPeCmpHelper();

        $result = $api->getInstance($instanceId, true);

        if (!$result['success']) {
            return [
                'templatefile' => 'templates/error',
                'vars' => [
                    'error' => $result['error'] ?? 'Failed to get VM status',
                ],
            ];
        }

        $instance = $result['instance'];
        $ipData = $instance['ip_addresses'] ?? $instance['addresses'] ?? [];
        $ips = $helper->extractIPs($ipData);
        $status = strtoupper($instance['status'] ?? 'Unknown');

        // Flavor details
        $flavorName = 'Unknown';
        $flavorVcpus = '-';
        $flavorRam = '-';
        $flavor = $instance['flavor'] ?? [];
        if (is_array($flavor)) {
            $flavorName = $flavor['name'] ?? 'Unknown';
            $flavorVcpus = $flavor['vcpu'] ?? $flavor['vcpus'] ?? '-';
            $flavorRam = $flavor['memory_gb'] ?? round(($flavor['ram'] ?? 0) / 1024, 1);
        }

        // Image details
        $imageName = 'Unknown';
        $image = $instance['image'] ?? [];
        if (is_array($image)) {
            $imageName = $image['name'] ?? 'Unknown';
        } elseif (is_string($image)) {
            $imageName = $image;
        }

        // Disk size
        $diskSize = $instance['boot_volume_size_gb'] ?? '-';

        $systemUrl = rtrim($GLOBALS['CONFIG']['SystemURL'] ?? '', '/');

        return [
            'templatefile' => 'templates/overview',
            'vars' => [
                'serviceid' => $params['serviceid'],
                'server_id' => $instanceId,
                'status' => $status,
                'status_label' => $helper->getStatusLabel($status),
                'hostname' => $instance['name'] ?? '',
                'ipv4' => $ips['ipv4'],
                'ipv6' => $ips['ipv6'],
                'created' => $instance['created_at'] ?? '',
                'vcpus' => $flavorVcpus,
                'ram' => $flavorRam,
                'disk' => $diskSize,
                'os' => $imageName,
                'flavor_name' => $flavorName,
                'WEB_ROOT' => $systemUrl,
            ],
        ];

    } catch (Exception $e) {
        return [
            'templatefile' => 'templates/error',
            'vars' => [
                'error' => $e->getMessage(),
            ],
        ];
    }
}

// =========================================================================
// Helper Functions
// =========================================================================

function cloudpe_cmp_getCustomField(int $serviceId, int $productId, string $fieldName): string
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

function cloudpe_cmp_updateCustomField(int $serviceId, int $productId, string $fieldName, string $value): bool
{
    $field = Capsule::table('tblcustomfields')
        ->where('relid', $productId)
        ->where('type', 'product')
        ->where('fieldname', $fieldName)
        ->first();

    if (!$field) return false;

    Capsule::table('tblcustomfieldsvalues')->updateOrInsert(
        ['fieldid' => $field->id, 'relid' => $serviceId],
        ['value' => $value]
    );

    return true;
}

/**
 * Sync IPs from instance data to WHMCS service
 */
function cloudpe_cmp_syncIPs(array $params, array $instance, CloudPeCmpHelper $helper): void
{
    $ipData = $instance['ip_addresses'] ?? $instance['addresses'] ?? [];
    $ips = $helper->extractIPs($ipData);

    cloudpe_cmp_updateCustomField($params['serviceid'], $params['pid'], 'Public IPv4', $ips['ipv4']);
    cloudpe_cmp_updateCustomField($params['serviceid'], $params['pid'], 'Public IPv6', $ips['ipv6']);

    $dedicatedIp = $ips['ipv4'] ?: $ips['ipv6'];
    Capsule::table('tblhosting')->where('id', $params['serviceid'])->update([
        'dedicatedip' => $dedicatedIp,
        'assignedips' => trim($ips['ipv4'] . "\n" . $ips['ipv6']),
    ]);
}

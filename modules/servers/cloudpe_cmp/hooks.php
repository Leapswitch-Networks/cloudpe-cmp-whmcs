<?php
/**
 * CloudPe CMP WHMCS Module Hooks
 *
 * Handles validation and UI enhancements for CloudPe CMP services
 *
 * @version 1.0.0
 */

if (!defined("WHMCS")) {
    die("This file cannot be accessed directly");
}

use WHMCS\Database\Capsule;

/**
 * Hook: Validate configurable options on upgrade
 * Prevents selecting smaller disk size than current
 */
add_hook('ShoppingCartValidateCheckout', 1, function($vars) {
    if (empty($_SESSION['upgradealiases'])) {
        return;
    }

    foreach ($_SESSION['upgradealiases'] as $upgradeKey => $upgradeData) {
        $serviceId = $upgradeData['serviceid'] ?? 0;
        if (empty($serviceId)) {
            continue;
        }

        $service = Capsule::table('tblhosting')
            ->join('tblproducts', 'tblhosting.packageid', '=', 'tblproducts.id')
            ->where('tblhosting.id', $serviceId)
            ->where('tblproducts.servertype', 'cloudpe_cmp')
            ->select('tblhosting.*', 'tblproducts.id as product_id')
            ->first();

        if (!$service) {
            continue;
        }

        $currentDiskSize = cloudpe_cmp_get_current_disk_size($serviceId, $service->product_id);

        if ($currentDiskSize <= 0) {
            continue;
        }

        $newDiskSize = 0;

        if (!empty($vars['configoptions'])) {
            foreach ($vars['configoptions'] as $optionId => $value) {
                $option = Capsule::table('tblproductconfigoptions')
                    ->where('id', $optionId)
                    ->first();

                if ($option && in_array($option->optionname, ['Disk Space', 'Volume Size'])) {
                    $parts = explode('|', $value);
                    $newDiskSize = (int)$parts[0];
                    break;
                }
            }
        }

        if ($newDiskSize == 0 && !empty($_POST['configoption'])) {
            foreach ($_POST['configoption'] as $optionId => $value) {
                $option = Capsule::table('tblproductconfigoptions')
                    ->where('id', $optionId)
                    ->first();

                if ($option && in_array($option->optionname, ['Disk Space', 'Volume Size'])) {
                    $subOption = Capsule::table('tblproductconfigoptionssub')
                        ->where('id', $value)
                        ->first();

                    if ($subOption) {
                        $parts = explode('|', $subOption->optionname);
                        $newDiskSize = (int)$parts[0];
                    }
                    break;
                }
            }
        }

        if ($newDiskSize > 0 && $newDiskSize < $currentDiskSize) {
            return [
                'error' => "Disk size cannot be reduced. Your current disk is {$currentDiskSize}GB. Please select {$currentDiskSize}GB or larger.",
            ];
        }
    }
});

/**
 * Hook: Add warning message in client area for disk upgrades
 */
add_hook('ClientAreaPageUpgrade', 1, function($vars) {
    $serviceId = $_GET['id'] ?? 0;

    if (empty($serviceId)) {
        return;
    }

    $service = Capsule::table('tblhosting')
        ->join('tblproducts', 'tblhosting.packageid', '=', 'tblproducts.id')
        ->where('tblhosting.id', $serviceId)
        ->where('tblproducts.servertype', 'cloudpe_cmp')
        ->select('tblhosting.*', 'tblproducts.id as product_id')
        ->first();

    if (!$service) {
        return;
    }

    $currentDiskSize = cloudpe_cmp_get_current_disk_size($serviceId, $service->product_id);

    if ($currentDiskSize > 0) {
        return [
            'cloudpe_cmp_current_disk' => $currentDiskSize,
            'cloudpe_cmp_disk_warning' => "Note: Disk size can only be increased. Your current disk is {$currentDiskSize}GB.",
        ];
    }
});

/**
 * Helper: Get current disk size for a CloudPe CMP service
 */
function cloudpe_cmp_get_current_disk_size($serviceId, $productId)
{
    try {
        $serverId = '';

        $field = Capsule::table('tblcustomfields')
            ->where('relid', $productId)
            ->where('type', 'product')
            ->where('fieldname', 'VM ID')
            ->first();

        if ($field) {
            $value = Capsule::table('tblcustomfieldsvalues')
                ->where('fieldid', $field->id)
                ->where('relid', $serviceId)
                ->first();

            $serverId = $value->value ?? '';
        }

        if (empty($serverId)) {
            return 0;
        }

        $service = Capsule::table('tblhosting')->where('id', $serviceId)->first();
        $server = Capsule::table('tblservers')->where('id', $service->server)->first();

        if (!$server) {
            return 0;
        }

        $apiPath = ROOTDIR . '/modules/servers/cloudpe_cmp/lib/CloudPeCmpAPI.php';
        if (!file_exists($apiPath)) {
            return 0;
        }

        require_once $apiPath;

        $api = new CloudPeCmpAPI([
            'serverhostname' => $server->hostname,
            'serverpassword' => decrypt($server->password),
            'serveraccesshash' => $server->accesshash,
            'serversecure' => $server->secure,
        ]);

        // Try to get disk info from instance
        $instanceResult = $api->getInstance($serverId);
        if ($instanceResult['success'] && !empty($instanceResult['instance']['boot_volume_size_gb'])) {
            return (int)$instanceResult['instance']['boot_volume_size_gb'];
        }

        // Fallback: list volumes for the instance
        $projectId = trim($server->accesshash ?? '');
        if (!empty($projectId)) {
            $volumesResult = $api->listVolumes($projectId, '', $serverId);
            if ($volumesResult['success'] && !empty($volumesResult['volumes'])) {
                return (int)($volumesResult['volumes'][0]['size_gb'] ?? $volumesResult['volumes'][0]['size'] ?? 0);
            }
        }
    } catch (Exception $e) {
        // Silently fail
    }

    return 0;
}

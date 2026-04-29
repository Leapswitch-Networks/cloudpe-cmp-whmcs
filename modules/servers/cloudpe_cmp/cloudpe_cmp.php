<?php
/**
 * CloudPe CMP WHMCS Provisioning Module
 *
 * Provisions virtual machines on CloudPe CMP (FastAPI backend)
 * using API Key authentication.
 *
 * @version 1.0.4
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
    // Product Module Settings -> Configuration. Four defaults the admin
    // picks per product; client cart configurable options can override
    // each. Volume Type is NOT a Module Setting — it is configured per
    // region in the admin addon (Volume Types tab).
    //
    // WHMCS lays out Module Settings in two columns in declaration order
    // (col1, col2, col1, col2, ...). The desired layout is:
    //   Default Region        | Default Server Size
    //   Default Disk Space    | Default Operating System
    //
    // Positional indices (referenced as configoption1..4 in CreateAccount):
    //   1 = region            (Default Region)
    //   2 = flavor            (Default Server Size)
    //   3 = default_disk_size (Default Disk Space)
    //   4 = image             (Default Operating System)
    // Type=text+Loader (not dropdown) so WHMCS Advanced mode shows a
    // normal text input pre-filled with the stored id. Dropdown types
    // render empty text inputs in Advanced mode which confuses admins.
    return [
        'region' => [
            'FriendlyName' => 'Default Region',
            'Type' => 'text',
            'Size' => 64,
            'Loader' => 'cloudpe_cmp_RegionLoader',
            'SimpleMode' => true,
            'Description' => 'Default region (used when the cart has no "Region" Configurable Option).',
        ],
        'flavor' => [
            'FriendlyName' => 'Default Server Size',
            'Type' => 'text',
            'Size' => 64,
            'Loader' => 'cloudpe_cmp_FlavorLoader',
            'SimpleMode' => true,
            'Description' => 'Default VM size (overridden by cart "Server Size" Configurable Option).',
        ],
        'default_disk_size' => [
            'FriendlyName' => 'Default Disk Space',
            'Type' => 'text',
            'Size' => 10,
            'Loader' => 'cloudpe_cmp_DiskSizeLoader',
            'SimpleMode' => true,
            'Description' => 'Default disk size in GB (overridden by cart "Disk Space" Configurable Option).',
        ],
        'image' => [
            'FriendlyName' => 'Default Operating System',
            'Type' => 'text',
            'Size' => 64,
            'Loader' => 'cloudpe_cmp_ImageLoader',
            'SimpleMode' => true,
            'Description' => 'Default OS image (overridden by cart "Operating System" Configurable Option).',
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
function cloudpe_cmp_getAdminSetting(int $serverId, string $key, ?string $regionId = null)
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
        $value   = $decoded === null ? $row->setting_value : $decoded;

        // Phase 4: region-scoped reads. Detect region-nested maps (top-level
        // keys are non-numeric strings — region IDs).
        if (is_array($value) && !empty($value)) {
            $isRegionNested = true;
            foreach (array_keys($value) as $k) {
                if (is_int($k) || ctype_digit((string)$k)) { $isRegionNested = false; break; }
            }
            if ($isRegionNested) {
                if ($regionId !== null) {
                    return $value[$regionId] ?? null;
                }
                // No region requested — flatten the union so legacy callers
                // (Module Settings loaders) still get a usable shape.
                $union = [];
                foreach ($value as $slice) {
                    if (!is_array($slice)) continue;
                    foreach ($slice as $k => $v) {
                        if (is_int($k) || ctype_digit((string)$k)) {
                            // Indexed list — append, dedupe later.
                            $union[] = $v;
                        } else {
                            // Associative — merge by id.
                            $union[$k] = $v;
                        }
                    }
                }
                // Dedupe pure index lists.
                $allIndexed = !empty($union);
                foreach (array_keys($union) as $k) {
                    if (!is_int($k) && !ctype_digit((string)$k)) { $allIndexed = false; break; }
                }
                if ($allIndexed) $union = array_values(array_unique($union));
                return $union;
            }
        }
        return $value;
    } catch (Exception $e) {
        return null;
    }
}

function cloudpe_cmp_BillingPeriodLoader(array $params): array
{
    return ['monthly' => 'Monthly', 'hourly' => 'Hourly'];
}

function cloudpe_cmp_IpVersionLoader(array $params): array
{
    return ['ipv4' => 'IPv4 Only', 'ipv6' => 'IPv6 Only', 'both' => 'Both IPv4 and IPv6'];
}

/**
 * Read the raw stored value for a setting (no region union flattening).
 * Loaders use this to walk the region-nested map and produce labels with
 * region context.
 */
function cloudpe_cmp_getAdminSettingRaw(int $serverId, string $key)
{
    if ($serverId <= 0) return null;
    try {
        $row = Capsule::table('mod_cloudpe_cmp_settings')
            ->where('server_id', $serverId)
            ->where('setting_key', $key)
            ->first();
        if (!$row) return null;
        $decoded = json_decode($row->setting_value, true);
        return $decoded === null ? $row->setting_value : $decoded;
    } catch (Exception $e) { return null; }
}

/**
 * Detect whether a value is region-nested (top-level keys are non-numeric).
 */
function cloudpe_cmp_isRegionNested($value): bool
{
    if (!is_array($value) || empty($value)) return false;
    foreach (array_keys($value) as $k) {
        if (is_int($k) || ctype_digit((string)$k)) return false;
    }
    return true;
}

/**
 * Fetch region_id => display_name map for the bound server. Cached per
 * request via a static. Falls back to region_id if name lookup fails.
 */
function cloudpe_cmp_getRegionNames(array $params): array
{
    static $cache = [];
    $serverId = (int)($params['serverid'] ?? 0);
    if (isset($cache[$serverId])) return $cache[$serverId];
    $map = [];
    try {
        $api = new CloudPeCmpAPI($params);
        $result = $api->listRegions();
        if (!empty($result['success']) && !empty($result['regions'])) {
            foreach ($result['regions'] as $r) {
                $id = $r['id'] ?? '';
                if (!$id) continue;
                $display = $r['display_name'] ?? '';
                $name    = $r['name'] ?? '';
                if ($display !== '' && $name !== '' && $display !== $name) {
                    $map[$id] = $display . ' (' . $name . ')';
                } else {
                    $map[$id] = $display ?: ($name ?: $id);
                }
            }
        }
    } catch (Exception $e) {}
    $cache[$serverId] = $map;
    return $map;
}

/**
 * Build a [id => "Label (Region)"] options array from a region-nested
 * `selected_*` setting + matching `*_names` setting. Falls back gracefully
 * for legacy flat data (no region suffix).
 */
function cloudpe_cmp_buildRegionScopedOptions(array $params, string $selectedKey, string $namesKey, string $sidecarKey = ''): ?array
{
    $serverId   = (int)($params['serverid'] ?? 0);
    $rawSel     = cloudpe_cmp_getAdminSettingRaw($serverId, $selectedKey);
    $rawNames   = cloudpe_cmp_getAdminSettingRaw($serverId, $namesKey);

    if (cloudpe_cmp_isRegionNested($rawSel)) {
        $regionNames = cloudpe_cmp_getRegionNames($params);
        $options = [];
        foreach ($rawSel as $regionId => $ids) {
            if (!is_array($ids) || empty($ids)) continue;
            $rLabel = $regionNames[$regionId] ?? ($regionId !== '' ? $regionId : 'Unassigned');
            $namesForRegion = (cloudpe_cmp_isRegionNested($rawNames) && isset($rawNames[$regionId]))
                ? (array)$rawNames[$regionId]
                : [];
            foreach ($ids as $id) {
                $base = $namesForRegion[$id] ?? $id;
                $options[$id] = $base . ' — ' . $rLabel;
            }
        }
        return empty($options) ? null : $options;
    }

    // Flat legacy data — try on-the-fly bucketing via the sidecar map so
    // labels still carry region context even before the addon migrates.
    if (is_array($rawSel) && !empty($rawSel) && $sidecarKey !== '') {
        $sidecar = cloudpe_cmp_getAdminSettingRaw($serverId, $sidecarKey);
        if (is_array($sidecar) && !empty($sidecar)) {
            $regionNames = cloudpe_cmp_getRegionNames($params);
            $namesFlat   = is_array($rawNames) ? $rawNames : [];
            $options = [];
            foreach ($rawSel as $id) {
                $rId    = $sidecar[$id] ?? '';
                $rLabel = $regionNames[$rId] ?? ($rId !== '' ? $rId : 'Unassigned');
                $base   = $namesFlat[$id] ?? $id;
                $options[$id] = $base . ' — ' . $rLabel;
            }
            return $options;
        }
    }

    if (is_array($rawSel) && !empty($rawSel)) {
        $names = is_array($rawNames) ? $rawNames : [];
        $options = [];
        foreach ($rawSel as $id) {
            $options[$id] = $names[$id] ?? $id;
        }
        return $options;
    }

    return null;
}

function cloudpe_cmp_FlavorLoader(array $params): array
{
    // Prefer the curated selection saved via the Admin Addon so the
    // product dropdown stays aligned with what the admin chose to
    // expose (and uses the friendly Display Names + region context).
    $opts = cloudpe_cmp_buildRegionScopedOptions($params, 'selected_flavors', 'flavor_names', 'flavor_regions');
    if ($opts !== null) return $opts;

    try {
        $api = new CloudPeCmpAPI($params);
        $result = $api->listFlavors();
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
    $opts = cloudpe_cmp_buildRegionScopedOptions($params, 'selected_images', 'image_names', 'image_regions');
    if ($opts !== null) return $opts;

    try {
        $api = new CloudPeCmpAPI($params);
        $result = $api->listImages();
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

function cloudpe_cmp_SecurityGroupLoader(array $params): array
{
    $opts = cloudpe_cmp_buildRegionScopedOptions($params, 'selected_security_groups', 'security_group_names');
    if ($opts !== null) return $opts;

    try {
        $api = new CloudPeCmpAPI($params);
        $projectId = trim($params['serveraccesshash'] ?? '');
        if (empty($projectId)) {
            return ['error' => 'Project ID not configured (set Access Hash)'];
        }
        $result = $api->listSecurityGroups($projectId);
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

/**
 * Region dropdown loader for Module Settings.
 *
 * Prefers the admin addon's curated `selected_regions` (Regions tab) so the
 * product dropdown matches what the cart will actually expose. Falls back to
 * a live `/regions` lookup when the admin hasn't picked any yet.
 */
function cloudpe_cmp_RegionLoader(array $params): array
{
    $serverId = (int)($params['serverid'] ?? 0);
    $selected = (array)cloudpe_cmp_getAdminSetting($serverId, 'selected_regions');
    $selected = array_values(array_filter(array_map('strval', $selected)));

    // region_names is a flat {rId: label} map. cloudpe_cmp_getAdminSetting
    // mis-detects it as region-nested (UUID keys) and flattens to []; read
    // the row directly to get the proper map.
    $names = [];
    try {
        $row = Capsule::table('mod_cloudpe_cmp_settings')
            ->where('server_id', $serverId)
            ->where('setting_key', 'region_names')
            ->first();
        if ($row) {
            $decoded = json_decode($row->setting_value, true);
            if (is_array($decoded)) $names = $decoded;
        }
    } catch (Exception $e) {}

    if (!empty($selected)) {
        $options = [];
        foreach ($selected as $rId) {
            $options[$rId] = $names[$rId] ?? $rId;
        }
        return $options;
    }

    try {
        $api = new CloudPeCmpAPI($params);
        $result = $api->listRegions();
        if (!empty($result['success']) && !empty($result['regions'])) {
            $options = [];
            foreach ($result['regions'] as $r) {
                $id = $r['id'] ?? '';
                if (!$id) continue;
                $display = $r['display_name'] ?? '';
                $name    = $r['name'] ?? '';
                if ($display !== '' && $name !== '' && $display !== $name) {
                    $options[$id] = $display . ' (' . $name . ')';
                } else {
                    $options[$id] = $display ?: ($name ?: $id);
                }
            }
            return $options;
        }
    } catch (Exception $e) {}
    return ['error' => 'Failed to load regions'];
}

function cloudpe_cmp_VolumeTypeLoader(array $params): array
{
    $opts = cloudpe_cmp_buildRegionScopedOptions($params, 'selected_volume_types', 'volume_type_names');
    if ($opts !== null) return $opts;

    try {
        $api = new CloudPeCmpAPI($params);
        $result = $api->listVolumeTypes();
        if ($result['success']) {
            $options = [];
            foreach ($result['volume_types'] as $vt) {
                $id   = $vt['id'] ?? $vt['name'] ?? '';
                $name = $vt['name'] ?? $id;
                if (!$id) continue;
                $options[$id] = $name;
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
    $opts = cloudpe_cmp_buildRegionScopedOptions($params, 'selected_projects', 'project_names', 'project_regions');
    if ($opts !== null) return $opts;

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

function cloudpe_cmp_NetworkLoader(array $params): array
{
    try {
        $api = new CloudPeCmpAPI($params);
        $result = $api->listNetworks();
        if ($result['success']) {
            $options = ['' => '(project default)'];
            foreach ($result['networks'] as $net) {
                $id = $net['id'] ?? '';
                if (!$id) continue;
                $options[$id] = $net['name'] ?? $net['display_name'] ?? $id;
            }
            return $options;
        }
    } catch (Exception $e) {}
    return ['' => '(project default)'];
}

/**
 * Disk size dropdown loader. Sources sizes from the admin module's
 * saved Disk Sizes tab so the product config uses the same curated
 * list. Falls back to sensible defaults.
 */
function cloudpe_cmp_DiskSizeLoader(array $params): array
{
    // Disks are server-wide. Legacy region-nested data is flattened by
    // taking the union (deduped on size_gb).
    $serverId = (int)($params['serverid'] ?? 0);
    $raw = cloudpe_cmp_getAdminSettingRaw($serverId, 'disk_sizes');
    $options = [];

    $disks = [];
    if (cloudpe_cmp_isRegionNested($raw)) {
        foreach ($raw as $slice) {
            if (!is_array($slice)) continue;
            foreach ($slice as $d) $disks[] = $d;
        }
    } elseif (is_array($raw)) {
        $disks = $raw;
    }

    foreach ($disks as $disk) {
        $size = (int)($disk['size_gb'] ?? $disk['size'] ?? 0);
        if (!$size || isset($options[(string)$size])) continue;
        $options[(string)$size] = $disk['label'] ?? ($size . ' GB');
    }

    if (!empty($options)) return $options;
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
// Provisioning Helpers
// =========================================================================

/**
 * Return $value only if it looks like a UUID; otherwise return ''.
 * Guards against WHMCS loader-error placeholders (e.g. "error") or
 * mis-mapped config option values being passed to the API as IDs.
 */
function cloudpe_cmp_sanitizeUuid(string $value): string
{
    return preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $value)
        ? $value : '';
}

/**
 * Return $value only if it is a non-empty, non-numeric string.
 * Prevents a stray disk-size number from being sent as volume_type.
 */
function cloudpe_cmp_sanitizeVolumeType(string $value): string
{
    return ($value !== '' && !is_numeric($value)) ? $value : '';
}

/**
 * Resolve a CMP internal flavor/image UUID to the openstack_id that the
 * /instances endpoint expects. If the API does not surface a separate
 * openstack_id for the matched item, fall back to the originally-stored id
 * so installs whose CMP backend already returns an openstack-style id keep
 * working. Returns '' only when no match at all is found in the region.
 */
function cloudpe_cmp_resolveOpenstackId($api, string $kind, string $id, string $regionId): string
{
    if ($id === '') return '';

    $matcher = function (array $items, string $needle): ?string {
        foreach ($items as $it) {
            if (!is_array($it)) continue;
            foreach (['id', 'uuid', 'openstack_id', 'slug'] as $k) {
                if (isset($it[$k]) && (string)$it[$k] === $needle) {
                    $os = $it['openstack_id'] ?? '';
                    return is_string($os) && $os !== '' ? $os : $needle;
                }
            }
        }
        return null;
    };

    // Try, in order:
    //   1. Region-scoped, grouped (matches admin/dashboard view).
    //   2. Region-scoped, ungrouped (covers ungrouped/legacy images).
    //   3. Global, ungrouped (last-resort: admin saved an image not currently
    //      surfaced for the chosen region — we still want to send the right
    //      openstack_id so CMP can reply with a precise error).
    $attempts = $kind === 'flavors'
        ? [
            ['list' => fn() => $api->listFlavors(true, $regionId), 'key' => 'flavors', 'tag' => 'flavors region'],
            ['list' => fn() => $api->listFlavors(true, ''),         'key' => 'flavors', 'tag' => 'flavors global'],
          ]
        : [
            ['list' => fn() => $api->listImages('', $regionId, true),  'key' => 'images', 'tag' => 'images region grouped'],
            ['list' => fn() => $api->listImages('', $regionId, false), 'key' => 'images', 'tag' => 'images region ungrouped'],
            ['list' => fn() => $api->listImages('', '',        false), 'key' => 'images', 'tag' => 'images global ungrouped'],
          ];

    $attemptLog = [];
    foreach ($attempts as $a) {
        try {
            $res = ($a['list'])();
            $items = is_array($res[$a['key']] ?? null) ? $res[$a['key']] : [];
            $attemptLog[$a['tag']] = ['success' => $res['success'] ?? false, 'count' => count($items)];
            if (empty($res['success'])) continue;
            $hit = $matcher($items, $id);
            if ($hit !== null) return $hit;
        } catch (Exception $e) {
            $attemptLog[$a['tag']] = ['exception' => $e->getMessage()];
        }
    }

    logModuleCall('cloudpe_cmp', 'resolveOpenstackId', [
        'kind' => $kind, 'id' => $id, 'region' => $regionId,
    ], $attemptLog, 'No match in any fallback listing');
    return '';
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

        // Module Settings positional map (cloudpe_cmp_ConfigOptions):
        //   1 = region, 2 = flavor, 3 = default_disk_size, 4 = image.
        $defaultRegionId  = cloudpe_cmp_sanitizeUuid(trim($params['configoption1'] ?? ''));
        $defaultFlavorId  = trim($params['configoption2'] ?? '');
        $defaultDiskSize  = (int)($params['configoption3'] ?? 0);
        $defaultImageId   = trim($params['configoption4'] ?? '');

        $serverId = (int)($params['serverid'] ?? 0);

        // Region — cart "Region" Configurable Option overrides product's
        // Default Region (configoption4).
        $regionId = cloudpe_cmp_sanitizeUuid(trim((string)(
            $params['configoptions']['Region'] ?? ''
        )));
        if ($regionId === '') $regionId = $defaultRegionId;

        $volumeType = '';

        // Flavor / Image: cart override > Module Settings default.
        $flavorId = trim(
            $params['configoptions']['Server Size'] ??
            $params['configoptions']['Flavor'] ??
            $params['configoptions']['Plan'] ??
            $defaultFlavorId
        );
        $imageId = trim(
            $params['configoptions']['Operating System'] ??
            $params['configoptions']['Image'] ??
            $params['configoptions']['OS'] ??
            $defaultImageId
        );

        // Disk size: cart override > Module Settings default.
        $volumeSize = (int)(
            $params['configoptions']['Disk Space']
            ?? $params['configoptions']['Volume Size']
            ?? $defaultDiskSize
        );
        if ($volumeSize < 30) $volumeSize = 30;

        // Project: admin-addon `selected_projects[regionId][0]` is canonical;
        // server Access Hash is the legacy fallback for single-region installs.
        $projectId = '';
        if ($regionId !== '') {
            $regionProjects = cloudpe_cmp_getAdminSetting($serverId, 'selected_projects', $regionId);
            if (is_array($regionProjects) && !empty($regionProjects)) {
                $projectId = cloudpe_cmp_sanitizeUuid(trim((string)reset($regionProjects)));
            }
        }
        if ($projectId === '') {
            $projectId = cloudpe_cmp_sanitizeUuid(trim($params['serveraccesshash'] ?? ''));
        }

        // Volume type: per-region selection from the admin addon
        // (Volume Types tab → selected_volume_types[regionId][0]).
        if ($regionId !== '') {
            $regionVts = cloudpe_cmp_getAdminSetting($serverId, 'selected_volume_types', $regionId);
            if (is_array($regionVts) && !empty($regionVts)) {
                $volumeType = cloudpe_cmp_sanitizeVolumeType((string)reset($regionVts));
            }
        }

        if (empty($regionId))   return 'Configuration Error: No region specified (cart "Region" Configurable Option or product Default Region).';
        if (empty($flavorId))   return 'Configuration Error: No server size specified.';
        if (empty($imageId))    return 'Configuration Error: No OS image specified.';
        if (empty($projectId))  return 'Configuration Error: No project ID for region (configure in admin addon Projects tab, or set server Access Hash).';
        if ($volumeType === '') return 'Configuration Error: No volume type configured for this region (set it in the admin addon Volume Types tab).';

        // CMP /instances expects the openstack_id (not the CMP UUID) for
        // both flavor and image. Resolve via the region-scoped listing.
        $flavorOsId = cloudpe_cmp_resolveOpenstackId($api, 'flavors', $flavorId, $regionId);
        if ($flavorOsId === '') {
            return "Configuration Error: Flavor '{$flavorId}' is not currently available in the selected region. Re-load Flavors in the admin addon and update the configurable option.";
        }
        $imageOsId  = cloudpe_cmp_resolveOpenstackId($api, 'images',  $imageId,  $regionId);
        if ($imageOsId === '') {
            return "Configuration Error: Image '{$imageId}' is not currently available in the selected region. Re-load Images in the admin addon and update the configurable option.";
        }
        $flavorId = $flavorOsId;
        $imageId  = $imageOsId;

        // Hostname and password — WHMCS auto-generates both when the cart
        // does not collect them. Reuse $params['domain'] (hostname field
        // on server orders) and $params['password'] (auto-generated).
        $hostname = trim($params['domain'] ?? '');
        if ($hostname === '') $hostname = $helper->generateHostname($params);
        $password = trim($params['password'] ?? '');
        if ($password === '' || strlen($password) < 8) {
            $password = $helper->generatePassword();
        }

        // Payload shape per CMP /api/v1/instances:
        //   { name, password, region_id, project_id, flavor_id, image_id,
        //     volume:{size_gb, volume_type}, billing_cycle }
        $instanceData = [
            'name'          => $hostname,
            'password'      => $password,
            'region_id'     => $regionId,
            'project_id'    => $projectId,
            'flavor_id'     => $flavorId,
            'image_id'      => $imageId,
            'volume'        => [
                'size_gb'     => $volumeSize,
                'volume_type' => $volumeType,
            ],
            'billing_cycle' => 'monthly',
        ];

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

        // Get new values from configurable options or product settings.
        // Positional map: 1=region, 2=flavor, 3=default_disk_size, 4=image.
        $defaultFlavorId = trim($params['configoption2'] ?? '');
        $newFlavorId = trim(
            $params['configoptions']['Server Size'] ??
            $params['configoptions']['Flavor'] ??
            $params['configoptions']['Plan'] ??
            $defaultFlavorId
        );

        $minVolumeSize = 30;
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
            $volumesResult = $api->listVolumes($projectId, $instanceId);

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
        $status   = strtoupper($instance['status'] ?? 'Unknown');

        // IPs: CMP /instances/{id} returns a single 'ip_address' string for IPv4.
        // Fall back to older shapes (ip_addresses / addresses) for compatibility.
        $ipv4 = $instance['ip_address'] ?? '';
        $ipv6 = '';
        if ($ipv4 === '' || isset($instance['ip_addresses']) || isset($instance['addresses'])) {
            $legacy = $helper->extractIPs($instance['ip_addresses'] ?? $instance['addresses'] ?? []);
            if ($ipv4 === '') { $ipv4 = $legacy['ipv4']; }
            $ipv6 = $legacy['ipv6'];
        }

        // Flavor details — CMP returns flavor_name/vcpus/ram_mb as top-level
        // fields (the nested 'flavor' key is now just a UUID string).
        $flavorName  = $instance['flavor_name']
                    ?? (is_array($instance['flavor'] ?? null) ? ($instance['flavor']['name'] ?? 'Unknown') : 'Unknown');
        $flavorVcpus = $instance['vcpus']
                    ?? (is_array($instance['flavor'] ?? null) ? ($instance['flavor']['vcpu'] ?? $instance['flavor']['vcpus'] ?? '-') : '-');
        $flavorRam   = '-';
        if (isset($instance['ram_mb']) && (float)$instance['ram_mb'] > 0) {
            $flavorRam = round((float)$instance['ram_mb'] / 1024, 1);
        } elseif (is_array($instance['flavor'] ?? null)) {
            $flavorRam = $instance['flavor']['memory_gb']
                      ?? (isset($instance['flavor']['ram']) ? round((float)$instance['flavor']['ram'] / 1024, 1) : '-');
        }

        // Image — CMP returns image_name as top-level field.
        $imageName = $instance['image_name']
                  ?? (is_array($instance['image'] ?? null) ? ($instance['image']['name'] ?? 'Unknown')
                      : (is_string($instance['image'] ?? null) ? $instance['image'] : 'Unknown'));

        // Disk size — CMP returns disk_gb; keep older boot_volume_size_gb as fallback.
        $diskSize = $instance['disk_gb'] ?? $instance['boot_volume_size_gb'] ?? '-';

        // Keep WHMCS's Primary IP and Assigned IPs in sync on every view.
        cloudpe_cmp_syncIPs($params, $instance, $helper);

        $systemUrl = rtrim($GLOBALS['CONFIG']['SystemURL'] ?? '', '/');

        return [
            'templatefile' => 'templates/overview',
            'vars' => [
                'serviceid' => $params['serviceid'],
                'server_id' => $instanceId,
                'status' => $status,
                'status_label' => $helper->getStatusLabel($status),
                'hostname' => $instance['name'] ?? '',
                'ipv4' => $ipv4,
                'ipv6' => $ipv6,
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

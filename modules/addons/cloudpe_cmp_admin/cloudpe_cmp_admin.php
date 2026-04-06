<?php
/**
 * CloudPe CMP Admin Addon Module
 *
 * Provides admin interface for managing CloudPe CMP resources:
 * images, flavors, disk sizes, configurable options groups,
 * and module auto-updates.
 *
 * @author CloudPe
 * @version 1.0.0
 */

if (!defined("WHMCS")) {
  die("This file cannot be accessed directly");
}

define('CLOUDPE_CMP_MODULE_VERSION', '1.0.0');
define('CLOUDPE_CMP_UPDATE_URL', 'https://raw.githubusercontent.com/Leapswitch-Networks/cloudpe-cmp-whmcs/main/version.json');
define('CLOUDPE_CMP_RELEASES_URL', 'https://api.github.com/repos/Leapswitch-Networks/cloudpe-cmp-whmcs/releases');

use WHMCS\Database\Capsule;

// ---------------------------------------------------------------------------
// Module metadata & lifecycle
// ---------------------------------------------------------------------------

/**
 * Module configuration metadata.
 */
function cloudpe_cmp_admin_config(): array
{
  return [
    'name'        => 'CloudPe CMP Admin',
    'description' => 'Admin interface for managing CloudPe CMP resources, configurable options, and module updates.',
    'version'     => CLOUDPE_CMP_MODULE_VERSION,
    'author'      => 'CloudPe',
    'language'    => 'english',
  ];
}

/**
 * Create required database tables on activation.
 */
function cloudpe_cmp_admin_activate(): array
{
  try {
    Capsule::schema()->create('mod_cloudpe_cmp_settings', function ($table) {
      $table->increments('id');
      $table->unsignedInteger('server_id');
      $table->string('setting_key', 128);
      $table->longText('setting_value')->nullable();
      $table->timestamps();
      $table->unique(['server_id', 'setting_key']);
    });

    return ['status' => 'success', 'description' => 'CloudPe CMP Admin module activated successfully.'];
  } catch (\Exception $e) {
    // Table may already exist – treat as non-fatal
    if (strpos($e->getMessage(), 'already exists') !== false) {
      return ['status' => 'success', 'description' => 'CloudPe CMP Admin module activated (table already existed).'];
    }
    return ['status' => 'error', 'description' => 'Activation failed: ' . $e->getMessage()];
  }
}

/**
 * Module deactivation – leave data intact.
 */
function cloudpe_cmp_admin_deactivate(): array
{
  return ['status' => 'success', 'description' => 'CloudPe CMP Admin module deactivated.'];
}

// ---------------------------------------------------------------------------
// Update helpers
// ---------------------------------------------------------------------------

/**
 * Fetch all GitHub releases for the module.
 *
 * @return array List of release objects from GitHub API
 */
function cloudpe_cmp_admin_get_all_releases(): array
{
  $ch = curl_init(CLOUDPE_CMP_RELEASES_URL);
  curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => 15,
    CURLOPT_USERAGENT      => 'CloudPe-CMP-WHMCS/' . CLOUDPE_CMP_MODULE_VERSION,
    CURLOPT_HTTPHEADER     => ['Accept: application/vnd.github.v3+json'],
  ]);

  $body     = curl_exec($ch);
  $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
  $error    = curl_error($ch);
  curl_close($ch);

  if ($error || $httpCode !== 200) {
    return [];
  }

  $releases = json_decode($body, true);
  return is_array($releases) ? $releases : [];
}

/**
 * Check whether a newer version is available.
 *
 * @param string $updateUrl URL to the version.json manifest
 * @return array  Keys: current, latest, download_url, update_available, changelog
 */
function cloudpe_cmp_admin_check_update(string $updateUrl): array
{
  $ch = curl_init($updateUrl);
  curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => 15,
    CURLOPT_USERAGENT      => 'CloudPe-CMP-WHMCS/' . CLOUDPE_CMP_MODULE_VERSION,
  ]);

  $body     = curl_exec($ch);
  $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
  $error    = curl_error($ch);
  curl_close($ch);

  if ($error || $httpCode !== 200) {
    return [
      'current'          => CLOUDPE_CMP_MODULE_VERSION,
      'latest'           => null,
      'update_available' => false,
      'error'            => $error ?: "HTTP $httpCode",
    ];
  }

  $data = json_decode($body, true);
  if (!is_array($data) || empty($data['version'])) {
    return [
      'current'          => CLOUDPE_CMP_MODULE_VERSION,
      'latest'           => null,
      'update_available' => false,
      'error'            => 'Invalid version manifest',
    ];
  }

  return [
    'current'          => CLOUDPE_CMP_MODULE_VERSION,
    'latest'           => $data['version'],
    'download_url'     => $data['download_url'] ?? '',
    'update_available' => version_compare($data['version'], CLOUDPE_CMP_MODULE_VERSION, '>'),
    'changelog'        => $data['changelog'] ?? [],
  ];
}

/**
 * Download a release ZIP and install both module directories.
 *
 * @param string $downloadUrl Direct ZIP download URL
 * @return array  Keys: success (bool), message (string)
 */
function cloudpe_cmp_admin_install_update(string $downloadUrl): array
{
  $tmpFile = tempnam(sys_get_temp_dir(), 'cloudpe_cmp_update_') . '.zip';
  $tmpDir  = sys_get_temp_dir() . '/cloudpe_cmp_update_' . time();

  try {
    // Download ZIP
    $ch = curl_init($downloadUrl);
    $fp = fopen($tmpFile, 'wb');
    curl_setopt_array($ch, [
      CURLOPT_FILE           => $fp,
      CURLOPT_TIMEOUT        => 120,
      CURLOPT_FOLLOWLOCATION => true,
      CURLOPT_USERAGENT      => 'CloudPe-CMP-WHMCS/' . CLOUDPE_CMP_MODULE_VERSION,
    ]);
    curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error    = curl_error($ch);
    curl_close($ch);
    fclose($fp);

    if ($error || $httpCode !== 200) {
      throw new \Exception('Download failed: ' . ($error ?: "HTTP $httpCode"));
    }

    // Extract ZIP
    $zip = new ZipArchive();
    if ($zip->open($tmpFile) !== true) {
      throw new \Exception('Failed to open ZIP archive.');
    }
    mkdir($tmpDir, 0755, true);
    $zip->extractTo($tmpDir);
    $zip->close();

    // Locate the modules directory inside the extracted ZIP
    $modulesRoot = null;
    $iterator    = new RecursiveIteratorIterator(
      new RecursiveDirectoryIterator($tmpDir, RecursiveDirectoryIterator::SKIP_DOTS),
      RecursiveIteratorIterator::SELF_FIRST
    );
    foreach ($iterator as $item) {
      if ($item->isDir() && basename($item->getPathname()) === 'modules') {
        $modulesRoot = $item->getPathname();
        break;
      }
    }

    if (!$modulesRoot) {
      throw new \Exception('Could not locate modules/ directory in the archive.');
    }

    $whmcsRoot = dirname(dirname(dirname(dirname(__DIR__))));

    // Copy server module
    $srcServer = $modulesRoot . '/servers/cloudpe_cmp';
    $dstServer = $whmcsRoot . '/modules/servers/cloudpe_cmp';
    if (is_dir($srcServer)) {
      cloudpe_cmp_admin_copy_directory($srcServer, $dstServer);
    }

    // Copy addon module
    $srcAddon = $modulesRoot . '/addons/cloudpe_cmp_admin';
    $dstAddon = $whmcsRoot . '/modules/addons/cloudpe_cmp_admin';
    if (is_dir($srcAddon)) {
      cloudpe_cmp_admin_copy_directory($srcAddon, $dstAddon);
    }

    return ['success' => true, 'message' => 'Module updated successfully. Please refresh the page.'];
  } catch (\Exception $e) {
    return ['success' => false, 'message' => $e->getMessage()];
  } finally {
    if (file_exists($tmpFile)) {
      @unlink($tmpFile);
    }
    cloudpe_cmp_admin_cleanup_temp($tmpDir);
  }
}

/**
 * Recursively copy a directory.
 *
 * @param string $src Source directory path
 * @param string $dst Destination directory path
 */
function cloudpe_cmp_admin_copy_directory(string $src, string $dst): void
{
  if (!is_dir($dst)) {
    mkdir($dst, 0755, true);
  }

  $items = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($src, RecursiveDirectoryIterator::SKIP_DOTS),
    RecursiveIteratorIterator::SELF_FIRST
  );

  foreach ($items as $item) {
    $target = $dst . DIRECTORY_SEPARATOR . $items->getSubPathName();
    if ($item->isDir()) {
      if (!is_dir($target)) {
        mkdir($target, 0755, true);
      }
    } else {
      copy($item->getPathname(), $target);
    }
  }
}

/**
 * Recursively remove a temporary directory.
 *
 * @param string $dir Directory path to remove
 */
function cloudpe_cmp_admin_cleanup_temp(string $dir): void
{
  if (!is_dir($dir)) {
    return;
  }

  $items = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS),
    RecursiveIteratorIterator::CHILD_FIRST
  );

  foreach ($items as $item) {
    if ($item->isDir()) {
      @rmdir($item->getPathname());
    } else {
      @unlink($item->getPathname());
    }
  }

  @rmdir($dir);
}

// ---------------------------------------------------------------------------
// Database helpers
// ---------------------------------------------------------------------------

/**
 * Persist a setting value for a server.
 *
 * @param int    $serverId WHMCS server ID
 * @param string $key      Setting key
 * @param mixed  $value    Value (arrays will be JSON-encoded)
 */
function cloudpe_cmp_admin_save_setting(int $serverId, string $key, $value): void
{
  if (is_array($value) || is_object($value)) {
    $value = json_encode($value);
  }

  Capsule::table('mod_cloudpe_cmp_settings')->updateOrInsert(
    ['server_id' => $serverId, 'setting_key' => $key],
    ['setting_value' => $value, 'updated_at' => date('Y-m-d H:i:s')]
  );
}

/**
 * Retrieve a setting value for a server.
 *
 * @param int    $serverId WHMCS server ID
 * @param string $key      Setting key
 * @param mixed  $default  Default if not found
 * @return mixed Raw string or decoded JSON
 */
function cloudpe_cmp_admin_get_setting(int $serverId, string $key, $default = null)
{
  $row = Capsule::table('mod_cloudpe_cmp_settings')
    ->where('server_id', $serverId)
    ->where('setting_key', $key)
    ->first();

  if (!$row) {
    return $default;
  }

  $decoded = json_decode($row->setting_value, true);
  return (json_last_error() === JSON_ERROR_NONE) ? $decoded : $row->setting_value;
}

// ---------------------------------------------------------------------------
// CMP API wrappers
// ---------------------------------------------------------------------------

/**
 * Load available images from the CMP API for a given server.
 *
 * @param int $serverId WHMCS server ID
 * @return array  Keys: success (bool), images (array)|error (string)
 */
function cloudpe_cmp_admin_load_images(int $serverId): array
{
  $apiLibPath = dirname(dirname(__DIR__)) . '/servers/cloudpe_cmp/lib/CloudPeCmpAPI.php';
  if (!file_exists($apiLibPath)) {
    return ['success' => false, 'error' => 'CloudPeCmpAPI library not found.'];
  }
  require_once $apiLibPath;

  $server = Capsule::table('tblservers')->where('id', $serverId)->first();
  if (!$server) {
    return ['success' => false, 'error' => 'Server not found.'];
  }

  $params = [
    'serverhostname'   => $server->hostname,
    'serverpassword'   => decrypt($server->password),
    'serveraccesshash' => $server->accesshash,
    'serversecure'     => $server->secure,
  ];

  try {
    $api    = new CloudPeCmpAPI($params);
    $result = $api->listImages();

    if (!$result['success']) {
      return ['success' => false, 'error' => $result['error'] ?? 'Failed to load images.'];
    }

    $images = [];
    foreach ((array)($result['images'] ?? []) as $img) {
      $images[] = [
        'id'   => $img['id'] ?? $img['slug'] ?? '',
        'name' => $img['name'] ?? $img['display_name'] ?? $img['id'] ?? '',
      ];
    }

    return ['success' => true, 'images' => $images];
  } catch (\Exception $e) {
    return ['success' => false, 'error' => $e->getMessage()];
  }
}

/**
 * Load available flavors from the CMP API for a given server.
 *
 * @param int $serverId WHMCS server ID
 * @return array  Keys: success (bool), flavors (array)|error (string)
 */
function cloudpe_cmp_admin_load_flavors(int $serverId): array
{
  $apiLibPath = dirname(dirname(__DIR__)) . '/servers/cloudpe_cmp/lib/CloudPeCmpAPI.php';
  if (!file_exists($apiLibPath)) {
    return ['success' => false, 'error' => 'CloudPeCmpAPI library not found.'];
  }
  require_once $apiLibPath;

  $server = Capsule::table('tblservers')->where('id', $serverId)->first();
  if (!$server) {
    return ['success' => false, 'error' => 'Server not found.'];
  }

  $params = [
    'serverhostname'   => $server->hostname,
    'serverpassword'   => decrypt($server->password),
    'serveraccesshash' => $server->accesshash,
    'serversecure'     => $server->secure,
  ];

  try {
    $api    = new CloudPeCmpAPI($params);
    $result = $api->listFlavors();

    if (!$result['success']) {
      return ['success' => false, 'error' => $result['error'] ?? 'Failed to load flavors.'];
    }

    $flavors = [];
    foreach ((array)($result['flavors'] ?? []) as $flv) {
      $flavors[] = [
        'id'        => $flv['id'] ?? $flv['slug'] ?? '',
        'name'      => $flv['name'] ?? $flv['display_name'] ?? $flv['id'] ?? '',
        'vcpu'      => $flv['vcpus'] ?? $flv['vcpu'] ?? $flv['cpu'] ?? 0,
        'memory_gb' => isset($flv['ram'])
          ? round($flv['ram'] / 1024, 1)
          : ($flv['memory_gb'] ?? $flv['memory'] ?? 0),
      ];
    }

    return ['success' => true, 'flavors' => $flavors];
  } catch (\Exception $e) {
    return ['success' => false, 'error' => $e->getMessage()];
  }
}

// ---------------------------------------------------------------------------
// Configurable options group creator
// ---------------------------------------------------------------------------

/**
 * Create a WHMCS configurable options group populated from saved settings.
 *
 * Creates three options: Operating System, Server Size, Disk Space.
 *
 * @param array $params  Keys: server_id (int), group_name (string)
 * @return array  Keys: success (bool), message (string), group_id (int)
 */
function cloudpe_cmp_admin_create_config_group(array $params): array
{
  $serverId  = (int)($params['server_id'] ?? 0);
  $groupName = trim($params['group_name'] ?? 'CloudPe CMP Options');

  if (!$serverId) {
    return ['success' => false, 'message' => 'No server selected.'];
  }

  // Load saved images
  $savedImages  = cloudpe_cmp_admin_get_setting($serverId, 'selected_images', []);
  $imageNames   = cloudpe_cmp_admin_get_setting($serverId, 'image_names', []);
  $imagePrices  = cloudpe_cmp_admin_get_setting($serverId, 'image_prices', []);

  // Load saved flavors
  $savedFlavors = cloudpe_cmp_admin_get_setting($serverId, 'selected_flavors', []);
  $flavorNames  = cloudpe_cmp_admin_get_setting($serverId, 'flavor_names', []);
  $flavorPrices = cloudpe_cmp_admin_get_setting($serverId, 'flavor_prices', []);

  // Load saved disk sizes
  $savedDisks   = cloudpe_cmp_admin_get_setting($serverId, 'disk_sizes', []);

  if (empty($savedImages) && empty($savedFlavors) && empty($savedDisks)) {
    return ['success' => false, 'message' => 'No resources configured. Please configure images, flavors, and disk sizes first.'];
  }

  try {
    // Create the configurable options group
    $groupId = Capsule::table('tblconfigoptionsgroups')->insertGetId([
      'name' => $groupName,
    ]);

    $sortOrder = 0;

    // --- Operating System ---
    if (!empty($savedImages)) {
      $osOptionId = Capsule::table('tblconfigoptions')->insertGetId([
        'gid'         => $groupId,
        'optionname'  => 'Operating System',
        'optiontype'  => 1, // dropdown
        'sortorder'   => $sortOrder++,
        'hidden'      => 0,
      ]);

      $osSubOrder = 0;
      foreach ($savedImages as $imageId) {
        $displayName = $imageNames[$imageId] ?? $imageId;
        $price       = (float)($imagePrices[$imageId] ?? 0);

        $subId = Capsule::table('tblconfigoptionssub')->insertGetId([
          'configid'  => $osOptionId,
          'optionname'=> $displayName . '|' . $imageId,
          'sortorder' => $osSubOrder++,
          'hidden'    => 0,
        ]);

        // Insert pricing (all currencies at once via tblpricing)
        $currencies = Capsule::table('tblcurrencies')->get();
        foreach ($currencies as $currency) {
          Capsule::table('tblpricing')->insert([
            'type'      => 'configoptions',
            'currency'  => $currency->id,
            'relid'     => $subId,
            'msetupfee' => 0,
            'qsetupfee' => 0,
            'ssetupfee' => 0,
            'asetupfee' => 0,
            'bsetupfee' => 0,
            'tsetupfee' => 0,
            'monthly'   => $price,
            'quarterly' => 0,
            'semiannually' => 0,
            'annually'  => 0,
            'biennially'=> 0,
            'triennially' => 0,
          ]);
        }
      }
    }

    // --- Server Size ---
    if (!empty($savedFlavors)) {
      $sizeOptionId = Capsule::table('tblconfigoptions')->insertGetId([
        'gid'        => $groupId,
        'optionname' => 'Server Size',
        'optiontype' => 1, // dropdown
        'sortorder'  => $sortOrder++,
        'hidden'     => 0,
      ]);

      $sizeSubOrder = 0;
      foreach ($savedFlavors as $flavorId) {
        $displayName = $flavorNames[$flavorId] ?? $flavorId;
        $price       = (float)($flavorPrices[$flavorId] ?? 0);

        $subId = Capsule::table('tblconfigoptionssub')->insertGetId([
          'configid'  => $sizeOptionId,
          'optionname'=> $displayName . '|' . $flavorId,
          'sortorder' => $sizeSubOrder++,
          'hidden'    => 0,
        ]);

        $currencies = Capsule::table('tblcurrencies')->get();
        foreach ($currencies as $currency) {
          Capsule::table('tblpricing')->insert([
            'type'         => 'configoptions',
            'currency'     => $currency->id,
            'relid'        => $subId,
            'msetupfee'    => 0,
            'qsetupfee'    => 0,
            'ssetupfee'    => 0,
            'asetupfee'    => 0,
            'bsetupfee'    => 0,
            'tsetupfee'    => 0,
            'monthly'      => $price,
            'quarterly'    => 0,
            'semiannually' => 0,
            'annually'     => 0,
            'biennially'   => 0,
            'triennially'  => 0,
          ]);
        }
      }
    }

    // --- Disk Space ---
    if (!empty($savedDisks)) {
      $diskOptionId = Capsule::table('tblconfigoptions')->insertGetId([
        'gid'        => $groupId,
        'optionname' => 'Disk Space',
        'optiontype' => 1, // dropdown
        'sortorder'  => $sortOrder++,
        'hidden'     => 0,
      ]);

      $diskSubOrder = 0;
      foreach ($savedDisks as $disk) {
        $diskSizeGb  = (int)($disk['size_gb'] ?? 0);
        $diskLabel   = $disk['label'] ?? ($diskSizeGb . ' GB');
        $diskPrice   = (float)($disk['price'] ?? 0);

        if (!$diskSizeGb) {
          continue;
        }

        $subId = Capsule::table('tblconfigoptionssub')->insertGetId([
          'configid'  => $diskOptionId,
          'optionname'=> $diskLabel . '|' . $diskSizeGb,
          'sortorder' => $diskSubOrder++,
          'hidden'    => 0,
        ]);

        $currencies = Capsule::table('tblcurrencies')->get();
        foreach ($currencies as $currency) {
          Capsule::table('tblpricing')->insert([
            'type'         => 'configoptions',
            'currency'     => $currency->id,
            'relid'        => $subId,
            'msetupfee'    => 0,
            'qsetupfee'    => 0,
            'ssetupfee'    => 0,
            'asetupfee'    => 0,
            'bsetupfee'    => 0,
            'tsetupfee'    => 0,
            'monthly'      => $diskPrice,
            'quarterly'    => 0,
            'semiannually' => 0,
            'annually'     => 0,
            'biennially'   => 0,
            'triennially'  => 0,
          ]);
        }
      }
    }

    return [
      'success'  => true,
      'message'  => 'Configurable options group "' . $groupName . '" created successfully.',
      'group_id' => $groupId,
    ];
  } catch (\Exception $e) {
    return ['success' => false, 'message' => 'Failed to create config group: ' . $e->getMessage()];
  }
}

// ---------------------------------------------------------------------------
// Main output entry point
// ---------------------------------------------------------------------------

/**
 * Render the addon module admin page and handle AJAX actions.
 *
 * @param array $vars WHMCS template variables
 */
function cloudpe_cmp_admin_output(array $vars): void
{
  // -------------------------------------------------------------------
  // AJAX handler
  // -------------------------------------------------------------------
  $action = $_POST['action'] ?? $_GET['action'] ?? '';

  if ($action) {
    header('Content-Type: application/json');

    $serverId = (int)($_POST['server_id'] ?? $_GET['server_id'] ?? 0);

    switch ($action) {
      case 'check_update':
        echo json_encode(cloudpe_cmp_admin_check_update(CLOUDPE_CMP_UPDATE_URL));
        exit;

      case 'install_update':
        $downloadUrl = trim($_POST['download_url'] ?? '');
        if (!$downloadUrl) {
          echo json_encode(['success' => false, 'message' => 'No download URL provided.']);
        } else {
          echo json_encode(cloudpe_cmp_admin_install_update($downloadUrl));
        }
        exit;

      case 'get_releases':
        $releases = cloudpe_cmp_admin_get_all_releases();
        echo json_encode(['success' => true, 'releases' => $releases]);
        exit;

      case 'load_images':
        echo json_encode(cloudpe_cmp_admin_load_images($serverId));
        exit;

      case 'load_flavors':
        echo json_encode(cloudpe_cmp_admin_load_flavors($serverId));
        exit;

      case 'save_images':
        $selectedImages = $_POST['selected_images'] ?? [];
        cloudpe_cmp_admin_save_setting($serverId, 'selected_images', $selectedImages);
        echo json_encode(['success' => true, 'message' => 'Image selection saved.']);
        exit;

      case 'save_flavors':
        $selectedFlavors = $_POST['selected_flavors'] ?? [];
        cloudpe_cmp_admin_save_setting($serverId, 'selected_flavors', $selectedFlavors);
        echo json_encode(['success' => true, 'message' => 'Flavor selection saved.']);
        exit;

      case 'save_image_names':
        $names = $_POST['names'] ?? [];
        cloudpe_cmp_admin_save_setting($serverId, 'image_names', $names);
        echo json_encode(['success' => true, 'message' => 'Image display names saved.']);
        exit;

      case 'save_flavor_names':
        $names = $_POST['names'] ?? [];
        cloudpe_cmp_admin_save_setting($serverId, 'flavor_names', $names);
        echo json_encode(['success' => true, 'message' => 'Flavor display names saved.']);
        exit;

      case 'save_image_prices':
        $prices = $_POST['prices'] ?? [];
        cloudpe_cmp_admin_save_setting($serverId, 'image_prices', $prices);
        echo json_encode(['success' => true, 'message' => 'Image prices saved.']);
        exit;

      case 'save_flavor_prices':
        $prices = $_POST['prices'] ?? [];
        cloudpe_cmp_admin_save_setting($serverId, 'flavor_prices', $prices);
        echo json_encode(['success' => true, 'message' => 'Flavor prices saved.']);
        exit;

      case 'save_disks':
        $disks = $_POST['disks'] ?? [];
        cloudpe_cmp_admin_save_setting($serverId, 'disk_sizes', $disks);
        echo json_encode(['success' => true, 'message' => 'Disk sizes saved.']);
        exit;

      case 'create_config_group':
        $result = cloudpe_cmp_admin_create_config_group([
          'server_id'  => $serverId,
          'group_name' => trim($_POST['group_name'] ?? 'CloudPe CMP Options'),
        ]);
        echo json_encode($result);
        exit;

      default:
        echo json_encode(['success' => false, 'error' => 'Unknown action.']);
        exit;
    }
  }

  // -------------------------------------------------------------------
  // Fetch CloudPe CMP servers
  // -------------------------------------------------------------------
  $servers = Capsule::table('tblservers')
    ->where('type', 'cloudpe_cmp')
    ->orderBy('name')
    ->get();

  $activeTab = $_GET['tab'] ?? 'dashboard';
  $serverId  = (int)($_GET['server_id'] ?? ($servers->first()->id ?? 0));

  // -------------------------------------------------------------------
  // Page HTML
  // -------------------------------------------------------------------
  $moduleUrl = $vars['modulelink'];
  ?>
  <style>
    .cmp-tab-nav { margin-bottom: 20px; }
    .cmp-tab-nav .nav-tabs > li > a { cursor: pointer; }
    .cmp-section { margin-bottom: 30px; }
    .cmp-section h4 { border-bottom: 1px solid #eee; padding-bottom: 8px; margin-bottom: 15px; }
    .cmp-resource-table th { background: #f5f5f5; }
    .cmp-badge-new { background: #e74c3c; color: #fff; padding: 2px 8px; border-radius: 10px; font-size: 11px; }
    .cmp-release-item { border: 1px solid #e0e0e0; border-radius: 4px; margin-bottom: 10px; padding: 12px 15px; }
    .cmp-release-item h5 { margin: 0 0 5px; }
    .cmp-spinner { display: none; }
    .cmp-alert { display: none; margin-top: 10px; }
    #cmp-server-selector { margin-bottom: 20px; }
  </style>

  <div class="cmp-tab-nav">
    <h2>CloudPe CMP Admin <small style="font-size:13px; color:#999;">v<?php echo CLOUDPE_CMP_MODULE_VERSION; ?></small></h2>

    <?php if (empty((array)$servers)): ?>
      <div class="alert alert-warning">
        No CloudPe CMP servers found. Please add a server with type <strong>cloudpe_cmp</strong> in
        <a href="configservers.php">Setup &rarr; Servers</a>.
      </div>
    <?php else: ?>

    <!-- Server selector -->
    <div id="cmp-server-selector">
      <form method="get" style="display:inline-block;">
        <input type="hidden" name="module" value="cloudpe_cmp_admin">
        <input type="hidden" name="tab" value="<?php echo htmlspecialchars($activeTab); ?>">
        <label><strong>Server:</strong></label>
        <select name="server_id" class="form-control" style="display:inline-block; width:auto; margin-left:8px;"
                onchange="this.form.submit()">
          <?php foreach ($servers as $srv): ?>
            <option value="<?php echo (int)$srv->id; ?>"
              <?php echo ($srv->id == $serverId) ? 'selected' : ''; ?>>
              <?php echo htmlspecialchars($srv->name); ?> (<?php echo htmlspecialchars($srv->hostname); ?>)
            </option>
          <?php endforeach; ?>
        </select>
      </form>
    </div>

    <!-- Tab navigation -->
    <ul class="nav nav-tabs" role="tablist">
      <?php
      $tabs = [
        'dashboard'     => 'Dashboard',
        'images'        => 'Images',
        'flavors'       => 'Flavors',
        'disks'         => 'Disk Sizes',
        'create_group'  => 'Create Config Group',
        'updates'       => 'Updates',
      ];
      foreach ($tabs as $tabKey => $tabLabel):
        $href = $moduleUrl . '&tab=' . $tabKey . '&server_id=' . $serverId;
      ?>
        <li role="presentation" <?php echo ($activeTab === $tabKey) ? 'class="active"' : ''; ?>>
          <a href="<?php echo $href; ?>"><?php echo $tabLabel; ?></a>
        </li>
      <?php endforeach; ?>
    </ul>

    <!-- Tab content -->
    <div class="tab-content" style="padding-top: 20px;">
      <?php
      switch ($activeTab) {
        case 'images':
          cloudpe_cmp_admin_render_images($serverId, $moduleUrl);
          break;
        case 'flavors':
          cloudpe_cmp_admin_render_flavors($serverId, $moduleUrl);
          break;
        case 'disks':
          cloudpe_cmp_admin_render_disks($serverId, $moduleUrl);
          break;
        case 'create_group':
          cloudpe_cmp_admin_render_create_group($serverId, $moduleUrl);
          break;
        case 'updates':
          cloudpe_cmp_admin_render_updates($moduleUrl);
          break;
        default:
          cloudpe_cmp_admin_render_dashboard($serverId, $moduleUrl, $servers);
          break;
      }
      ?>
    </div>

    <?php endif; ?>
  </div>
  <?php
}

// ---------------------------------------------------------------------------
// Tab renderers
// ---------------------------------------------------------------------------

/**
 * Render the Dashboard tab.
 *
 * @param int    $serverId  Active server ID
 * @param string $moduleUrl Base module URL
 * @param object $servers   Collection of all CloudPe CMP servers
 */
function cloudpe_cmp_admin_render_dashboard(int $serverId, string $moduleUrl, $servers): void
{
  $server = Capsule::table('tblservers')->where('id', $serverId)->first();

  $savedImages  = cloudpe_cmp_admin_get_setting($serverId, 'selected_images', []);
  $savedFlavors = cloudpe_cmp_admin_get_setting($serverId, 'selected_flavors', []);
  $savedDisks   = cloudpe_cmp_admin_get_setting($serverId, 'disk_sizes', []);
  ?>
  <div class="cmp-section">
    <h4>Server Overview</h4>
    <?php if ($server): ?>
    <table class="table table-bordered" style="max-width:600px;">
      <tr><th style="width:160px;">Name</th><td><?php echo htmlspecialchars($server->name); ?></td></tr>
      <tr><th>Hostname</th><td><?php echo htmlspecialchars($server->hostname); ?></td></tr>
      <tr><th>SSL</th><td><?php echo $server->secure === 'on' ? '<span class="label label-success">Enabled</span>' : '<span class="label label-default">Disabled</span>'; ?></td></tr>
      <tr><th>Module Version</th><td><?php echo CLOUDPE_CMP_MODULE_VERSION; ?></td></tr>
    </table>
    <?php else: ?>
    <div class="alert alert-warning">Server not found.</div>
    <?php endif; ?>
  </div>

  <div class="cmp-section">
    <h4>Configuration Summary</h4>
    <div class="row">
      <div class="col-sm-4">
        <div class="panel panel-default">
          <div class="panel-body text-center">
            <h2 style="margin:0;"><?php echo count((array)$savedImages); ?></h2>
            <p style="color:#888;">Configured Images</p>
            <a href="<?php echo $moduleUrl; ?>&tab=images&server_id=<?php echo $serverId; ?>" class="btn btn-xs btn-default">Manage</a>
          </div>
        </div>
      </div>
      <div class="col-sm-4">
        <div class="panel panel-default">
          <div class="panel-body text-center">
            <h2 style="margin:0;"><?php echo count((array)$savedFlavors); ?></h2>
            <p style="color:#888;">Configured Flavors</p>
            <a href="<?php echo $moduleUrl; ?>&tab=flavors&server_id=<?php echo $serverId; ?>" class="btn btn-xs btn-default">Manage</a>
          </div>
        </div>
      </div>
      <div class="col-sm-4">
        <div class="panel panel-default">
          <div class="panel-body text-center">
            <h2 style="margin:0;"><?php echo count((array)$savedDisks); ?></h2>
            <p style="color:#888;">Disk Size Options</p>
            <a href="<?php echo $moduleUrl; ?>&tab=disks&server_id=<?php echo $serverId; ?>" class="btn btn-xs btn-default">Manage</a>
          </div>
        </div>
      </div>
    </div>
  </div>

  <div class="cmp-section">
    <h4>Quick Actions</h4>
    <a href="<?php echo $moduleUrl; ?>&tab=create_group&server_id=<?php echo $serverId; ?>" class="btn btn-primary">
      Create Configurable Options Group
    </a>
    &nbsp;
    <a href="<?php echo $moduleUrl; ?>&tab=updates&server_id=<?php echo $serverId; ?>" class="btn btn-default">
      Check for Updates
    </a>
  </div>
  <?php
}

/**
 * Render the Images tab.
 *
 * @param int    $serverId  Active server ID
 * @param string $moduleUrl Base module URL
 */
function cloudpe_cmp_admin_render_images(int $serverId, string $moduleUrl): void
{
  $savedImages = cloudpe_cmp_admin_get_setting($serverId, 'selected_images', []);
  $imageNames  = cloudpe_cmp_admin_get_setting($serverId, 'image_names', []);
  $imagePrices = cloudpe_cmp_admin_get_setting($serverId, 'image_prices', []);
  ?>
  <div class="cmp-section">
    <h4>Images
      <button class="btn btn-sm btn-primary pull-right" id="btn-load-images">
        <i class="fa fa-refresh"></i> Load from API
      </button>
    </h4>
    <div id="images-loading" class="cmp-spinner"><i class="fa fa-spinner fa-spin"></i> Loading images...</div>
    <div id="images-error" class="alert alert-danger cmp-alert"></div>
    <div id="images-container">
      <?php if (!empty($savedImages)): ?>
      <div class="alert alert-info" style="display:block;">
        Showing previously saved selection. Click <strong>Load from API</strong> to refresh.
      </div>
      <?php endif; ?>
    </div>

    <div id="images-saved-section" style="<?php echo empty($savedImages) ? 'display:none;' : ''; ?>">
      <h4>Selected Images Configuration</h4>
      <table class="table table-bordered cmp-resource-table" id="images-config-table">
        <thead>
          <tr>
            <th>Image ID</th>
            <th>Display Name</th>
            <th>Monthly Price (add-on)</th>
            <th>Action</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ((array)$savedImages as $imgId): ?>
          <tr data-id="<?php echo htmlspecialchars($imgId); ?>">
            <td><?php echo htmlspecialchars($imgId); ?></td>
            <td><input type="text" class="form-control input-sm img-name"
                 value="<?php echo htmlspecialchars($imageNames[$imgId] ?? $imgId); ?>"></td>
            <td><input type="number" step="0.01" min="0" class="form-control input-sm img-price"
                 value="<?php echo htmlspecialchars($imagePrices[$imgId] ?? '0'); ?>"></td>
            <td><button class="btn btn-xs btn-danger btn-remove-image">Remove</button></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
      <button class="btn btn-success" id="btn-save-image-config">
        <i class="fa fa-save"></i> Save Image Configuration
      </button>
      <div id="images-save-msg" class="cmp-alert"></div>
    </div>
  </div>

  <script>
  (function() {
    var serverId = <?php echo $serverId; ?>;
    var moduleUrl = '<?php echo $moduleUrl; ?>';

    $('#btn-load-images').on('click', function() {
      $('#images-loading').show();
      $('#images-error').hide();
      $.post(moduleUrl, { action: 'load_images', server_id: serverId }, function(resp) {
        $('#images-loading').hide();
        if (!resp.success) {
          $('#images-error').text(resp.error || 'Failed to load images.').show();
          return;
        }
        renderImagesTable(resp.images);
      }, 'json').fail(function() {
        $('#images-loading').hide();
        $('#images-error').text('Request failed. Check server connectivity.').show();
      });
    });

    function renderImagesTable(images) {
      var savedImages = <?php echo json_encode((array)$savedImages); ?>;
      var imageNames  = <?php echo json_encode((object)($imageNames ?: new stdClass())); ?>;
      var imagePrices = <?php echo json_encode((object)($imagePrices ?: new stdClass())); ?>;

      var html = '<table class="table table-bordered cmp-resource-table"><thead><tr>' +
        '<th><input type="checkbox" id="check-all-images"> All</th>' +
        '<th>Image ID</th><th>Name</th>' +
        '</tr></thead><tbody>';

      $.each(images, function(i, img) {
        var checked = (savedImages.indexOf(img.id) !== -1) ? 'checked' : '';
        html += '<tr><td><input type="checkbox" class="img-check" value="' + $('<span>').text(img.id).html() + '" ' + checked + '></td>' +
          '<td>' + $('<span>').text(img.id).html() + '</td>' +
          '<td>' + $('<span>').text(img.name).html() + '</td></tr>';
      });

      html += '</tbody></table>';
      html += '<button class="btn btn-primary" id="btn-apply-image-selection"><i class="fa fa-check"></i> Apply Selection</button>';

      $('#images-container').html(html);

      $('#check-all-images').on('change', function() {
        $('.img-check').prop('checked', $(this).is(':checked'));
      });

      $('#btn-apply-image-selection').on('click', function() {
        var selected = [];
        $('.img-check:checked').each(function() { selected.push($(this).val()); });

        $.post(moduleUrl, { action: 'save_images', server_id: serverId, selected_images: selected }, function(resp) {
          if (resp.success) {
            // Rebuild config table
            var tbody = $('#images-config-table tbody');
            tbody.empty();
            $.each(selected, function(i, imgId) {
              var name  = imageNames[imgId] || imgId;
              var price = imagePrices[imgId] || '0';
              tbody.append('<tr data-id="' + $('<span>').text(imgId).html() + '">' +
                '<td>' + $('<span>').text(imgId).html() + '</td>' +
                '<td><input type="text" class="form-control input-sm img-name" value="' + $('<span>').text(name).html() + '"></td>' +
                '<td><input type="number" step="0.01" min="0" class="form-control input-sm img-price" value="' + $('<span>').text(price).html() + '"></td>' +
                '<td><button class="btn btn-xs btn-danger btn-remove-image">Remove</button></td>' +
                '</tr>');
            });
            $('#images-saved-section').show();
          }
        }, 'json');
      });
    }

    $(document).on('click', '.btn-remove-image', function() {
      $(this).closest('tr').remove();
    });

    $('#btn-save-image-config').on('click', function() {
      var names = {}, prices = {};
      $('#images-config-table tbody tr').each(function() {
        var id = $(this).data('id');
        names[id]  = $(this).find('.img-name').val();
        prices[id] = $(this).find('.img-price').val();
      });

      var ids = [];
      $('#images-config-table tbody tr').each(function() { ids.push($(this).data('id')); });

      $.when(
        $.post(moduleUrl, { action: 'save_image_names',  server_id: serverId, names:  names  }, null, 'json'),
        $.post(moduleUrl, { action: 'save_image_prices', server_id: serverId, prices: prices }, null, 'json'),
        $.post(moduleUrl, { action: 'save_images',       server_id: serverId, selected_images: ids }, null, 'json')
      ).done(function() {
        $('#images-save-msg').text('Image configuration saved successfully.').addClass('alert alert-success').show();
        setTimeout(function() { $('#images-save-msg').hide(); }, 3000);
      });
    });
  }());
  </script>
  <?php
}

/**
 * Render the Flavors tab.
 *
 * @param int    $serverId  Active server ID
 * @param string $moduleUrl Base module URL
 */
function cloudpe_cmp_admin_render_flavors(int $serverId, string $moduleUrl): void
{
  $savedFlavors = cloudpe_cmp_admin_get_setting($serverId, 'selected_flavors', []);
  $flavorNames  = cloudpe_cmp_admin_get_setting($serverId, 'flavor_names', []);
  $flavorPrices = cloudpe_cmp_admin_get_setting($serverId, 'flavor_prices', []);
  ?>
  <div class="cmp-section">
    <h4>Flavors
      <button class="btn btn-sm btn-primary pull-right" id="btn-load-flavors">
        <i class="fa fa-refresh"></i> Load from API
      </button>
    </h4>
    <div id="flavors-loading" class="cmp-spinner"><i class="fa fa-spinner fa-spin"></i> Loading flavors...</div>
    <div id="flavors-error" class="alert alert-danger cmp-alert"></div>
    <div id="flavors-container">
      <?php if (!empty($savedFlavors)): ?>
      <div class="alert alert-info" style="display:block;">
        Showing previously saved selection. Click <strong>Load from API</strong> to refresh.
      </div>
      <?php endif; ?>
    </div>

    <div id="flavors-saved-section" style="<?php echo empty($savedFlavors) ? 'display:none;' : ''; ?>">
      <h4>Selected Flavors Configuration</h4>
      <table class="table table-bordered cmp-resource-table" id="flavors-config-table">
        <thead>
          <tr>
            <th>Flavor ID</th>
            <th>Display Name</th>
            <th>Monthly Price</th>
            <th>Action</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ((array)$savedFlavors as $flvId): ?>
          <tr data-id="<?php echo htmlspecialchars($flvId); ?>">
            <td><?php echo htmlspecialchars($flvId); ?></td>
            <td><input type="text" class="form-control input-sm flv-name"
                 value="<?php echo htmlspecialchars($flavorNames[$flvId] ?? $flvId); ?>"></td>
            <td><input type="number" step="0.01" min="0" class="form-control input-sm flv-price"
                 value="<?php echo htmlspecialchars($flavorPrices[$flvId] ?? '0'); ?>"></td>
            <td><button class="btn btn-xs btn-danger btn-remove-flavor">Remove</button></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
      <button class="btn btn-success" id="btn-save-flavor-config">
        <i class="fa fa-save"></i> Save Flavor Configuration
      </button>
      <div id="flavors-save-msg" class="cmp-alert"></div>
    </div>
  </div>

  <script>
  (function() {
    var serverId  = <?php echo $serverId; ?>;
    var moduleUrl = '<?php echo $moduleUrl; ?>';

    $('#btn-load-flavors').on('click', function() {
      $('#flavors-loading').show();
      $('#flavors-error').hide();
      $.post(moduleUrl, { action: 'load_flavors', server_id: serverId }, function(resp) {
        $('#flavors-loading').hide();
        if (!resp.success) {
          $('#flavors-error').text(resp.error || 'Failed to load flavors.').show();
          return;
        }
        renderFlavorsTable(resp.flavors);
      }, 'json').fail(function() {
        $('#flavors-loading').hide();
        $('#flavors-error').text('Request failed. Check server connectivity.').show();
      });
    });

    function renderFlavorsTable(flavors) {
      var savedFlavors = <?php echo json_encode((array)$savedFlavors); ?>;
      var flavorNames  = <?php echo json_encode((object)($flavorNames ?: new stdClass())); ?>;
      var flavorPrices = <?php echo json_encode((object)($flavorPrices ?: new stdClass())); ?>;

      var html = '<table class="table table-bordered cmp-resource-table"><thead><tr>' +
        '<th><input type="checkbox" id="check-all-flavors"> All</th>' +
        '<th>Flavor ID</th><th>Name</th><th>vCPU</th><th>RAM (GB)</th>' +
        '</tr></thead><tbody>';

      $.each(flavors, function(i, flv) {
        var checked = (savedFlavors.indexOf(flv.id) !== -1) ? 'checked' : '';
        html += '<tr>' +
          '<td><input type="checkbox" class="flv-check" value="' + $('<span>').text(flv.id).html() + '" ' + checked + '></td>' +
          '<td>' + $('<span>').text(flv.id).html() + '</td>' +
          '<td>' + $('<span>').text(flv.name).html() + '</td>' +
          '<td>' + (parseInt(flv.vcpu) || 0) + '</td>' +
          '<td>' + (parseFloat(flv.memory_gb) || 0) + '</td></tr>';
      });

      html += '</tbody></table>';
      html += '<button class="btn btn-primary" id="btn-apply-flavor-selection"><i class="fa fa-check"></i> Apply Selection</button>';

      $('#flavors-container').html(html);

      $('#check-all-flavors').on('change', function() {
        $('.flv-check').prop('checked', $(this).is(':checked'));
      });

      $('#btn-apply-flavor-selection').on('click', function() {
        var selected = [];
        $('.flv-check:checked').each(function() { selected.push($(this).val()); });

        $.post(moduleUrl, { action: 'save_flavors', server_id: serverId, selected_flavors: selected }, function(resp) {
          if (resp.success) {
            var tbody = $('#flavors-config-table tbody');
            tbody.empty();
            $.each(selected, function(i, flvId) {
              var name  = flavorNames[flvId] || flvId;
              var price = flavorPrices[flvId] || '0';
              tbody.append('<tr data-id="' + $('<span>').text(flvId).html() + '">' +
                '<td>' + $('<span>').text(flvId).html() + '</td>' +
                '<td><input type="text" class="form-control input-sm flv-name" value="' + $('<span>').text(name).html() + '"></td>' +
                '<td><input type="number" step="0.01" min="0" class="form-control input-sm flv-price" value="' + $('<span>').text(price).html() + '"></td>' +
                '<td><button class="btn btn-xs btn-danger btn-remove-flavor">Remove</button></td>' +
                '</tr>');
            });
            $('#flavors-saved-section').show();
          }
        }, 'json');
      });
    }

    $(document).on('click', '.btn-remove-flavor', function() {
      $(this).closest('tr').remove();
    });

    $('#btn-save-flavor-config').on('click', function() {
      var names = {}, prices = {};
      $('#flavors-config-table tbody tr').each(function() {
        var id = $(this).data('id');
        names[id]  = $(this).find('.flv-name').val();
        prices[id] = $(this).find('.flv-price').val();
      });

      var ids = [];
      $('#flavors-config-table tbody tr').each(function() { ids.push($(this).data('id')); });

      $.when(
        $.post(moduleUrl, { action: 'save_flavor_names',  server_id: serverId, names:  names  }, null, 'json'),
        $.post(moduleUrl, { action: 'save_flavor_prices', server_id: serverId, prices: prices }, null, 'json'),
        $.post(moduleUrl, { action: 'save_flavors',       server_id: serverId, selected_flavors: ids }, null, 'json')
      ).done(function() {
        $('#flavors-save-msg').text('Flavor configuration saved successfully.').addClass('alert alert-success').show();
        setTimeout(function() { $('#flavors-save-msg').hide(); }, 3000);
      });
    });
  }());
  </script>
  <?php
}

/**
 * Render the Disk Sizes tab.
 *
 * @param int    $serverId  Active server ID
 * @param string $moduleUrl Base module URL
 */
function cloudpe_cmp_admin_render_disks(int $serverId, string $moduleUrl): void
{
  $savedDisks = cloudpe_cmp_admin_get_setting($serverId, 'disk_sizes', []);
  $savedDisks = is_array($savedDisks) ? $savedDisks : [];
  ?>
  <div class="cmp-section">
    <h4>Disk Size Options</h4>
    <p class="text-muted">Define the disk size options customers can choose from when ordering.</p>

    <table class="table table-bordered cmp-resource-table" id="disks-table">
      <thead>
        <tr>
          <th>Size (GB)</th>
          <th>Display Label</th>
          <th>Monthly Price</th>
          <th>Action</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($savedDisks as $disk): ?>
        <tr>
          <td><input type="number" min="1" class="form-control input-sm disk-size"
               value="<?php echo (int)($disk['size_gb'] ?? 0); ?>"></td>
          <td><input type="text" class="form-control input-sm disk-label"
               value="<?php echo htmlspecialchars($disk['label'] ?? ''); ?>"></td>
          <td><input type="number" step="0.01" min="0" class="form-control input-sm disk-price"
               value="<?php echo htmlspecialchars($disk['price'] ?? '0'); ?>"></td>
          <td><button class="btn btn-xs btn-danger btn-remove-disk">Remove</button></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>

    <button class="btn btn-default btn-sm" id="btn-add-disk">
      <i class="fa fa-plus"></i> Add Disk Option
    </button>
    &nbsp;
    <button class="btn btn-success" id="btn-save-disks">
      <i class="fa fa-save"></i> Save Disk Sizes
    </button>
    <div id="disks-save-msg" class="cmp-alert" style="margin-top:10px;"></div>
  </div>

  <script>
  (function() {
    var serverId  = <?php echo $serverId; ?>;
    var moduleUrl = '<?php echo $moduleUrl; ?>';

    $('#btn-add-disk').on('click', function() {
      $('#disks-table tbody').append(
        '<tr>' +
        '<td><input type="number" min="1" class="form-control input-sm disk-size" placeholder="e.g. 50"></td>' +
        '<td><input type="text" class="form-control input-sm disk-label" placeholder="e.g. 50 GB SSD"></td>' +
        '<td><input type="number" step="0.01" min="0" class="form-control input-sm disk-price" value="0"></td>' +
        '<td><button class="btn btn-xs btn-danger btn-remove-disk">Remove</button></td>' +
        '</tr>'
      );
    });

    $(document).on('click', '.btn-remove-disk', function() {
      $(this).closest('tr').remove();
    });

    $('#btn-save-disks').on('click', function() {
      var disks = [];
      $('#disks-table tbody tr').each(function() {
        var size  = parseInt($(this).find('.disk-size').val());
        var label = $(this).find('.disk-label').val().trim();
        var price = parseFloat($(this).find('.disk-price').val()) || 0;
        if (size > 0) {
          disks.push({ size_gb: size, label: label || (size + ' GB'), price: price });
        }
      });

      $.post(moduleUrl, { action: 'save_disks', server_id: serverId, disks: disks }, function(resp) {
        var msg = $('#disks-save-msg');
        if (resp.success) {
          msg.text('Disk sizes saved successfully.').removeClass('alert-danger').addClass('alert alert-success').show();
        } else {
          msg.text(resp.message || 'Failed to save disk sizes.').removeClass('alert-success').addClass('alert alert-danger').show();
        }
        setTimeout(function() { msg.hide(); }, 3000);
      }, 'json');
    });
  }());
  </script>
  <?php
}

/**
 * Render the Create Config Group tab.
 *
 * @param int    $serverId  Active server ID
 * @param string $moduleUrl Base module URL
 */
function cloudpe_cmp_admin_render_create_group(int $serverId, string $moduleUrl): void
{
  $savedImages  = cloudpe_cmp_admin_get_setting($serverId, 'selected_images', []);
  $savedFlavors = cloudpe_cmp_admin_get_setting($serverId, 'selected_flavors', []);
  $savedDisks   = cloudpe_cmp_admin_get_setting($serverId, 'disk_sizes', []);
  ?>
  <div class="cmp-section">
    <h4>Create Configurable Options Group</h4>
    <p class="text-muted">
      Creates a WHMCS configurable options group pre-populated with your saved images, flavors,
      and disk sizes. You can then link this group to any product.
    </p>

    <div class="panel panel-default" style="max-width:600px;">
      <div class="panel-body">
        <h5>Resources to be included:</h5>
        <ul>
          <li><strong><?php echo count((array)$savedImages); ?></strong> image(s)
            <?php if (empty($savedImages)): ?><span class="text-danger">(none configured)</span><?php endif; ?>
          </li>
          <li><strong><?php echo count((array)$savedFlavors); ?></strong> flavor(s)
            <?php if (empty($savedFlavors)): ?><span class="text-danger">(none configured)</span><?php endif; ?>
          </li>
          <li><strong><?php echo count((array)$savedDisks); ?></strong> disk size option(s)
            <?php if (empty($savedDisks)): ?><span class="text-danger">(none configured)</span><?php endif; ?>
          </li>
        </ul>

        <?php if (empty($savedImages) && empty($savedFlavors) && empty($savedDisks)): ?>
        <div class="alert alert-warning">
          No resources are configured. Please configure
          <a href="<?php echo $moduleUrl; ?>&tab=images&server_id=<?php echo $serverId; ?>">images</a>,
          <a href="<?php echo $moduleUrl; ?>&tab=flavors&server_id=<?php echo $serverId; ?>">flavors</a>,
          and <a href="<?php echo $moduleUrl; ?>&tab=disks&server_id=<?php echo $serverId; ?>">disk sizes</a> first.
        </div>
        <?php else: ?>
        <hr>
        <div class="form-group">
          <label for="group-name">Group Name</label>
          <input type="text" id="group-name" class="form-control" value="CloudPe CMP Options"
                 placeholder="Enter a name for the configurable options group">
        </div>
        <button class="btn btn-primary" id="btn-create-group">
          <i class="fa fa-plus-circle"></i> Create Group
        </button>
        <div id="create-group-msg" class="cmp-alert" style="margin-top:10px;"></div>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <script>
  (function() {
    var serverId  = <?php echo $serverId; ?>;
    var moduleUrl = '<?php echo $moduleUrl; ?>';

    $('#btn-create-group').on('click', function() {
      var groupName = $('#group-name').val().trim();
      if (!groupName) {
        alert('Please enter a group name.');
        return;
      }

      $(this).prop('disabled', true).html('<i class="fa fa-spinner fa-spin"></i> Creating...');

      $.post(moduleUrl, {
        action:      'create_config_group',
        server_id:   serverId,
        group_name:  groupName,
      }, function(resp) {
        $('#btn-create-group').prop('disabled', false).html('<i class="fa fa-plus-circle"></i> Create Group');
        var msg = $('#create-group-msg');
        if (resp.success) {
          msg.html(resp.message + ' <a href="configoptiongroups.php" target="_blank">View groups &rarr;</a>')
             .removeClass('alert-danger').addClass('alert alert-success').show();
        } else {
          msg.text(resp.message || 'Failed to create group.').removeClass('alert-success').addClass('alert alert-danger').show();
        }
      }, 'json').fail(function() {
        $('#btn-create-group').prop('disabled', false).html('<i class="fa fa-plus-circle"></i> Create Group');
        $('#create-group-msg').text('Request failed.').addClass('alert alert-danger').show();
      });
    });
  }());
  </script>
  <?php
}

/**
 * Render the Updates tab.
 *
 * @param string $moduleUrl Base module URL
 */
function cloudpe_cmp_admin_render_updates(string $moduleUrl): void
{
  ?>
  <div class="cmp-section">
    <h4>Module Version</h4>
    <table class="table table-bordered" style="max-width:500px;">
      <tr><th style="width:160px;">Installed Version</th><td><strong><?php echo CLOUDPE_CMP_MODULE_VERSION; ?></strong></td></tr>
      <tr>
        <th>Latest Version</th>
        <td id="latest-version"><em class="text-muted">Click "Check for Updates"</em></td>
      </tr>
    </table>

    <button class="btn btn-default" id="btn-check-update">
      <i class="fa fa-search"></i> Check for Updates
    </button>
    <div id="update-spinner" class="cmp-spinner" style="margin-left:10px; display:inline-block;">
      <i class="fa fa-spinner fa-spin"></i>
    </div>
    <div id="update-info" class="cmp-alert" style="margin-top:15px;"></div>
  </div>

  <div class="cmp-section" id="install-update-section" style="display:none;">
    <h4>Install Update</h4>
    <p>A newer version is available. Installing will overwrite the current module files.</p>
    <button class="btn btn-warning" id="btn-install-update">
      <i class="fa fa-download"></i> Install Update
    </button>
    <div id="install-msg" class="cmp-alert" style="margin-top:10px;"></div>
  </div>

  <div class="cmp-section">
    <h4>All Releases
      <button class="btn btn-xs btn-default pull-right" id="btn-load-releases">
        <i class="fa fa-refresh"></i> Load Releases
      </button>
    </h4>
    <div id="releases-spinner" class="cmp-spinner"><i class="fa fa-spinner fa-spin"></i> Loading releases...</div>
    <div id="releases-container">
      <p class="text-muted">Click <strong>Load Releases</strong> to fetch the release history from GitHub.</p>
    </div>
  </div>

  <script>
  (function() {
    var moduleUrl    = '<?php echo $moduleUrl; ?>';
    var downloadUrl  = '';

    $('#btn-check-update').on('click', function() {
      $('#update-spinner').show();
      $('#update-info').hide();
      $.post(moduleUrl, { action: 'check_update' }, function(resp) {
        $('#update-spinner').hide();
        $('#latest-version').text(resp.latest || 'Unknown');

        if (resp.error) {
          $('#update-info').text('Error: ' + resp.error).removeClass('alert-success alert-info').addClass('alert alert-danger').show();
          return;
        }

        if (resp.update_available) {
          downloadUrl = resp.download_url || '';
          var changelogHtml = '';
          if (resp.changelog && resp.changelog.length) {
            changelogHtml = '<ul>' + $.map(resp.changelog, function(c) {
              return '<li>' + $('<span>').text(c).html() + '</li>';
            }).join('') + '</ul>';
          }
          $('#update-info')
            .html('<strong>Update available: v' + $('<span>').text(resp.latest).html() + '</strong>' + changelogHtml)
            .removeClass('alert-success alert-danger').addClass('alert alert-warning').show();
          $('#install-update-section').show();
        } else {
          $('#update-info')
            .text('You are running the latest version (' + resp.current + ').')
            .removeClass('alert-warning alert-danger').addClass('alert alert-success').show();
          $('#install-update-section').hide();
        }
      }, 'json').fail(function() {
        $('#update-spinner').hide();
        $('#update-info').text('Request failed.').addClass('alert alert-danger').show();
      });
    });

    $('#btn-install-update').on('click', function() {
      if (!downloadUrl) {
        alert('No download URL found. Please check for updates first.');
        return;
      }
      if (!confirm('Install the update now? The current module files will be overwritten.')) {
        return;
      }
      $(this).prop('disabled', true).html('<i class="fa fa-spinner fa-spin"></i> Installing...');
      $.post(moduleUrl, { action: 'install_update', download_url: downloadUrl }, function(resp) {
        $('#btn-install-update').prop('disabled', false).html('<i class="fa fa-download"></i> Install Update');
        var msg = $('#install-msg');
        if (resp.success) {
          msg.text(resp.message).removeClass('alert-danger').addClass('alert alert-success').show();
        } else {
          msg.text(resp.message || 'Update failed.').removeClass('alert-success').addClass('alert alert-danger').show();
        }
      }, 'json').fail(function() {
        $('#btn-install-update').prop('disabled', false).html('<i class="fa fa-download"></i> Install Update');
        $('#install-msg').text('Request failed during install.').addClass('alert alert-danger').show();
      });
    });

    $('#btn-load-releases').on('click', function() {
      $('#releases-spinner').show();
      $.post(moduleUrl, { action: 'get_releases' }, function(resp) {
        $('#releases-spinner').hide();
        if (!resp.success || !resp.releases.length) {
          $('#releases-container').html('<p class="text-muted">No releases found.</p>');
          return;
        }
        var html = '';
        $.each(resp.releases, function(i, release) {
          var isLatest = (i === 0);
          var tag      = $('<span>').text(release.tag_name || '').html();
          var name     = $('<span>').text(release.name || release.tag_name || '').html();
          var date     = release.published_at ? release.published_at.substring(0, 10) : '';
          var body     = $('<span>').text(release.body || '').html().replace(/\n/g, '<br>');
          var dlUrl    = '';
          if (release.assets && release.assets.length) {
            dlUrl = release.assets[0].browser_download_url || '';
          } else {
            dlUrl = release.zipball_url || '';
          }

          html += '<div class="cmp-release-item">';
          html += '<h5>' + name + ' <small style="color:#888;">' + date + '</small>';
          if (isLatest) html += ' <span class="cmp-badge-new">Latest</span>';
          html += '</h5>';
          if (body) html += '<p style="font-size:13px; color:#555; white-space:pre-wrap;">' + body + '</p>';
          if (dlUrl) {
            html += '<a href="' + $('<span>').text(dlUrl).html() + '" class="btn btn-xs btn-default" target="_blank">' +
              '<i class="fa fa-download"></i> Download ' + tag + '</a>';
          }
          html += '</div>';
        });
        $('#releases-container').html(html);
      }, 'json').fail(function() {
        $('#releases-spinner').hide();
        $('#releases-container').html('<p class="text-danger">Failed to load releases.</p>');
      });
    });
  }());
  </script>
  <?php
}

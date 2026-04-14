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

define('CLOUDPE_CMP_MODULE_VERSION', '1.0.8');
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

  // Per-file stats so we can report back exactly what happened; this
  // makes silent failures (e.g. permission denied, opcache lock)
  // debuggable from the WHMCS admin UI.
  $stats = [
    'files_written' => 0,
    'files_failed'  => 0,
    'opcache_invalidated' => 0,
    'failures'      => [],
  ];

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

    // WHMCS defines ROOTDIR globally - use it rather than a fragile
    // dirname() chain (v1.0.2 got the dirname count wrong and wrote
    // files one level above the real WHMCS root, silently because the
    // parent directory was writable).
    if (!defined('ROOTDIR')) {
      throw new \Exception('ROOTDIR is not defined - run this from inside WHMCS.');
    }
    $whmcsRoot = ROOTDIR;

    // Belt-and-suspenders: a real WHMCS root always has init.php
    // (or configuration.php). Fail fast if that's not the case so a
    // future misconfiguration can't silently write files somewhere
    // unexpected.
    if (!file_exists($whmcsRoot . '/init.php') && !file_exists($whmcsRoot . '/configuration.php')) {
      throw new \Exception(
        'Refusing to install: ROOTDIR (' . $whmcsRoot . ') does not look like a WHMCS root.'
      );
    }

    $dstServer = $whmcsRoot . '/modules/servers/cloudpe_cmp';
    $dstAddon  = $whmcsRoot . '/modules/addons/cloudpe_cmp_admin';
    // Note: we intentionally skip an is_writable() pre-check here.
    // On shared hosting PHP runs as the site user via suPHP/FastCGI, and
    // is_writable() can return false for directories that copy() will
    // actually succeed on (different effective UIDs, ACLs, etc.).
    // Instead, per-file failures are tracked in $stats and surfaced after
    // the attempt so the admin sees exactly which files failed and why.

    // Snapshot the current module files before we overwrite them so
    // admins can roll back if the update goes sideways. Matches the
    // reference cloudpe-whmcs behaviour.
    $backupDir = $whmcsRoot . '/modules/cloudpe_cmp_backup_' . date('YmdHis');
    $backupStats = [];
    if (is_dir($dstServer)) {
      cloudpe_cmp_admin_copy_directory($dstServer, $backupDir . '/servers/cloudpe_cmp', $backupStats);
    }
    if (is_dir($dstAddon)) {
      cloudpe_cmp_admin_copy_directory($dstAddon, $backupDir . '/addons/cloudpe_cmp_admin', $backupStats);
    }

    // Copy server module
    $srcServer = $modulesRoot . '/servers/cloudpe_cmp';
    if (is_dir($srcServer)) {
      cloudpe_cmp_admin_copy_directory($srcServer, $dstServer, $stats);
    }

    // Copy addon module
    $srcAddon = $modulesRoot . '/addons/cloudpe_cmp_admin';
    if (is_dir($srcAddon)) {
      cloudpe_cmp_admin_copy_directory($srcAddon, $dstAddon, $stats);
    }

    // Surface backup location in the stats so admins know where to
    // restore from if needed.
    $stats['backup_path'] = $backupDir;

    // Nuke PHP OPcache so the next request sees the freshly-written
    // files. Without this, the old bytecode keeps being served and
    // the UI continues to show the previous version even though the
    // files on disk are new.
    if (function_exists('opcache_reset')) {
      @opcache_reset();
    }

    if ($stats['files_failed'] > 0) {
      return [
        'success' => false,
        'message' => 'Update partially failed. '
          . $stats['files_written'] . ' file(s) written, '
          . $stats['files_failed'] . ' failed. '
          . 'First failure: ' . ($stats['failures'][0] ?? 'unknown'),
        'stats'   => $stats,
      ];
    }

    return [
      'success' => true,
      'message' => 'Module updated successfully ('
        . $stats['files_written'] . ' files written, '
        . $stats['opcache_invalidated'] . ' opcache entries cleared). '
        . 'Please refresh the page.',
      'stats'   => $stats,
    ];
  } catch (\Exception $e) {
    return ['success' => false, 'message' => $e->getMessage(), 'stats' => $stats];
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
 * Tracks per-file success/failure in $stats and invalidates PHP's
 * OPcache for any `.php` file written so the next request actually
 * executes the new code.
 *
 * @param string $src    Source directory path
 * @param string $dst    Destination directory path
 * @param array  $stats  Tally: files_written, files_failed, opcache_invalidated, failures[]
 */
function cloudpe_cmp_admin_copy_directory(string $src, string $dst, array &$stats = []): void
{
  if (!isset($stats['files_written']))       $stats['files_written'] = 0;
  if (!isset($stats['files_failed']))        $stats['files_failed']  = 0;
  if (!isset($stats['opcache_invalidated'])) $stats['opcache_invalidated'] = 0;
  if (!isset($stats['failures']))            $stats['failures']      = [];

  if (!is_dir($dst)) {
    if (!@mkdir($dst, 0755, true)) {
      $stats['files_failed']++;
      $stats['failures'][] = "mkdir failed: $dst";
      return;
    }
  }

  $items = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($src, RecursiveDirectoryIterator::SKIP_DOTS),
    RecursiveIteratorIterator::SELF_FIRST
  );

  foreach ($items as $item) {
    $target = $dst . DIRECTORY_SEPARATOR . $items->getSubPathName();
    if ($item->isDir()) {
      if (!is_dir($target) && !@mkdir($target, 0755, true)) {
        $stats['files_failed']++;
        $stats['failures'][] = "mkdir failed: $target";
      }
      continue;
    }

    // File: copy with explicit success check. @ suppress because we
    // surface failures via $stats rather than PHP warnings.
    if (@copy($item->getPathname(), $target)) {
      $stats['files_written']++;
      // Invalidate opcache entry for the just-written PHP file so
      // the next request picks up the new code. Without this, a
      // successful copy is useless on servers with OPcache enabled.
      if (substr($target, -4) === '.php' && function_exists('opcache_invalidate')) {
        if (@opcache_invalidate($target, true)) {
          $stats['opcache_invalidated']++;
        }
      }
    } else {
      $stats['files_failed']++;
      $err = error_get_last();
      $stats['failures'][] = "copy failed: {$target}" . ($err ? " ({$err['message']})" : '');
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
function cloudpe_cmp_admin_load_images(int $serverId, string $region = ''): array
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
    $result = $api->listImages($region);

    if (!$result['success']) {
      return ['success' => false, 'error' => $result['error'] ?? 'Failed to load images.'];
    }

    $images = [];
    foreach ((array)($result['images'] ?? []) as $img) {
      $id = $img['id'] ?? $img['slug'] ?? '';
      // The CMP API does not embed a region slug on each image object;
      // region is a query filter. We stamp the fetched region onto every
      // returned item so the UI can display and save it.
      $images[] = [
        'id'     => $id,
        'name'   => $img['name'] ?? $img['display_name'] ?? $id,
        'region' => $region,
      ];
    }

    return ['success' => true, 'images' => $images, 'region' => $region];
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
function cloudpe_cmp_admin_load_flavors(int $serverId, string $region = ''): array
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
    $result = $api->listFlavors($region);

    if (!$result['success']) {
      return ['success' => false, 'error' => $result['error'] ?? 'Failed to load flavors.'];
    }

    $flavors = [];
    foreach ((array)($result['flavors'] ?? []) as $flv) {
      $id = $flv['id'] ?? $flv['slug'] ?? '';
      // Region is a query filter on the CMP API, not a per-item field;
      // stamp the requested region onto every returned flavor.
      $flavors[] = [
        'id'        => $id,
        'name'      => $flv['name'] ?? $flv['display_name'] ?? $id,
        'vcpu'      => $flv['vcpus'] ?? $flv['vcpu'] ?? $flv['cpu'] ?? 0,
        'memory_gb' => isset($flv['ram'])
          ? round($flv['ram'] / 1024, 1)
          : ($flv['memory_gb'] ?? $flv['memory'] ?? 0),
        'region'    => $region,
      ];
    }

    return ['success' => true, 'flavors' => $flavors, 'region' => $region];
  } catch (\Exception $e) {
    return ['success' => false, 'error' => $e->getMessage()];
  }
}

/**
 * Load projects visible to the authenticated API key.
 *
 * @param int $serverId WHMCS server ID
 * @return array  Keys: success (bool), projects (array)|error (string)
 */
function cloudpe_cmp_admin_load_projects(int $serverId): array
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
    $result = $api->listProjects();

    if (!$result['success']) {
      return ['success' => false, 'error' => $result['error'] ?? 'Failed to load projects.'];
    }

    $projects = [];
    foreach ((array)($result['projects'] ?? []) as $proj) {
      // Projects may carry region info under several shapes depending on
      // how the API evolves; normalize into a comma-separated string.
      $regionVal = '';
      if (!empty($proj['region'])) {
        $regionVal = is_array($proj['region']) ? implode(',', $proj['region']) : (string)$proj['region'];
      } elseif (!empty($proj['regions'])) {
        if (is_array($proj['regions'])) {
          // Array of slugs or array of region objects
          $slugs = array_map(function ($r) {
            return is_array($r) ? ($r['slug'] ?? $r['id'] ?? $r['name'] ?? '') : (string)$r;
          }, $proj['regions']);
          $regionVal = implode(',', array_filter($slugs));
        } else {
          $regionVal = (string)$proj['regions'];
        }
      }

      $projects[] = [
        'id'     => $proj['id'] ?? $proj['uuid'] ?? '',
        'name'   => $proj['name'] ?? $proj['display_name'] ?? $proj['id'] ?? '',
        'region' => $regionVal,
      ];
    }

    // Also fetch available regions so the UI can render a dropdown for
    // per-project region assignment.
    $regions = [];
    try {
      $regResult = $api->listRegions('vm');
      if (!empty($regResult['success']) && !empty($regResult['regions'])) {
        foreach ($regResult['regions'] as $r) {
          $slug = $r['slug'] ?? $r['id'] ?? '';
          if (!$slug) continue;
          $regions[] = [
            'slug' => $slug,
            'name' => $r['name'] ?? $slug,
          ];
        }
      }
    } catch (\Exception $e) { /* region list is optional */ }

    return ['success' => true, 'projects' => $projects, 'regions' => $regions];
  } catch (\Exception $e) {
    return ['success' => false, 'error' => $e->getMessage()];
  }
}

/**
 * Load security groups for the configured project.
 *
 * Uses the server's Access Hash as the project_id. If no project is
 * configured on the server, returns a clear error so the admin knows
 * to populate it.
 *
 * @param int $serverId WHMCS server ID
 * @return array  Keys: success (bool), security_groups (array)|error (string)
 */
function cloudpe_cmp_admin_load_security_groups(int $serverId): array
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

  $projectId = trim($server->accesshash ?? '');
  if (!$projectId) {
    return ['success' => false, 'error' => 'No project ID set on the server (Access Hash field). Set it first.'];
  }

  $params = [
    'serverhostname'   => $server->hostname,
    'serverpassword'   => decrypt($server->password),
    'serveraccesshash' => $server->accesshash,
    'serversecure'     => $server->secure,
  ];

  try {
    $api    = new CloudPeCmpAPI($params);
    $result = $api->listSecurityGroups($projectId);

    if (!$result['success']) {
      return ['success' => false, 'error' => $result['error'] ?? 'Failed to load security groups.'];
    }

    $groups = [];
    foreach ((array)($result['security_groups'] ?? []) as $sg) {
      $groups[] = [
        'id'          => $sg['id'] ?? '',
        'name'        => $sg['name'] ?? $sg['id'] ?? '',
        'description' => $sg['description'] ?? '',
      ];
    }

    return ['success' => true, 'security_groups' => $groups];
  } catch (\Exception $e) {
    return ['success' => false, 'error' => $e->getMessage()];
  }
}

/**
 * Decide whether we need to hit the CMP API to fill in missing
 * friendly names. Returns true if any selected ID has no saved
 * Display Name or the saved name is just the ID itself.
 *
 * @param array $selectedIds  Array of resource IDs (UUIDs)
 * @param array $nameMap      Map of id => display name
 * @return bool
 */
function cloudpe_cmp_admin_needs_name_lookup(array $selectedIds, array $nameMap): bool
{
  foreach ($selectedIds as $id) {
    $name = $nameMap[$id] ?? '';
    if ($name === '' || $name === $id) {
      return true;
    }
  }
  return false;
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

  // Fallback: if any saved Display Name is empty or equal to the ID
  // (e.g. admin never clicked Apply with a loaded list, or saved
  // names pre-date the v1.0.1 name-auto-persist fix), fetch live names
  // from the CMP API so the configurable options get friendly labels
  // instead of raw UUIDs.
  $needImageLookup  = cloudpe_cmp_admin_needs_name_lookup($savedImages, $imageNames);
  $needFlavorLookup = cloudpe_cmp_admin_needs_name_lookup($savedFlavors, $flavorNames);
  if ($needImageLookup || $needFlavorLookup) {
    if ($needImageLookup) {
      $live = cloudpe_cmp_admin_load_images($serverId);
      if (!empty($live['success']) && !empty($live['images'])) {
        foreach ($live['images'] as $img) {
          $id = $img['id'] ?? '';
          if (!$id) continue;
          // Only override when we don't already have a real, non-ID name
          if (empty($imageNames[$id]) || $imageNames[$id] === $id) {
            $imageNames[$id] = $img['name'] ?? $id;
          }
        }
      }
    }
    if ($needFlavorLookup) {
      $live = cloudpe_cmp_admin_load_flavors($serverId);
      if (!empty($live['success']) && !empty($live['flavors'])) {
        foreach ($live['flavors'] as $flv) {
          $id = $flv['id'] ?? '';
          if (!$id) continue;
          if (empty($flavorNames[$id]) || $flavorNames[$id] === $id) {
            $flavorNames[$id] = $flv['name'] ?? $id;
          }
        }
      }
    }
    // Persist the resolved names so next Create Config Group (and the
    // product dropdowns) pick them up without another live lookup.
    if ($needImageLookup)  cloudpe_cmp_admin_save_setting($serverId, 'image_names', $imageNames);
    if ($needFlavorLookup) cloudpe_cmp_admin_save_setting($serverId, 'flavor_names', $flavorNames);
  }

  try {
    // Create the configurable options group
    $groupId = Capsule::table('tblproductconfiggroups')->insertGetId([
      'name'        => $groupName,
      'description' => 'Auto-generated by CloudPe CMP Manager',
    ]);

    $sortOrder = 0;

    // --- Operating System ---
    if (!empty($savedImages)) {
      $osOptionId = Capsule::table('tblproductconfigoptions')->insertGetId([
        'gid'         => $groupId,
        'optionname'  => 'Operating System',
        'optiontype'  => 1, // dropdown
        'qtyminimum'  => 0,
        'qtymaximum'  => 0,
        'order'       => $sortOrder++,
        'hidden'      => 0,
      ]);

      $osSubOrder = 0;
      foreach ($savedImages as $imageId) {
        $displayName = $imageNames[$imageId] ?? $imageId;
        $price       = (float)($imagePrices[$imageId] ?? 0);

        $subId = Capsule::table('tblproductconfigoptionssub')->insertGetId([
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
      $sizeOptionId = Capsule::table('tblproductconfigoptions')->insertGetId([
        'gid'        => $groupId,
        'optionname' => 'Server Size',
        'optiontype' => 1, // dropdown
        'qtyminimum' => 0,
        'qtymaximum' => 0,
        'order'      => $sortOrder++,
        'hidden'     => 0,
      ]);

      $sizeSubOrder = 0;
      foreach ($savedFlavors as $flavorId) {
        $displayName = $flavorNames[$flavorId] ?? $flavorId;
        $price       = (float)($flavorPrices[$flavorId] ?? 0);

        $subId = Capsule::table('tblproductconfigoptionssub')->insertGetId([
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
      $diskOptionId = Capsule::table('tblproductconfigoptions')->insertGetId([
        'gid'        => $groupId,
        'optionname' => 'Disk Space',
        'optiontype' => 1, // dropdown
        'qtyminimum' => 0,
        'qtymaximum' => 0,
        'order'      => $sortOrder++,
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

        $subId = Capsule::table('tblproductconfigoptionssub')->insertGetId([
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
        echo json_encode(cloudpe_cmp_admin_load_images($serverId, trim($_POST['region'] ?? '')));
        exit;

      case 'load_flavors':
        echo json_encode(cloudpe_cmp_admin_load_flavors($serverId, trim($_POST['region'] ?? '')));
        exit;

      case 'load_projects':
        echo json_encode(cloudpe_cmp_admin_load_projects($serverId));
        exit;

      case 'load_security_groups':
        echo json_encode(cloudpe_cmp_admin_load_security_groups($serverId));
        exit;

      case 'save_projects':
        $selectedProjects = $_POST['selected_projects'] ?? [];
        cloudpe_cmp_admin_save_setting($serverId, 'selected_projects', $selectedProjects);
        echo json_encode(['success' => true, 'message' => 'Project selection saved.']);
        exit;

      case 'save_project_names':
        $names = $_POST['names'] ?? [];
        cloudpe_cmp_admin_save_setting($serverId, 'project_names', $names);
        echo json_encode(['success' => true, 'message' => 'Project display names saved.']);
        exit;

      case 'save_project_regions':
        $regions = $_POST['regions'] ?? [];
        cloudpe_cmp_admin_save_setting($serverId, 'project_regions', $regions);
        echo json_encode(['success' => true, 'message' => 'Project regions saved.']);
        exit;

      case 'save_security_groups':
        $selectedSgs = $_POST['selected_security_groups'] ?? [];
        cloudpe_cmp_admin_save_setting($serverId, 'selected_security_groups', $selectedSgs);
        echo json_encode(['success' => true, 'message' => 'Security group selection saved.']);
        exit;

      case 'save_security_group_names':
        $names = $_POST['names'] ?? [];
        cloudpe_cmp_admin_save_setting($serverId, 'security_group_names', $names);
        echo json_encode(['success' => true, 'message' => 'Security group names saved.']);
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

      case 'save_image_regions':
        cloudpe_cmp_admin_save_setting($serverId, 'image_regions', $_POST['regions'] ?? []);
        echo json_encode(['success' => true]);
        exit;

      case 'save_flavor_regions':
        cloudpe_cmp_admin_save_setting($serverId, 'flavor_regions', $_POST['regions'] ?? []);
        echo json_encode(['success' => true]);
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
        'projects'      => 'Projects',
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
        case 'projects':
          cloudpe_cmp_admin_render_projects($serverId, $moduleUrl);
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
  $savedImages  = cloudpe_cmp_admin_get_setting($serverId, 'selected_images', []);
  $imageNames   = cloudpe_cmp_admin_get_setting($serverId, 'image_names', []);
  $imagePrices  = cloudpe_cmp_admin_get_setting($serverId, 'image_prices', []);
  $imageRegions = cloudpe_cmp_admin_get_setting($serverId, 'image_regions', []);
  ?>
  <div class="cmp-section">
    <h4>Images</h4>
    <div class="form-inline" style="margin-bottom:10px;">
      <div class="form-group">
        <label for="img-region-select" style="margin-right:8px;"><strong>Region:</strong></label>
        <select id="img-region-select" class="form-control" style="width:220px; margin-right:8px;">
          <option value="">Loading regions...</option>
        </select>
      </div>
      <button class="btn btn-primary" id="btn-load-images">
        <i class="fa fa-refresh"></i> Load from API
      </button>
    </div>
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
            <th>Region</th>
            <th>Monthly Price (add-on)</th>
            <th>Action</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ((array)$savedImages as $imgId):
            $savedName = $imageNames[$imgId] ?? '';
            // Show ID only if no real name has been persisted yet
            $displayName = ($savedName !== '' && $savedName !== $imgId) ? $savedName : $imgId;
          ?>
          <tr data-id="<?php echo htmlspecialchars($imgId); ?>"
              data-region="<?php echo htmlspecialchars($imageRegions[$imgId] ?? ''); ?>">
            <td><?php echo htmlspecialchars($imgId); ?></td>
            <td><input type="text" class="form-control input-sm img-name"
                 value="<?php echo htmlspecialchars($displayName); ?>"></td>
            <td><?php echo htmlspecialchars($imageRegions[$imgId] ?? '—'); ?></td>
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

    // Session caches built on Load from API
    var loadedImageNames   = {};
    var loadedImageRegions = {};

    // Populate the region selector on page load
    $.post(moduleUrl, { action: 'load_projects', server_id: serverId }, null, 'json')
      .done(function(resp) {
        // Reuse the regions list returned alongside projects
        if (resp && resp.regions && resp.regions.length) {
          var sel = $('#img-region-select').empty().append('<option value="">(all regions)</option>');
          $.each(resp.regions, function(i, r) {
            sel.append('<option value="' + $('<span>').text(r.slug).html() + '">'
              + $('<span>').text(r.name + ' (' + r.slug + ')').html() + '</option>');
          });
        } else {
          $('#img-region-select').empty().append('<option value="">(no regions found – enter manually)</option>');
        }
      });

    $('#btn-load-images').on('click', function() {
      var region = $('#img-region-select').val();
      $('#images-loading').show();
      $('#images-error').hide();
      $.post(moduleUrl, { action: 'load_images', server_id: serverId, region: region }, function(resp) {
        $('#images-loading').hide();
        if (!resp.success) {
          $('#images-error').text(resp.error || 'Failed to load images.').show();
          return;
        }
        loadedImageNames   = {};
        loadedImageRegions = {};
        // The API returns region as a filter, not per-item; the loader
        // stamps the query region on every returned object so we can
        // pre-fill the Region column automatically.
        var fetchedRegion = resp.region || region;
        $.each(resp.images, function(i, img) {
          loadedImageNames[img.id]   = img.name;
          loadedImageRegions[img.id] = img.region || fetchedRegion;
        });
        renderImagesTable(resp.images);
      }, 'json').fail(function() {
        $('#images-loading').hide();
        $('#images-error').text('Request failed. Check server connectivity.').show();
      });
    });

    function bestName(savedNames, id) {
      // Prefer the saved name only when it is a real name (not the UUID).
      var s = savedNames[id] || '';
      return (s && s !== id) ? s : (loadedImageNames[id] || id);
    }

    function renderImagesTable(images) {
      var savedImages  = <?php echo json_encode((array)$savedImages); ?>;
      var imageNames   = <?php echo json_encode((object)($imageNames  ?: new stdClass())); ?>;
      var imageRegions = <?php echo json_encode((object)($imageRegions ?: new stdClass())); ?>;
      var imagePrices  = <?php echo json_encode((object)($imagePrices  ?: new stdClass())); ?>;

      var html = '<table class="table table-bordered cmp-resource-table"><thead><tr>' +
        '<th><input type="checkbox" id="check-all-images"> All</th>' +
        '<th>Image ID</th><th>Name</th><th>Region</th>' +
        '</tr></thead><tbody>';

      $.each(images, function(i, img) {
        var checked = (savedImages.indexOf(img.id) !== -1) ? 'checked' : '';
        html += '<tr data-id="' + $('<span>').text(img.id).html() + '">' +
          '<td><input type="checkbox" class="img-check" value="' + $('<span>').text(img.id).html() + '" ' + checked + '></td>' +
          '<td>' + $('<span>').text(img.id).html() + '</td>' +
          '<td>' + $('<span>').text(img.name).html() + '</td>' +
          '<td>' + $('<span>').text(img.region || '').html() + '</td>' +
          '</tr>';
      });

      html += '</tbody></table>';
      html += '<button class="btn btn-primary" id="btn-apply-image-selection"><i class="fa fa-check"></i> Apply Selection</button>';

      $('#images-container').html(html);

      $('#check-all-images').on('change', function() {
        $('.img-check').prop('checked', $(this).is(':checked'));
      });

      $('#btn-apply-image-selection').on('click', function() {
        var selected = [], regionsByRow = {};
        $('#images-container tbody tr').each(function() {
          var row = $(this), id = row.data('id');
          if (row.find('.img-check').is(':checked')) {
            selected.push(id);
            // Capture region from the fetch table row
            var cells = row.find('td');
            regionsByRow[id] = cells.eq(3).text().trim();
          }
        });

        $.post(moduleUrl, { action: 'save_images', server_id: serverId, selected_images: selected }, function(resp) {
          if (resp.success) {
            var tbody = $('#images-config-table tbody');
            tbody.empty();
            var namesToPersist = {}, regionsToPersist = {};
            $.each(selected, function(i, imgId) {
              var name   = bestName(imageNames, imgId);
              var region = regionsByRow[imgId] || imageRegions[imgId] || loadedImageRegions[imgId] || '';
              var price  = imagePrices[imgId] || '0';
              namesToPersist[imgId]   = name;
              regionsToPersist[imgId] = region;
              tbody.append('<tr data-id="' + $('<span>').text(imgId).html() + '" data-region="' + $('<span>').text(region).html() + '">' +
                '<td>' + $('<span>').text(imgId).html() + '</td>' +
                '<td><input type="text" class="form-control input-sm img-name" value="' + $('<span>').text(name).html() + '"></td>' +
                '<td>' + $('<span>').text(region || '—').html() + '</td>' +
                '<td><input type="number" step="0.01" min="0" class="form-control input-sm img-price" value="' + $('<span>').text(price).html() + '"></td>' +
                '<td><button class="btn btn-xs btn-danger btn-remove-image">Remove</button></td>' +
                '</tr>');
            });
            $('#images-saved-section').show();
            $.post(moduleUrl, { action: 'save_image_names',   server_id: serverId, names:   namesToPersist   }, null, 'json');
            $.post(moduleUrl, { action: 'save_image_regions', server_id: serverId, regions: regionsToPersist }, null, 'json');
          }
        }, 'json');
      });
    }

    $(document).on('click', '.btn-remove-image', function() {
      $(this).closest('tr').remove();
    });

    $('#btn-save-image-config').on('click', function() {
      var names = {}, regions = {}, prices = {}, ids = [];
      $('#images-config-table tbody tr').each(function() {
        var id = $(this).data('id');
        ids.push(id);
        names[id]   = $(this).find('.img-name').val();
        regions[id] = $(this).data('region') || '';   // read from data attr, not an input
        prices[id]  = $(this).find('.img-price').val();
      });

      $.when(
        $.post(moduleUrl, { action: 'save_image_names',   server_id: serverId, names:   names   }, null, 'json'),
        $.post(moduleUrl, { action: 'save_image_regions', server_id: serverId, regions: regions }, null, 'json'),
        $.post(moduleUrl, { action: 'save_image_prices',  server_id: serverId, prices:  prices  }, null, 'json'),
        $.post(moduleUrl, { action: 'save_images',        server_id: serverId, selected_images: ids }, null, 'json')
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
  $savedFlavors  = cloudpe_cmp_admin_get_setting($serverId, 'selected_flavors', []);
  $flavorNames   = cloudpe_cmp_admin_get_setting($serverId, 'flavor_names', []);
  $flavorPrices  = cloudpe_cmp_admin_get_setting($serverId, 'flavor_prices', []);
  $flavorRegions = cloudpe_cmp_admin_get_setting($serverId, 'flavor_regions', []);
  ?>
  <div class="cmp-section">
    <h4>Flavors</h4>
    <div class="form-inline" style="margin-bottom:10px;">
      <div class="form-group">
        <label for="flv-region-select" style="margin-right:8px;"><strong>Region:</strong></label>
        <select id="flv-region-select" class="form-control" style="width:220px; margin-right:8px;">
          <option value="">Loading regions...</option>
        </select>
      </div>
      <button class="btn btn-primary" id="btn-load-flavors">
        <i class="fa fa-refresh"></i> Load from API
      </button>
    </div>
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
            <th>Region</th>
            <th>Monthly Price</th>
            <th>Action</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ((array)$savedFlavors as $flvId):
            $savedName = $flavorNames[$flvId] ?? '';
            $displayName = ($savedName !== '' && $savedName !== $flvId) ? $savedName : $flvId;
          ?>
          <tr data-id="<?php echo htmlspecialchars($flvId); ?>"
              data-region="<?php echo htmlspecialchars($flavorRegions[$flvId] ?? ''); ?>">
            <td><?php echo htmlspecialchars($flvId); ?></td>
            <td><input type="text" class="form-control input-sm flv-name"
                 value="<?php echo htmlspecialchars($displayName); ?>"></td>
            <td><?php echo htmlspecialchars($flavorRegions[$flvId] ?? '—'); ?></td>
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

    var loadedFlavorNames   = {};
    var loadedFlavorRegions = {};

    // Populate the region selector on page load
    $.post(moduleUrl, { action: 'load_projects', server_id: serverId }, null, 'json')
      .done(function(resp) {
        if (resp && resp.regions && resp.regions.length) {
          var sel = $('#flv-region-select').empty().append('<option value="">(all regions)</option>');
          $.each(resp.regions, function(i, r) {
            sel.append('<option value="' + $('<span>').text(r.slug).html() + '">'
              + $('<span>').text(r.name + ' (' + r.slug + ')').html() + '</option>');
          });
        } else {
          $('#flv-region-select').empty().append('<option value="">(no regions found – enter manually)</option>');
        }
      });

    $('#btn-load-flavors').on('click', function() {
      var region = $('#flv-region-select').val();
      $('#flavors-loading').show();
      $('#flavors-error').hide();
      $.post(moduleUrl, { action: 'load_flavors', server_id: serverId, region: region }, function(resp) {
        $('#flavors-loading').hide();
        if (!resp.success) {
          $('#flavors-error').text(resp.error || 'Failed to load flavors.').show();
          return;
        }
        loadedFlavorNames   = {};
        loadedFlavorRegions = {};
        var fetchedRegion = resp.region || region;
        $.each(resp.flavors, function(i, flv) {
          loadedFlavorNames[flv.id]   = flv.name;
          loadedFlavorRegions[flv.id] = flv.region || fetchedRegion;
        });
        renderFlavorsTable(resp.flavors);
      }, 'json').fail(function() {
        $('#flavors-loading').hide();
        $('#flavors-error').text('Request failed. Check server connectivity.').show();
      });
    });

    function bestFlvName(savedNames, id) {
      var s = savedNames[id] || '';
      return (s && s !== id) ? s : (loadedFlavorNames[id] || id);
    }

    function renderFlavorsTable(flavors) {
      var savedFlavors  = <?php echo json_encode((array)$savedFlavors); ?>;
      var flavorNames   = <?php echo json_encode((object)($flavorNames  ?: new stdClass())); ?>;
      var flavorRegions = <?php echo json_encode((object)($flavorRegions ?: new stdClass())); ?>;
      var flavorPrices  = <?php echo json_encode((object)($flavorPrices  ?: new stdClass())); ?>;

      var html = '<table class="table table-bordered cmp-resource-table"><thead><tr>' +
        '<th><input type="checkbox" id="check-all-flavors"> All</th>' +
        '<th>Flavor ID</th><th>Name</th><th>vCPU</th><th>RAM (GB)</th><th>Region</th>' +
        '</tr></thead><tbody>';

      $.each(flavors, function(i, flv) {
        var checked = (savedFlavors.indexOf(flv.id) !== -1) ? 'checked' : '';
        html += '<tr data-id="' + $('<span>').text(flv.id).html() + '">' +
          '<td><input type="checkbox" class="flv-check" value="' + $('<span>').text(flv.id).html() + '" ' + checked + '></td>' +
          '<td>' + $('<span>').text(flv.id).html() + '</td>' +
          '<td>' + $('<span>').text(flv.name).html() + '</td>' +
          '<td>' + (parseInt(flv.vcpu) || 0) + '</td>' +
          '<td>' + (parseFloat(flv.memory_gb) || 0) + '</td>' +
          '<td>' + $('<span>').text(flv.region || '').html() + '</td>' +
          '</tr>';
      });

      html += '</tbody></table>';
      html += '<button class="btn btn-primary" id="btn-apply-flavor-selection"><i class="fa fa-check"></i> Apply Selection</button>';

      $('#flavors-container').html(html);

      $('#check-all-flavors').on('change', function() {
        $('.flv-check').prop('checked', $(this).is(':checked'));
      });

      $('#btn-apply-flavor-selection').on('click', function() {
        var selected = [], regionsByRow = {};
        $('#flavors-container tbody tr').each(function() {
          var row = $(this), id = row.data('id');
          if (row.find('.flv-check').is(':checked')) {
            selected.push(id);
            var cells = row.find('td');
            regionsByRow[id] = cells.eq(5).text().trim(); // Region is 6th cell (index 5)
          }
        });

        $.post(moduleUrl, { action: 'save_flavors', server_id: serverId, selected_flavors: selected }, function(resp) {
          if (resp.success) {
            var tbody = $('#flavors-config-table tbody');
            tbody.empty();
            var namesToPersist = {}, regionsToPersist = {};
            $.each(selected, function(i, flvId) {
              var name   = bestFlvName(flavorNames, flvId);
              var region = regionsByRow[flvId] || flavorRegions[flvId] || loadedFlavorRegions[flvId] || '';
              var price  = flavorPrices[flvId] || '0';
              namesToPersist[flvId]   = name;
              regionsToPersist[flvId] = region;
              tbody.append('<tr data-id="' + $('<span>').text(flvId).html() + '" data-region="' + $('<span>').text(region).html() + '">' +
                '<td>' + $('<span>').text(flvId).html() + '</td>' +
                '<td><input type="text" class="form-control input-sm flv-name" value="' + $('<span>').text(name).html() + '"></td>' +
                '<td>' + $('<span>').text(region || '—').html() + '</td>' +
                '<td><input type="number" step="0.01" min="0" class="form-control input-sm flv-price" value="' + $('<span>').text(price).html() + '"></td>' +
                '<td><button class="btn btn-xs btn-danger btn-remove-flavor">Remove</button></td>' +
                '</tr>');
            });
            $('#flavors-saved-section').show();
            $.post(moduleUrl, { action: 'save_flavor_names',   server_id: serverId, names:   namesToPersist   }, null, 'json');
            $.post(moduleUrl, { action: 'save_flavor_regions', server_id: serverId, regions: regionsToPersist }, null, 'json');
          }
        }, 'json');
      });
    }

    $(document).on('click', '.btn-remove-flavor', function() {
      $(this).closest('tr').remove();
    });

    $('#btn-save-flavor-config').on('click', function() {
      var names = {}, regions = {}, prices = {}, ids = [];
      $('#flavors-config-table tbody tr').each(function() {
        var id = $(this).data('id');
        ids.push(id);
        names[id]   = $(this).find('.flv-name').val();
        regions[id] = $(this).data('region') || '';   // read from data attr, not an input
        prices[id]  = $(this).find('.flv-price').val();
      });

      $.when(
        $.post(moduleUrl, { action: 'save_flavor_names',   server_id: serverId, names:   names   }, null, 'json'),
        $.post(moduleUrl, { action: 'save_flavor_regions', server_id: serverId, regions: regions }, null, 'json'),
        $.post(moduleUrl, { action: 'save_flavor_prices',  server_id: serverId, prices:  prices  }, null, 'json'),
        $.post(moduleUrl, { action: 'save_flavors',        server_id: serverId, selected_flavors: ids }, null, 'json')
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
 * Render the Projects tab.
 *
 * Allows admin to load Projects from the CMP API, select which
 * projects are available to customers, and assign display names.
 *
 * @param int    $serverId  Active server ID
 * @param string $moduleUrl Base module URL
 */
function cloudpe_cmp_admin_render_projects(int $serverId, string $moduleUrl): void
{
  $savedProjects  = cloudpe_cmp_admin_get_setting($serverId, 'selected_projects', []);
  $projectNames   = cloudpe_cmp_admin_get_setting($serverId, 'project_names', []);
  $projectRegions = cloudpe_cmp_admin_get_setting($serverId, 'project_regions', []);
  ?>
  <div class="cmp-section">
    <h4>Projects
      <button class="btn btn-sm btn-primary pull-right" id="btn-load-projects">
        <i class="fa fa-refresh"></i> Load from API
      </button>
    </h4>
    <p class="text-muted">Projects scope VM resources. Select the projects you want to expose to customers, set a friendly Display Name, and pick the Region each project is served from.</p>
    <div id="projects-loading" class="cmp-spinner"><i class="fa fa-spinner fa-spin"></i> Loading projects...</div>
    <div id="projects-error" class="alert alert-danger cmp-alert"></div>
    <div id="projects-container">
      <?php if (!empty($savedProjects)): ?>
      <div class="alert alert-info" style="display:block;">
        Showing previously saved selection. Click <strong>Load from API</strong> to refresh.
      </div>
      <?php endif; ?>
    </div>

    <div id="projects-saved-section" style="<?php echo empty($savedProjects) ? 'display:none;' : ''; ?>">
      <h4>Selected Projects Configuration</h4>
      <table class="table table-bordered cmp-resource-table" id="projects-config-table">
        <thead>
          <tr>
            <th>Project ID</th>
            <th>Display Name</th>
            <th>Region</th>
            <th>Action</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ((array)$savedProjects as $projId): ?>
          <tr data-id="<?php echo htmlspecialchars($projId); ?>">
            <td><?php echo htmlspecialchars($projId); ?></td>
            <td><input type="text" class="form-control input-sm proj-name"
                 value="<?php echo htmlspecialchars($projectNames[$projId] ?? $projId); ?>"></td>
            <td>
              <input type="text" class="form-control input-sm proj-region"
                     list="proj-region-list"
                     placeholder="Load from API for suggestions"
                     value="<?php echo htmlspecialchars($projectRegions[$projId] ?? ''); ?>">
            </td>
            <td><button class="btn btn-xs btn-danger btn-remove-project">Remove</button></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
      <datalist id="proj-region-list"></datalist>
      <button class="btn btn-success" id="btn-save-project-config">
        <i class="fa fa-save"></i> Save Project Configuration
      </button>
      <div id="projects-save-msg" class="cmp-alert"></div>
    </div>
  </div>

  <script>
  (function() {
    var serverId  = <?php echo $serverId; ?>;
    var moduleUrl = '<?php echo $moduleUrl; ?>';
    // Session caches for fallback values when rebuilding rows
    var loadedProjectNames   = {};
    var loadedProjectRegions = {};
    // Regions available for the region dropdowns - populated on Load
    var loadedRegions = [];

    // (regionOptionsHtml removed - region field is now a plain text input)

    $('#btn-load-projects').on('click', function() {
      $('#projects-loading').show();
      $('#projects-error').hide();
      $.post(moduleUrl, { action: 'load_projects', server_id: serverId }, function(resp) {
        $('#projects-loading').hide();
        if (!resp.success) {
          $('#projects-error').text(resp.error || 'Failed to load projects.').show();
          return;
        }
        loadedProjectNames   = {};
        loadedProjectRegions = {};
        $.each(resp.projects, function(i, p) {
          loadedProjectNames[p.id]   = p.name;
          loadedProjectRegions[p.id] = p.region || '';
        });
        loadedRegions = resp.regions || [];
        // Backfill region datalist for the already-saved applied table
        var dl = $('#proj-region-list').empty();
        loadedRegions.forEach(function(r) {
          dl.append('<option value="' + $('<span>').text(r.slug).html() + '">'
            + $('<span>').text(r.name).html() + '</option>');
        });
        renderProjectsTable(resp.projects);
      }, 'json').fail(function() {
        $('#projects-loading').hide();
        $('#projects-error').text('Request failed. Check server connectivity.').show();
      });
    });

    function renderProjectsTable(projects) {
      var savedProjects   = <?php echo json_encode((array)$savedProjects); ?>;
      var projectNames    = <?php echo json_encode((object)($projectNames ?: new stdClass())); ?>;
      var projectRegions  = <?php echo json_encode((object)($projectRegions ?: new stdClass())); ?>;

      if (!projects || projects.length === 0) {
        $('#projects-container').html('<div class="alert alert-warning">No projects returned by the API. You can still manually enter a Project ID in the server Access Hash field.</div>');
        return;
      }

      var html = '<table class="table table-bordered cmp-resource-table"><thead><tr>' +
        '<th><input type="checkbox" id="check-all-projects"> All</th>' +
        '<th>Project ID</th><th>Name</th><th>Region</th>' +
        '</tr></thead><tbody>';

      $.each(projects, function(i, p) {
        var checked = (savedProjects.indexOf(p.id) !== -1) ? 'checked' : '';
        // Preselect: admin-saved override > API-returned region value
        var currentRegion = projectRegions[p.id] || p.region || '';
        html += '<tr data-id="' + $('<span>').text(p.id).html() + '">' +
          '<td><input type="checkbox" class="proj-check" value="' + $('<span>').text(p.id).html() + '" ' + checked + '></td>' +
          '<td>' + $('<span>').text(p.id).html() + '</td>' +
          '<td>' + $('<span>').text(p.name).html() + '</td>' +
          '<td><input type="text" class="form-control input-sm proj-region-fetch" list="proj-region-list" placeholder="e.g. ap-south-1" value="' + $('<span>').text(currentRegion).html() + '"></td>' +
          '</tr>';
      });

      html += '</tbody></table>';
      html += '<button class="btn btn-primary" id="btn-apply-project-selection"><i class="fa fa-check"></i> Apply Selection</button>';

      $('#projects-container').html(html);

      $('#check-all-projects').on('change', function() {
        $('.proj-check').prop('checked', $(this).is(':checked'));
      });

      $('#btn-apply-project-selection').on('click', function() {
        var selected = [];
        // Collect region selection from the fetch table so it carries
        // into the applied config table on Apply.
        var regionsFromFetch = {};
        $('#projects-container tbody tr').each(function() {
          var row  = $(this);
          var pid  = row.data('id');
          var cb   = row.find('.proj-check');
          var rsel = row.find('.proj-region-fetch').val();
          if (cb.is(':checked')) {
            selected.push(pid);
            regionsFromFetch[pid] = rsel || '';
          }
        });

        $.post(moduleUrl, { action: 'save_projects', server_id: serverId, selected_projects: selected }, function(resp) {
          if (resp.success) {
            var tbody = $('#projects-config-table tbody');
            tbody.empty();
            var namesToPersist   = {};
            var regionsToPersist = {};
            $.each(selected, function(i, projId) {
              var name   = projectNames[projId] || loadedProjectNames[projId] || projId;
              var region = regionsFromFetch[projId] || projectRegions[projId] || loadedProjectRegions[projId] || '';
              namesToPersist[projId]   = name;
              regionsToPersist[projId] = region;
              tbody.append('<tr data-id="' + $('<span>').text(projId).html() + '">' +
                '<td>' + $('<span>').text(projId).html() + '</td>' +
                '<td><input type="text" class="form-control input-sm proj-name" value="' + $('<span>').text(name).html() + '"></td>' +
                '<td><input type="text" class="form-control input-sm proj-region" list="proj-region-list" value="' + $('<span>').text(region).html() + '"></td>' +
                '<td><button class="btn btn-xs btn-danger btn-remove-project">Remove</button></td>' +
                '</tr>');
            });
            $('#projects-saved-section').show();
            $.post(moduleUrl, { action: 'save_project_names',   server_id: serverId, names: namesToPersist },     null, 'json');
            $.post(moduleUrl, { action: 'save_project_regions', server_id: serverId, regions: regionsToPersist }, null, 'json');
          }
        }, 'json');
      });
    }

    $(document).on('click', '.btn-remove-project', function() {
      $(this).closest('tr').remove();
    });

    $('#btn-save-project-config').on('click', function() {
      var names = {}, regions = {}, ids = [];
      $('#projects-config-table tbody tr').each(function() {
        var id = $(this).data('id');
        ids.push(id);
        names[id]   = $(this).find('.proj-name').val();
        regions[id] = $(this).find('.proj-region').val();
      });

      $.when(
        $.post(moduleUrl, { action: 'save_project_names',   server_id: serverId, names: names },     null, 'json'),
        $.post(moduleUrl, { action: 'save_project_regions', server_id: serverId, regions: regions }, null, 'json'),
        $.post(moduleUrl, { action: 'save_projects',        server_id: serverId, selected_projects: ids }, null, 'json')
      ).done(function() {
        $('#projects-save-msg').text('Project configuration saved successfully.').addClass('alert alert-success').show();
        setTimeout(function() { $('#projects-save-msg').hide(); }, 3000);
      });
    });
  }());
  </script>
  <?php
}

/**
 * Render the Security Groups tab.
 *
 * Lets admin load security groups from the CMP API (scoped to the
 * server's configured project) and choose which ones are offered to
 * customers. Display Names default to the group name from the API.
 *
 * @param int    $serverId  Active server ID
 * @param string $moduleUrl Base module URL
 */
function cloudpe_cmp_admin_render_security_groups(int $serverId, string $moduleUrl): void
{
  $savedSgs = cloudpe_cmp_admin_get_setting($serverId, 'selected_security_groups', []);
  $sgNames  = cloudpe_cmp_admin_get_setting($serverId, 'security_group_names', []);
  ?>
  <div class="cmp-section">
    <h4>Security Groups
      <button class="btn btn-sm btn-primary pull-right" id="btn-load-sgs">
        <i class="fa fa-refresh"></i> Load from API
      </button>
    </h4>
    <p class="text-muted">Security groups define network firewall rules for the VM. Select the groups you want customers to be able to choose from.</p>
    <div id="sgs-loading" class="cmp-spinner"><i class="fa fa-spinner fa-spin"></i> Loading security groups...</div>
    <div id="sgs-error" class="alert alert-danger cmp-alert"></div>
    <div id="sgs-container">
      <?php if (!empty($savedSgs)): ?>
      <div class="alert alert-info" style="display:block;">
        Showing previously saved selection. Click <strong>Load from API</strong> to refresh.
      </div>
      <?php endif; ?>
    </div>

    <div id="sgs-saved-section" style="<?php echo empty($savedSgs) ? 'display:none;' : ''; ?>">
      <h4>Selected Security Groups Configuration</h4>
      <table class="table table-bordered cmp-resource-table" id="sgs-config-table">
        <thead>
          <tr>
            <th>Security Group ID</th>
            <th>Display Name</th>
            <th>Action</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ((array)$savedSgs as $sgId): ?>
          <tr data-id="<?php echo htmlspecialchars($sgId); ?>">
            <td><?php echo htmlspecialchars($sgId); ?></td>
            <td><input type="text" class="form-control input-sm sg-name"
                 value="<?php echo htmlspecialchars($sgNames[$sgId] ?? $sgId); ?>"></td>
            <td><button class="btn btn-xs btn-danger btn-remove-sg">Remove</button></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
      <button class="btn btn-success" id="btn-save-sg-config">
        <i class="fa fa-save"></i> Save Security Group Configuration
      </button>
      <div id="sgs-save-msg" class="cmp-alert"></div>
    </div>
  </div>

  <script>
  (function() {
    var serverId  = <?php echo $serverId; ?>;
    var moduleUrl = '<?php echo $moduleUrl; ?>';
    var loadedSgNames = {};

    $('#btn-load-sgs').on('click', function() {
      $('#sgs-loading').show();
      $('#sgs-error').hide();
      $.post(moduleUrl, { action: 'load_security_groups', server_id: serverId }, function(resp) {
        $('#sgs-loading').hide();
        if (!resp.success) {
          $('#sgs-error').text(resp.error || 'Failed to load security groups.').show();
          return;
        }
        loadedSgNames = {};
        $.each(resp.security_groups, function(i, sg) {
          loadedSgNames[sg.id] = sg.name;
        });
        renderSgsTable(resp.security_groups);
      }, 'json').fail(function() {
        $('#sgs-loading').hide();
        $('#sgs-error').text('Request failed. Check server connectivity.').show();
      });
    });

    function renderSgsTable(sgs) {
      var savedSgs = <?php echo json_encode((array)$savedSgs); ?>;
      var sgNames  = <?php echo json_encode((object)($sgNames ?: new stdClass())); ?>;

      if (!sgs || sgs.length === 0) {
        $('#sgs-container').html('<div class="alert alert-warning">No security groups found for this project.</div>');
        return;
      }

      var html = '<table class="table table-bordered cmp-resource-table"><thead><tr>' +
        '<th><input type="checkbox" id="check-all-sgs"> All</th>' +
        '<th>SG ID</th><th>Name</th><th>Description</th>' +
        '</tr></thead><tbody>';

      $.each(sgs, function(i, sg) {
        var checked = (savedSgs.indexOf(sg.id) !== -1) ? 'checked' : '';
        html += '<tr><td><input type="checkbox" class="sg-check" value="' + $('<span>').text(sg.id).html() + '" ' + checked + '></td>' +
          '<td>' + $('<span>').text(sg.id).html() + '</td>' +
          '<td>' + $('<span>').text(sg.name).html() + '</td>' +
          '<td>' + $('<span>').text(sg.description || '').html() + '</td></tr>';
      });

      html += '</tbody></table>';
      html += '<button class="btn btn-primary" id="btn-apply-sg-selection"><i class="fa fa-check"></i> Apply Selection</button>';

      $('#sgs-container').html(html);

      $('#check-all-sgs').on('change', function() {
        $('.sg-check').prop('checked', $(this).is(':checked'));
      });

      $('#btn-apply-sg-selection').on('click', function() {
        var selected = [];
        $('.sg-check:checked').each(function() { selected.push($(this).val()); });

        $.post(moduleUrl, { action: 'save_security_groups', server_id: serverId, selected_security_groups: selected }, function(resp) {
          if (resp.success) {
            var tbody = $('#sgs-config-table tbody');
            tbody.empty();
            var namesToPersist = {};
            $.each(selected, function(i, sgId) {
              var name = sgNames[sgId] || loadedSgNames[sgId] || sgId;
              namesToPersist[sgId] = name;
              tbody.append('<tr data-id="' + $('<span>').text(sgId).html() + '">' +
                '<td>' + $('<span>').text(sgId).html() + '</td>' +
                '<td><input type="text" class="form-control input-sm sg-name" value="' + $('<span>').text(name).html() + '"></td>' +
                '<td><button class="btn btn-xs btn-danger btn-remove-sg">Remove</button></td>' +
                '</tr>');
            });
            $('#sgs-saved-section').show();
            $.post(moduleUrl, { action: 'save_security_group_names', server_id: serverId, names: namesToPersist }, null, 'json');
          }
        }, 'json');
      });
    }

    $(document).on('click', '.btn-remove-sg', function() {
      $(this).closest('tr').remove();
    });

    $('#btn-save-sg-config').on('click', function() {
      var names = {}, ids = [];
      $('#sgs-config-table tbody tr').each(function() {
        var id = $(this).data('id');
        ids.push(id);
        names[id] = $(this).find('.sg-name').val();
      });

      $.when(
        $.post(moduleUrl, { action: 'save_security_group_names', server_id: serverId, names: names }, null, 'json'),
        $.post(moduleUrl, { action: 'save_security_groups',      server_id: serverId, selected_security_groups: ids }, null, 'json')
      ).done(function() {
        $('#sgs-save-msg').text('Security group configuration saved successfully.').addClass('alert alert-success').show();
        setTimeout(function() { $('#sgs-save-msg').hide(); }, 3000);
      });
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
        msg.removeClass('alert-success alert-danger').empty();
        // Render per-file diagnostics so silent copy/opcache issues
        // surface directly in the UI.
        var detail = '';
        if (resp.stats) {
          detail = ' (' + resp.stats.files_written + ' written, '
            + resp.stats.files_failed + ' failed, '
            + resp.stats.opcache_invalidated + ' opcache cleared)';
          if (resp.stats.failures && resp.stats.failures.length) {
            detail += '<br><small>' + resp.stats.failures.slice(0, 5).map(function(f){
              return $('<span>').text(f).html();
            }).join('<br>') + '</small>';
          }
        }
        if (resp.success) {
          msg.html($('<span>').text(resp.message).html() + detail)
             .addClass('alert alert-success').show();
        } else {
          msg.html($('<span>').text(resp.message || 'Update failed.').html() + detail)
             .addClass('alert alert-danger').show();
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

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

define('CLOUDPE_CMP_MODULE_VERSION', '1.2.0');
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
    return ['success' => false, 'error' => $error ?: "HTTP $httpCode", 'releases' => []];
  }

  $raw = json_decode($body, true);
  if (!is_array($raw)) {
    return ['success' => false, 'error' => 'Invalid response from GitHub', 'releases' => []];
  }

  // Normalise raw GitHub API objects into the shape the JS accordion
  // expects: version, tag, name, body, published_at, download_url,
  // html_url, prerelease.  This mirrors the cloudpe-whmcs reference.
  $formatted = [];
  foreach ($raw as $release) {
    // Find the ZIP asset
    $downloadUrl = '';
    foreach ($release['assets'] ?? [] as $asset) {
      if (stripos($asset['name'], '.zip') !== false) {
        $downloadUrl = $asset['browser_download_url'] ?? '';
        break;
      }
    }

    $formatted[] = [
      'version'      => ltrim($release['tag_name'] ?? '', 'v'),
      'tag'          => $release['tag_name'] ?? '',
      'name'         => $release['name'] ?? '',
      'body'         => $release['body'] ?? '',
      'published_at' => isset($release['published_at'])
                        ? date('Y-m-d H:i', strtotime($release['published_at']))
                        : '',
      'download_url' => $downloadUrl,
      'html_url'     => $release['html_url'] ?? '',
      'prerelease'   => (bool)($release['prerelease'] ?? false),
    ];
  }

  return [
    'success'         => true,
    'releases'        => $formatted,
    'current_version' => CLOUDPE_CMP_MODULE_VERSION,
  ];
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
      'success'         => false,
      'current_version' => CLOUDPE_CMP_MODULE_VERSION,
      'latest_version'  => null,
      'update_available'=> false,
      'error'           => $error ?: "HTTP $httpCode",
    ];
  }

  $data = json_decode($body, true);
  if (!is_array($data) || empty($data['version'])) {
    return [
      'success'         => false,
      'current_version' => CLOUDPE_CMP_MODULE_VERSION,
      'latest_version'  => null,
      'update_available'=> false,
      'error'           => 'Invalid version manifest received from update server.',
    ];
  }

  return [
    'success'         => true,
    'current_version' => CLOUDPE_CMP_MODULE_VERSION,
    'latest_version'  => $data['version'],
    'download_url'    => $data['download_url'] ?? '',
    'update_available'=> version_compare($data['version'], CLOUDPE_CMP_MODULE_VERSION, '>'),
    'changelog'       => $data['changelog'] ?? [],
    'released'        => $data['released'] ?? '',
  ];
}

/**
 * Fetch a URL and return its body as a string.
 *
 * Tries CURLOPT_FOLLOWLOCATION first (fastest). On servers where
 * open_basedir disables FOLLOWLOCATION, PHP silently ignores the option and
 * cURL returns the raw 3xx response. We detect that and fall back to a
 * manual redirect loop (up to 10 hops) so GitHub release asset URLs —
 * which redirect github.com → objects.githubusercontent.com — always work.
 *
 * Reference params are untyped to avoid PHP 8 TypeError when callers pass
 * uninitialised variables (typed int/string refs require the variable to
 * already hold the correct type in strict mode).
 *
 * @param string $url       URL to fetch
 * @param mixed  &$httpCode Final HTTP status code (by reference)
 * @param mixed  &$error    cURL error string, empty on success (by ref)
 * @param mixed  &$finalUrl Effective URL after all redirects (by ref)
 * @return string|false  Response body, or false on failure
 */
function cloudpe_cmp_admin_download_url(
  string $url,
         &$httpCode,
         &$error,
         &$finalUrl
) {
  $ua      = 'CloudPe-CMP-WHMCS/' . CLOUDPE_CMP_MODULE_VERSION;
  $maxHops = 10;

  // --- attempt 1: standard FOLLOWLOCATION ---
  $ch = curl_init($url);
  curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => 120,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_MAXREDIRS      => $maxHops,
    CURLOPT_USERAGENT      => $ua,
    CURLOPT_HTTPHEADER     => ['Accept: application/octet-stream'],
  ]);
  $body     = curl_exec($ch);
  $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
  $finalUrl = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL) ?: $url;
  $error    = curl_error($ch);
  curl_close($ch);

  // Success or a non-redirect error — return as-is.
  if ($httpCode === 200 || ($httpCode < 300 && $httpCode > 0)) {
    return $body;
  }

  // --- attempt 2: manual redirect loop (handles open_basedir restrictions) ---
  if ($httpCode >= 300 && $httpCode < 400) {
    $currentUrl = $url;
    for ($hop = 0; $hop < $maxHops; $hop++) {
      $ch2 = curl_init($currentUrl);
      curl_setopt_array($ch2, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 120,
        CURLOPT_FOLLOWLOCATION => false, // manual — one hop at a time
        CURLOPT_HEADER         => true,  // capture response headers
        CURLOPT_USERAGENT      => $ua,
        CURLOPT_HTTPHEADER     => ['Accept: application/octet-stream'],
      ]);
      $raw      = curl_exec($ch2);
      $httpCode = (int)curl_getinfo($ch2, CURLINFO_HTTP_CODE);
      $error    = curl_error($ch2);
      $headerSize = curl_getinfo($ch2, CURLINFO_HEADER_SIZE);
      curl_close($ch2);

      if ($error) {
        return false;
      }

      $responseBody    = substr($raw, $headerSize);
      $responseHeaders = substr($raw, 0, $headerSize);

      if ($httpCode === 200) {
        $finalUrl = $currentUrl;
        return $responseBody;
      }

      if ($httpCode >= 300 && $httpCode < 400) {
        // Extract Location header
        if (preg_match('/^Location:\s*(.+)$/im', $responseHeaders, $m)) {
          $location = trim($m[1]);
          // Handle relative redirect (rare for GitHub but be safe)
          if (strpos($location, 'http') !== 0) {
            $parts    = parse_url($currentUrl);
            $location = ($parts['scheme'] ?? 'https') . '://' . ($parts['host'] ?? '') . $location;
          }
          $currentUrl = $location;
          continue;
        }
      }

      // Non-redirect, non-success — give up
      return false;
    }
  }

  return false;
}

/**
 * Install a module ZIP that already exists on disk (e.g. from an upload).
 *
 * Shared entry point used by both the auto-download path and the manual
 * upload path so the extract + copy logic lives in exactly one place.
 *
 * @param string $zipPath Absolute path to the ZIP file on the server
 * @return array  Keys: success (bool), message (string), stats (array)
 */
function cloudpe_cmp_admin_install_from_file(string $zipPath): array
{
  @set_time_limit(300);
  @ignore_user_abort(true);

  $tmpDir = sys_get_temp_dir() . '/cloudpe_cmp_update_' . time();
  $stats  = ['files_written' => 0, 'files_failed' => 0, 'opcache_invalidated' => 0, 'failures' => []];

  try {
    if (!file_exists($zipPath)) {
      throw new \Exception('ZIP file not found: ' . $zipPath);
    }

    $zip = new ZipArchive();
    if ($zip->open($zipPath) !== true) {
      throw new \Exception('Failed to open ZIP archive. The file may be corrupt.');
    }
    mkdir($tmpDir, 0755, true);
    $zip->extractTo($tmpDir);
    $zip->close();

    // Locate modules/ directory inside the extracted archive
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

    if (!defined('ROOTDIR')) {
      throw new \Exception('ROOTDIR is not defined — run this from inside WHMCS.');
    }
    $whmcsRoot = ROOTDIR;
    if (!file_exists($whmcsRoot . '/init.php') && !file_exists($whmcsRoot . '/configuration.php')) {
      throw new \Exception('Refusing to install: ROOTDIR (' . $whmcsRoot . ') does not look like a WHMCS root.');
    }

    $dstServer = $whmcsRoot . '/modules/servers/cloudpe_cmp';
    $dstAddon  = $whmcsRoot . '/modules/addons/cloudpe_cmp_admin';

    // Backup current module before overwriting
    $backupDir = $whmcsRoot . '/modules/cloudpe_cmp_backup_' . date('YmdHis');
    $backupStats = [];
    if (is_dir($dstServer)) cloudpe_cmp_admin_copy_directory($dstServer, $backupDir . '/servers/cloudpe_cmp',   $backupStats);
    if (is_dir($dstAddon))  cloudpe_cmp_admin_copy_directory($dstAddon,  $backupDir . '/addons/cloudpe_cmp_admin', $backupStats);

    $srcServer = $modulesRoot . '/servers/cloudpe_cmp';
    $srcAddon  = $modulesRoot . '/addons/cloudpe_cmp_admin';
    if (is_dir($srcServer)) cloudpe_cmp_admin_copy_directory($srcServer, $dstServer, $stats);
    if (is_dir($srcAddon))  cloudpe_cmp_admin_copy_directory($srcAddon,  $dstAddon,  $stats);

    $stats['backup_path'] = $backupDir;

    if (function_exists('opcache_reset')) @opcache_reset();

    if ($stats['files_failed'] > 0) {
      return [
        'success' => false,
        'message' => 'Update partially failed. ' . $stats['files_written'] . ' written, ' . $stats['files_failed'] . ' failed. First failure: ' . ($stats['failures'][0] ?? 'unknown'),
        'stats'   => $stats,
      ];
    }

    return [
      'success' => true,
      'message' => 'Module installed successfully (' . $stats['files_written'] . ' files written, ' . $stats['opcache_invalidated'] . ' opcache entries cleared).',
      'stats'   => $stats,
    ];
  } catch (\Throwable $e) {
    return ['success' => false, 'message' => $e->getMessage(), 'stats' => $stats];
  } finally {
    cloudpe_cmp_admin_cleanup_temp($tmpDir);
  }
}

/**
 * Download a release ZIP and install both module directories.
 *
 * @param string $downloadUrl Direct ZIP download URL
 * @return array  Keys: success (bool), message (string)
 */
function cloudpe_cmp_admin_install_update(string $downloadUrl): array
{
  // Give the download + extract + copy up to 5 minutes regardless of
  // the server's default max_execution_time (often 30s on shared hosts).
  @set_time_limit(300);
  // Keep running even if the browser disconnects mid-install so the
  // copy step always completes and we don't leave a half-written module.
  @ignore_user_abort(true);

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
    // Download ZIP then delegate extract+install to install_from_file().
    $dlHttpCode = 0;
    $dlError    = '';
    $dlFinalUrl = '';
    $zipContent = cloudpe_cmp_admin_download_url($downloadUrl, $dlHttpCode, $dlError, $dlFinalUrl);

    if ($dlError || $dlHttpCode !== 200 || !$zipContent) {
      return [
        'success' => false,
        'message' => 'Download failed: HTTP ' . $dlHttpCode
          . ($dlFinalUrl && $dlFinalUrl !== $downloadUrl ? ' (redirected to: ' . $dlFinalUrl . ')' : '')
          . ($dlError ? ' — ' . $dlError : '')
          . '. Use the Manual Install option below to upload the ZIP directly.',
        'stats'   => $stats,
      ];
    }

    if (!file_put_contents($tmpFile, $zipContent)) {
      return ['success' => false, 'message' => 'Failed to write download to temp file.', 'stats' => $stats];
    }
    unset($zipContent);

    return cloudpe_cmp_admin_install_from_file($tmpFile);

  } catch (\Throwable $e) {
    return ['success' => false, 'message' => $e->getMessage(), 'stats' => $stats];
  } finally {
    if (file_exists($tmpFile)) @unlink($tmpFile);
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
/**
 * Sanitize HTML entities and Unicode quote variants so JSON-encoded settings
 * don't end up with curly/smart quotes that break json_decode().
 */
function cloudpe_cmp_admin_sanitize_quotes(?string $value): ?string
{
  if ($value === null || $value === '') {
    return $value;
  }
  $value = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
  $doubleQuotes = [
    "\xE2\x80\x9C", "\xE2\x80\x9D", "\xE2\x80\x9E", "\xE2\x80\x9F",
    "\xE2\x80\xB3", "\xE2\x80\xB6", "\xEF\xBC\x82",
    "\xC2\xAB", "\xC2\xBB",
  ];
  $singleQuotes = [
    "\xE2\x80\x98", "\xE2\x80\x99", "\xE2\x80\x9A", "\xE2\x80\x9B",
    "\xE2\x80\xB2", "\xE2\x80\xB5", "\xEF\xBC\x87",
    "\xE2\x80\xB9", "\xE2\x80\xBA",
    "\xC2\x91", "\xC2\x92",
  ];
  $value = str_replace($doubleQuotes, '"', $value);
  $value = str_replace($singleQuotes, "'", $value);
  return $value;
}

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
      $msg = $result['error'] ?? 'Failed to load images.';
      if (!empty($result['httpCode'])) {
        $msg = 'HTTP ' . $result['httpCode'] . ': ' . $msg;
      }
      return ['success' => false, 'error' => $msg];
    }

    // Match reference/cloudpe-cmp-create-vm.php filter:
    //   keep images where group_id is set AND is_active === true
    // Value is openstack_id (what create_instance resolves by), grouped by group_name.
    $images = [];
    foreach ((array)($result['images'] ?? []) as $img) {
      if (empty($img['group_id']) || ($img['is_active'] ?? false) !== true) {
        continue;
      }
      $id = $img['openstack_id'] ?? $img['slug'] ?? $img['id'] ?? '';
      if ($id === '') continue;
      $name = $img['name']
           ?? $img['display_name']
           ?? $img['os_name']
           ?? $img['label']
           ?? $img['title']
           ?? $id;
      $images[] = [
        'id'         => $id,
        'name'       => $name,
        'group_name' => $img['group_name'] ?? '',
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
    $result = $api->listFlavors(false);

    if (!$result['success']) {
      $msg = $result['error'] ?? 'Failed to load flavors.';
      if (!empty($result['httpCode'])) {
        $msg = 'HTTP ' . $result['httpCode'] . ': ' . $msg;
      }
      return ['success' => false, 'error' => $msg];
    }

    // Match reference/cloudpe-cmp-create-vm.php:
    //   value = openstack_id (what create_instance resolves by), grouped by flavor_group_name.
    $flavors = [];
    foreach ((array)($result['flavors'] ?? []) as $flv) {
      $id = $flv['openstack_id'] ?? $flv['slug'] ?? $flv['id'] ?? '';
      if ($id === '') continue;
      $ramMb = $flv['ram_mb'] ?? $flv['memory_mb'] ?? $flv['ram'] ?? null;
      $ramGb = $flv['memory_gb'] ?? $flv['ram_gb'] ?? $flv['memory'] ?? null;
      $memoryGb = 0;
      if ($ramMb !== null && (float)$ramMb > 0) {
        $memoryGb = round((float)$ramMb / 1024, 1);
      } elseif ($ramGb !== null) {
        $memoryGb = (float)$ramGb;
      }
      $flavors[] = [
        'id'                 => $id,
        'name'               => $flv['name'] ?? $flv['display_name'] ?? $id,
        'vcpu'               => (int)($flv['vcpus'] ?? $flv['vcpu'] ?? $flv['cpu'] ?? 0),
        'memory_gb'          => $memoryGb,
        'group_name'         => $flv['flavor_group_name'] ?? $flv['group_name'] ?? '',
        'price_monthly_inr'  => isset($flv['price_monthly_inr']) ? (float)$flv['price_monthly_inr'] : null,
        'price_monthly_usd'  => isset($flv['price_monthly_usd']) ? (float)$flv['price_monthly_usd'] : null,
      ];
    }

    return ['success' => true, 'flavors' => $flavors];
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
      $projects[] = [
        'id'   => $proj['id'] ?? $proj['uuid'] ?? '',
        'name' => $proj['name'] ?? $proj['display_name'] ?? $proj['id'] ?? '',
      ];
    }

    return ['success' => true, 'projects' => $projects];
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
function cloudpe_cmp_admin_load_security_groups(int $serverId, string $projectId = ''): array
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

  // Use explicit param, fall back to server Access Hash
  if (empty($projectId)) {
    $projectId = trim($server->accesshash ?? '');
  }
  if (empty($projectId)) {
    return ['success' => false, 'error' => 'No project ID provided. Select a project from the dropdown or set the Access Hash on the server.'];
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
 * Load available volume types from the CMP API.
 *
 * @param int $serverId WHMCS server ID
 * @return array  Keys: success (bool), volume_types (array)|error (string)
 */
function cloudpe_cmp_admin_load_volume_types(int $serverId): array
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
    $result = $api->listVolumeTypes();

    if (!$result['success']) {
      return ['success' => false, 'error' => $result['error'] ?? 'Failed to load volume types.'];
    }

    $types = [];
    foreach ((array)($result['volume_types'] ?? []) as $vt) {
      $id   = $vt['id'] ?? $vt['slug'] ?? $vt['name'] ?? '';
      $name = $vt['name'] ?? $vt['display_name'] ?? $id;
      if (!$id) continue;
      $types[] = ['id' => $id, 'name' => $name];
    }

    return ['success' => true, 'volume_types' => $types];
  } catch (\Exception $e) {
    return ['success' => false, 'error' => $e->getMessage()];
  }
}

/**
 * Load flavor groups for the server's region.
 *
 * @param int $serverId WHMCS server ID
 * @return array Keys: success (bool), groups (array)|error (string)
 */
function cloudpe_cmp_admin_load_flavor_groups(int $serverId): array
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
    $result = $api->listFlavorGroups();

    if (!$result['success']) {
      return ['success' => false, 'error' => $result['error'] ?? 'Failed to load flavor groups.'];
    }

    $groups = [];
    foreach ((array)($result['groups'] ?? []) as $g) {
      $slug = $g['slug'] ?? $g['id'] ?? '';
      if (!$slug) continue;
      $groups[] = ['slug' => $slug, 'name' => $g['name'] ?? $g['display_name'] ?? $slug];
    }

    return ['success' => true, 'groups' => $groups];
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

  // Products to link
  $productIds = [];
  $rawProducts = $params['products'] ?? [];
  if (is_array($rawProducts)) {
    foreach ($rawProducts as $pid) {
      $pid = (int)$pid;
      if ($pid > 0) $productIds[] = $pid;
    }
  }
  if (empty($productIds)) {
    return ['success' => false, 'message' => 'At least one product must be selected.'];
  }

  $includeOs   = !empty($params['include_os']);
  $includeSize = !empty($params['include_size']);
  $includeDisk = !empty($params['include_disk']);
  if (!$includeOs && !$includeSize && !$includeDisk) {
    return ['success' => false, 'message' => 'At least one option type (OS, Size, or Disk) must be selected.'];
  }

  $multQ = (float)($params['mult_q'] ?? 3);
  $multS = (float)($params['mult_s'] ?? 6);
  $multA = (float)($params['mult_a'] ?? 12);
  $multB = (float)($params['mult_b'] ?? 24);
  $multT = (float)($params['mult_t'] ?? 36);

  // Load saved images
  $savedImages  = cloudpe_cmp_admin_get_setting($serverId, 'selected_images', []);
  $imageNames   = cloudpe_cmp_admin_get_setting($serverId, 'image_names', []);
  $imagePrices  = cloudpe_cmp_admin_get_setting($serverId, 'image_prices', []);

  // Load saved flavors
  $savedFlavors  = cloudpe_cmp_admin_get_setting($serverId, 'selected_flavors', []);
  $flavorNames   = cloudpe_cmp_admin_get_setting($serverId, 'flavor_names', []);
  $flavorPrices  = cloudpe_cmp_admin_get_setting($serverId, 'flavor_prices', []);

  // Load saved disk sizes
  $savedDisks   = cloudpe_cmp_admin_get_setting($serverId, 'disk_sizes', []);

  if (empty($savedImages) && empty($savedFlavors) && empty($savedDisks)) {
    return ['success' => false, 'message' => 'No resources configured. Please configure images, flavors, and disk sizes first.'];
  }

  // Fallback: if any saved Display Name is empty or equal to the ID, do a live API
  // lookup so the configurable options get friendly labels instead of raw UUIDs.
  $needImageLookup  = cloudpe_cmp_admin_needs_name_lookup($savedImages, $imageNames);
  $needFlavorLookup = cloudpe_cmp_admin_needs_name_lookup($savedFlavors, $flavorNames);
  if ($needImageLookup || $needFlavorLookup) {
    if ($needImageLookup) {
      $live = cloudpe_cmp_admin_load_images($serverId);
      if (!empty($live['success']) && !empty($live['images'])) {
        foreach ($live['images'] as $img) {
          $id = $img['id'] ?? '';
          if (!$id) continue;
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
          // Default to the spec-style name so customers see
          // "2 vCPU, 4 GB RAM" instead of a raw UUID when the admin
          // didn't set one.
          if (empty($flavorNames[$id]) || $flavorNames[$id] === $id) {
            $vcpu = (int)($flv['vcpu'] ?? 0);
            $ram  = (float)($flv['memory_gb'] ?? 0);
            $flavorNames[$id] = ($vcpu > 0 || $ram > 0)
              ? $vcpu . ' vCPU, ' . $ram . ' GB RAM'
              : ($flv['name'] ?? $id);
          }
        }
      }
    }
    if ($needImageLookup)  cloudpe_cmp_admin_save_setting($serverId, 'image_names', $imageNames);
    if ($needFlavorLookup) cloudpe_cmp_admin_save_setting($serverId, 'flavor_names', $flavorNames);
  }

  // Customer-facing display names for flavors on the cart page.
  $flavorDisplayNames = [];
  foreach ((array)$savedFlavors as $flavorId) {
    $flavorDisplayNames[$flavorId] = $flavorNames[$flavorId] ?? $flavorId;
  }

  try {
    // Create the configurable options group
    $groupId = Capsule::table('tblproductconfiggroups')->insertGetId([
      'name'        => $groupName,
      'description' => 'Auto-generated by CloudPe CMP Manager',
    ]);

    $sortOrder = 0;

    // --- Operating System ---
    if ($includeOs && !empty($savedImages)) {
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
        $stored      = $imagePrices[$imageId] ?? 0;

        $subId = Capsule::table('tblproductconfigoptionssub')->insertGetId([
          'configid'  => $osOptionId,
          'optionname'=> $imageId . '|' . $displayName,
          'sortorder' => $osSubOrder++,
          'hidden'    => 0,
        ]);

        // Insert pricing (all currencies at once via tblpricing)
        $currencies = Capsule::table('tblcurrencies')->get();
        foreach ($currencies as $currency) {
          $price = is_array($stored)
            ? (float)($stored[$currency->id] ?? ($stored[(string)$currency->id] ?? 0))
            : (float)$stored; // legacy scalar
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
            'monthly'      => $price,
            'quarterly'    => $price * $multQ,
            'semiannually' => $price * $multS,
            'annually'     => $price * $multA,
            'biennially'   => $price * $multB,
            'triennially'  => $price * $multT,
          ]);
        }
      }
    }

    // --- Server Size ---
    if ($includeSize && !empty($savedFlavors)) {
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
        $displayName = $flavorDisplayNames[$flavorId] ?? $flavorNames[$flavorId] ?? $flavorId;
        $stored      = $flavorPrices[$flavorId] ?? 0;

        $subId = Capsule::table('tblproductconfigoptionssub')->insertGetId([
          'configid'  => $sizeOptionId,
          'optionname'=> $flavorId . '|' . $displayName,
          'sortorder' => $sizeSubOrder++,
          'hidden'    => 0,
        ]);

        $currencies = Capsule::table('tblcurrencies')->get();
        foreach ($currencies as $currency) {
          $price = is_array($stored)
            ? (float)($stored[$currency->id] ?? ($stored[(string)$currency->id] ?? 0))
            : (float)$stored; // legacy scalar
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
            'quarterly'    => $price * $multQ,
            'semiannually' => $price * $multS,
            'annually'     => $price * $multA,
            'biennially'   => $price * $multB,
            'triennially'  => $price * $multT,
          ]);
        }
      }
    }

    // --- Disk Space ---
    if ($includeDisk && !empty($savedDisks)) {
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
        $diskSizeGb = (int)($disk['size_gb'] ?? 0);
        $diskLabel  = $disk['label'] ?? ($diskSizeGb . ' GB');
        $priceMap   = $disk['prices'] ?? null;
        $legacy     = (float)($disk['price'] ?? 0);

        if (!$diskSizeGb) {
          continue;
        }

        $subId = Capsule::table('tblproductconfigoptionssub')->insertGetId([
          'configid'  => $diskOptionId,
          'optionname'=> $diskSizeGb . '|' . $diskLabel,
          'sortorder' => $diskSubOrder++,
          'hidden'    => 0,
        ]);

        $currencies = Capsule::table('tblcurrencies')->get();
        foreach ($currencies as $currency) {
          $diskPrice = is_array($priceMap)
            ? (float)($priceMap[$currency->id] ?? $priceMap[(string)$currency->id] ?? 0)
            : $legacy;
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
            'quarterly'    => $diskPrice * $multQ,
            'semiannually' => $diskPrice * $multS,
            'annually'     => $diskPrice * $multA,
            'biennially'   => $diskPrice * $multB,
            'triennially'  => $diskPrice * $multT,
          ]);
        }
      }
    }

    // Link the new group to the products the admin selected.
    $assignedCount = 0;
    foreach ($productIds as $pid) {
      $exists = Capsule::table('tblproductconfiglinks')
        ->where('gid', $groupId)->where('pid', $pid)->exists();
      if (!$exists) {
        Capsule::table('tblproductconfiglinks')->insert([
          'gid' => $groupId,
          'pid' => $pid,
        ]);
        $assignedCount++;
      }
    }

    $msg = 'Configurable options group "' . $groupName . '" created successfully. Linked to ' . $assignedCount . ' product(s).';

    return [
      'success'  => true,
      'message'  => $msg,
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
          // Buffer any stray PHP warnings/notices so they cannot
          // corrupt the JSON response and trigger jQuery's error callback.
          ob_start();
          $installResult = cloudpe_cmp_admin_install_update($downloadUrl);
          ob_end_clean();
          echo json_encode($installResult);
        }
        exit;

      case 'install_from_upload':
        // Manual ZIP upload path — works when the server cannot reach GitHub
        // (firewall, proxy timeout, etc.). The client uploads the ZIP directly.
        if (empty($_FILES['module_zip']['tmp_name'])) {
          echo json_encode(['success' => false, 'message' => 'No file received. Check upload_max_filesize and post_max_size in php.ini.']);
          exit;
        }
        $uploadErr = $_FILES['module_zip']['error'] ?? UPLOAD_ERR_NO_FILE;
        if ($uploadErr !== UPLOAD_ERR_OK) {
          $uploadMessages = [
            UPLOAD_ERR_INI_SIZE   => 'File exceeds upload_max_filesize.',
            UPLOAD_ERR_FORM_SIZE  => 'File exceeds MAX_FILE_SIZE.',
            UPLOAD_ERR_PARTIAL    => 'File was only partially uploaded.',
            UPLOAD_ERR_NO_FILE    => 'No file was uploaded.',
            UPLOAD_ERR_NO_TMP_DIR => 'Temporary folder missing.',
            UPLOAD_ERR_CANT_WRITE => 'Failed to write file to disk.',
            UPLOAD_ERR_EXTENSION  => 'Upload blocked by PHP extension.',
          ];
          echo json_encode(['success' => false, 'message' => $uploadMessages[$uploadErr] ?? "Upload error code $uploadErr."]);
          exit;
        }
        ob_start();
        $uploadResult = cloudpe_cmp_admin_install_from_file($_FILES['module_zip']['tmp_name']);
        ob_end_clean();
        echo json_encode($uploadResult);
        exit;

      case 'get_releases':
        // cloudpe_cmp_admin_get_all_releases() already returns
        // {success, releases:[...], current_version} — echo it directly
        // instead of wrapping again (wrapping caused data.releases to be
        // the inner object, not the array, so release.version was always
        // undefined and version tags showed as bare "v").
        echo json_encode(cloudpe_cmp_admin_get_all_releases());
        exit;

      case 'load_images':
        echo json_encode(cloudpe_cmp_admin_load_images($serverId));
        exit;

      case 'load_flavors':
        echo json_encode(cloudpe_cmp_admin_load_flavors($serverId));
        exit;

      case 'load_projects':
        echo json_encode(cloudpe_cmp_admin_load_projects($serverId));
        exit;

      case 'load_security_groups':
        $sgProjectId = trim($_POST['project_id'] ?? '');
        echo json_encode(cloudpe_cmp_admin_load_security_groups($serverId, $sgProjectId));
        exit;

      case 'load_volume_types':
        echo json_encode(cloudpe_cmp_admin_load_volume_types($serverId));
        exit;

      case 'load_flavor_groups':
        echo json_encode(cloudpe_cmp_admin_load_flavor_groups($serverId));
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

      case 'save_flavor_specs':
        $specs = $_POST['specs'] ?? [];
        $existing = cloudpe_cmp_admin_get_setting($serverId, 'flavor_specs', []) ?: [];
        cloudpe_cmp_admin_save_setting($serverId, 'flavor_specs', array_merge((array)$existing, (array)$specs));
        echo json_encode(['success' => true]);
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

      case 'save_flavor_api_names':
        $apiNames = $_POST['api_names'] ?? [];
        $existing = cloudpe_cmp_admin_get_setting($serverId, 'flavor_api_names', []) ?: [];
        cloudpe_cmp_admin_save_setting($serverId, 'flavor_api_names', array_merge((array)$existing, (array)$apiNames));
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

      case 'save_flavor_groups':
        $groups = $_POST['flavor_groups'] ?? [];
        cloudpe_cmp_admin_save_setting($serverId, 'flavor_groups', $groups);
        echo json_encode(['success' => true]);
        exit;

      case 'save_volume_types':
        $selectedTypes = $_POST['selected_volume_types'] ?? [];
        cloudpe_cmp_admin_save_setting($serverId, 'selected_volume_types', $selectedTypes);
        echo json_encode(['success' => true, 'message' => 'Volume type selection saved.']);
        exit;

      case 'save_volume_type_names':
        $names = $_POST['names'] ?? [];
        cloudpe_cmp_admin_save_setting($serverId, 'volume_type_names', $names);
        echo json_encode(['success' => true, 'message' => 'Volume type names saved.']);
        exit;

      case 'create_config_group':
        $result = cloudpe_cmp_admin_create_config_group([
          'server_id'    => $serverId,
          'group_name'   => trim($_POST['group_name'] ?? 'CloudPe CMP Options'),
          'products'     => $_POST['products']     ?? [],
          'include_os'   => $_POST['include_os']   ?? 0,
          'include_size' => $_POST['include_size'] ?? 0,
          'include_disk' => $_POST['include_disk'] ?? 0,
          'mult_q'       => $_POST['mult_q']       ?? 3,
          'mult_s'       => $_POST['mult_s']       ?? 6,
          'mult_a'       => $_POST['mult_a']       ?? 12,
          'mult_b'       => $_POST['mult_b']       ?? 24,
          'mult_t'       => $_POST['mult_t']       ?? 36,
        ]);
        echo json_encode($result);
        exit;

      case 'repair_settings':
        if (!$serverId) {
          echo json_encode(['success' => false, 'error' => 'No server selected.']);
          exit;
        }
        $rows = Capsule::table('mod_cloudpe_cmp_settings')->where('server_id', $serverId)->get();
        $repaired = [];
        $errors   = [];
        foreach ($rows as $s) {
          $original  = $s->setting_value;
          $sanitized = cloudpe_cmp_admin_sanitize_quotes($original);
          if ($original !== $sanitized) {
            try {
              Capsule::table('mod_cloudpe_cmp_settings')
                ->where('id', $s->id)
                ->update(['setting_value' => $sanitized, 'updated_at' => date('Y-m-d H:i:s')]);
              $repaired[] = [
                'key'          => $s->setting_key,
                'before_valid' => json_decode((string)$original,  true) !== null,
                'after_valid'  => json_decode((string)$sanitized, true) !== null,
              ];
            } catch (\Exception $e) {
              $errors[] = ['key' => $s->setting_key, 'error' => $e->getMessage()];
            }
          }
        }
        echo json_encode([
          'success'        => true,
          'repaired_count' => count($repaired),
          'repaired'       => $repaired,
          'errors'         => $errors,
        ]);
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
    /* Scrollable table with sticky header */
    .cmp-table-wrap { max-height: calc(100vh - 260px); overflow-y: auto; border: 1px solid #ddd; border-radius: 3px; margin-bottom: 15px; }
    .cmp-table-wrap > .table { margin-bottom: 0; border: none; }
    .cmp-table-wrap thead th { position: sticky; top: 0; background: #f5f5f5; z-index: 2; box-shadow: 0 1px 0 #ddd; }
    /* Resource tab toolbar */
    .cmp-toolbar { display: flex; align-items: center; gap: 8px; margin-bottom: 10px; flex-wrap: nowrap; }
    .cmp-toolbar .cmp-search { flex: 1; min-width: 140px; }
    .cmp-toolbar .cmp-spacer { flex: 1; }
    .cmp-toolbar .cmp-save-msg { white-space: nowrap; font-weight: bold; color: #3c763d; }
    .cmp-toolbar .cmp-save-msg.error { color: #a94442; }
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
    <div id="cmp-server-selector" style="display:flex; align-items:center; gap:16px; flex-wrap:wrap;">
      <form method="get" style="display:flex; align-items:center; gap:8px;">
        <input type="hidden" name="module" value="cloudpe_cmp_admin">
        <input type="hidden" name="tab" value="<?php echo htmlspecialchars($activeTab); ?>">
        <label style="margin:0;"><strong>Server:</strong></label>
        <select name="server_id" class="form-control" style="width:auto;"
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
    <script>
    // Global state — shared across all tabs.
    window.cmpServerId  = <?php echo $serverId; ?>;
    window.cmpModuleUrl = '<?php echo $moduleUrl; ?>';
    </script>

    <!-- Tab navigation -->
    <ul class="nav nav-tabs" role="tablist">
      <?php
      $tabs = [
        'dashboard'       => 'Dashboard',
        'images'          => 'Images',
        'flavors'         => 'Flavors',
        'disks'           => 'Disk Sizes',
        'create_group'    => 'Config Groups',
        'updates'         => 'Updates',
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
      <tr><th>Secure</th><td><?php echo $server->secure ? 'Yes (HTTPS)' : 'No (HTTP)'; ?></td></tr>
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
 * Single unified table: saved items shown checked on load, Load from API
 * appends unchecked new items. Uncheck + confirm removes from selection.
 *
 * @param int    $serverId  Active server ID
 * @param string $moduleUrl Base module URL
 */
function cloudpe_cmp_admin_render_images(int $serverId, string $moduleUrl): void
{
  $savedImages  = (array)cloudpe_cmp_admin_get_setting($serverId, 'selected_images', []);
  $imageNames   = (array)cloudpe_cmp_admin_get_setting($serverId, 'image_names', []);
  $imagePrices  = (array)cloudpe_cmp_admin_get_setting($serverId, 'image_prices', []);
  $currencies   = Capsule::table('tblcurrencies')
    ->whereIn('code', ['INR', 'USD'])
    ->orderBy('id')
    ->get(['id', 'code']);
  $firstCurrId  = $currencies->count() ? (int)$currencies->first()->id : 0;
  ?>
  <div class="cmp-section">
    <h4>Images</h4>
    <div class="cmp-toolbar">
      <button class="btn btn-primary" id="btn-load-images">
        <i class="fa fa-refresh"></i> Load from API
      </button>
      <span id="images-loading" style="display:none;"><i class="fa fa-spinner fa-spin"></i> <span id="images-loading-text">Loading images...</span></span>
      <input type="text" id="images-search" class="form-control cmp-search" placeholder="Filter images...">
      <span id="images-saving" style="display:none;"><i class="fa fa-spinner fa-spin"></i> <span id="images-saving-text">Saving configuration...</span></span>
      <span id="images-save-msg" class="cmp-save-msg" style="display:none;"></span>
      <button class="btn btn-success" id="btn-save-images">
        <i class="fa fa-save"></i> Save Configuration
      </button>
    </div>
    <div id="images-error" class="alert alert-danger" style="display:none;"></div>

    <div class="cmp-table-wrap">
    <table class="table table-bordered cmp-resource-table" id="images-table">
      <thead>
        <tr>
          <th style="width:35px;">#</th>
          <th style="width:32px;"><input type="checkbox" id="check-all-images" title="Select/deselect all"></th>
          <th>Name</th>
          <th>Image ID</th>
          <th>Display Name</th>
          <?php foreach ($currencies as $c): ?>
          <th><?php echo htmlspecialchars($c->code); ?> /mo</th>
          <?php endforeach; ?>
        </tr>
      </thead>
      <tbody>
        <?php
        foreach ($savedImages as $i => $imgId):
          $savedName   = $imageNames[$imgId] ?? '';
          $displayName = ($savedName !== '' && $savedName !== $imgId) ? $savedName : '';
          $priceEntry  = $imagePrices[$imgId] ?? null;
        ?>
        <tr data-id="<?php echo htmlspecialchars($imgId); ?>" data-saved="1">
          <td class="row-num"><?php echo $i + 1; ?></td>
          <td><input type="checkbox" class="img-check" checked></td>
          <td class="img-api-name"><?php echo htmlspecialchars($displayName); ?></td>
          <td><small class="text-muted"><?php echo htmlspecialchars($imgId); ?></small></td>
          <td><input type="text" class="form-control input-sm img-name"
               value="<?php echo htmlspecialchars($displayName); ?>"></td>
          <?php foreach ($currencies as $c):
            if (is_array($priceEntry)) {
              $cellVal = $priceEntry[$c->id] ?? ($priceEntry[(string)$c->id] ?? '0');
            } else {
              // Legacy scalar: only fill for the first currency
              $cellVal = ((int)$c->id === $firstCurrId) ? ($priceEntry ?? '0') : '0';
            }
          ?>
          <td><input type="number" step="0.01" min="0" class="form-control input-sm img-price"
               data-currency="<?php echo (int)$c->id; ?>"
               value="<?php echo htmlspecialchars((string)$cellVal); ?>" style="width:90px;"></td>
          <?php endforeach; ?>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    </div>

    <?php if (empty($savedImages)): ?>
    <p class="text-muted">No images configured yet. Click <strong>Load from API</strong> to fetch available images.</p>
    <?php endif; ?>
  </div>

  <script>
  (function() {
    var serverId  = <?php echo $serverId; ?>;
    var moduleUrl = '<?php echo $moduleUrl; ?>';

    var savedImageIds   = <?php echo json_encode(array_values($savedImages)); ?>;
    var savedNames      = <?php echo json_encode((object)($imageNames ?: new stdClass())); ?>;
    var savedPrices     = <?php echo json_encode((object)($imagePrices ?: new stdClass())); ?>;
    var currencies      = <?php echo json_encode($currencies->toArray()); ?>;
    var firstCurrId     = <?php echo (int)$firstCurrId; ?>;

    function reNumber() {
      var n = 0;
      $('#images-table tbody tr').each(function() {
        $(this).find('.row-num').text($(this).is(':visible') ? ++n : '');
      });
    }

    function addRows(items) {
      var existingIds = [];
      $('#images-table tbody tr').each(function() { existingIds.push(String($(this).data('id'))); });

      $.each(items, function(i, img) {
        if (existingIds.indexOf(String(img.id)) !== -1) return; // skip duplicates
        var isSaved = savedImageIds.indexOf(img.id) !== -1;
        var row = $('<tr>').attr('data-id', img.id).attr('data-saved', '0');
        var saved = savedPrices[img.id];
        var savedIsMap = saved && typeof saved === 'object';
        var priceCells = '';
        currencies.forEach(function(c) {
          var v;
          if (savedIsMap) {
            v = saved[c.id] !== undefined ? saved[c.id] : (saved[String(c.id)] !== undefined ? saved[String(c.id)] : '0');
          } else if (saved !== undefined && saved !== null && saved !== '') {
            // Legacy scalar: only fill for first currency
            v = (parseInt(c.id) === firstCurrId) ? saved : '0';
          } else {
            v = '0';
          }
          priceCells += '<td><input type="number" step="0.01" min="0" class="form-control input-sm img-price" data-currency="' +
            parseInt(c.id) + '" style="width:90px;" value="' + $('<span>').text(v).html() + '"></td>';
        });
        row.html(
          '<td class="row-num"></td>' +
          '<td><input type="checkbox" class="img-check"' + (isSaved ? ' checked' : '') + '></td>' +
          '<td class="img-api-name">' + $('<span>').text(img.name).html() + '</td>' +
          '<td><small class="text-muted">' + $('<span>').text(img.id).html() + '</small></td>' +
          '<td><input type="text" class="form-control input-sm img-name" value="' +
            $('<span>').text(savedNames[img.id] || img.name).html() + '"></td>' +
          priceCells
        );
        $('#images-table tbody').append(row);
      });
      reNumber();
    }

    // Search filter: hide non-matching rows, but always show checked rows
    $('#images-search').on('input', function() {
      var q = $(this).val().toLowerCase().trim();
      $('#images-table tbody tr').each(function() {
        var row = $(this);
        if (!q) { row.show(); return; }
        if (row.find('.img-check').is(':checked')) { row.show(); return; }
        row.toggle(row.text().toLowerCase().indexOf(q) !== -1);
      });
    });

    // Load from API: fetch images for the server's region
    $('#btn-load-images').on('click', function() {
      var btn = $(this).prop('disabled', true);
      // Remove previously-loaded (unsaved) rows so re-fetch is a clean refresh
      $('#images-table tbody tr[data-saved="0"]').remove();
      $('#btn-save-images').prop('disabled', true);
      $('#images-loading').show();
      $('#images-loading-text').text('Loading images...');
      $('#images-error').hide();

      $.post(moduleUrl, { action: 'load_images', server_id: serverId }, function(resp) {
        if (resp.success && resp.images && resp.images.length) {
          addRows(resp.images);
        } else {
          $('#images-error').text(resp.error || 'No images returned.').show();
        }
      }, 'json').always(function() {
        btn.prop('disabled', false);
        $('#btn-save-images').prop('disabled', false);
        $('#images-loading').hide();
      });
    });

    // Uncheck: confirm, then just uncheck (row stays in table)
    $('#images-table').on('change', '.img-check', function() {
      var cb  = $(this);
      if (!cb.is(':checked')) {
        var name = cb.closest('tr').find('.img-api-name').text() || cb.closest('tr').data('id');
        if (!confirm('Deselect "' + name + '" from the image selection?')) {
          cb.prop('checked', true);
        }
      }
    });

    // Check-all header: check or uncheck all visible rows (no removal)
    $('#check-all-images').on('change', function() {
      var checked = $(this).is(':checked');
      $('#images-table tbody tr:visible').each(function() {
        $(this).find('.img-check').prop('checked', checked);
      });
    });

    $('#btn-save-images').on('click', function() {
      var saveBtn = $(this).prop('disabled', true);
      $('#btn-load-images').prop('disabled', true);
      $('#images-saving').show();
      var ids = [], names = {}, prices = {};
      $('#images-table tbody tr').each(function() {
        var row = $(this);
        if (row.find('.img-check').is(':checked')) {
          var id = row.data('id');
          ids.push(id);
          names[id]   = row.find('.img-name').val();
          var perCurr = {};
          row.find('.img-price').each(function() {
            perCurr[$(this).data('currency')] = $(this).val();
          });
          prices[id] = perCurr;
        }
      });

      $.when(
        $.post(moduleUrl, { action: 'save_images',        server_id: serverId, selected_images: ids }, null, 'json'),
        $.post(moduleUrl, { action: 'save_image_names',   server_id: serverId, names: names         }, null, 'json'),
        $.post(moduleUrl, { action: 'save_image_prices',  server_id: serverId, prices: prices       }, null, 'json')
      ).always(function() {
        savedImageIds = ids;
        saveBtn.prop('disabled', false);
        $('#btn-load-images').prop('disabled', false);
        $('#images-saving').hide();
        $('#images-save-msg').text('Configuration saved.').removeClass('error').show();
        setTimeout(function() { $('#images-save-msg').fadeOut(); }, 3000);
      });
    });
  }());
  </script>
  <?php
}

/**
 * Render the Flavors tab.
 *
 * Single unified table with region selector. Saved items shown checked on
 * load. Load from API appends unchecked new items. Uncheck + confirm removes.
 *
 * @param int    $serverId  Active server ID
 * @param string $moduleUrl Base module URL
 */
function cloudpe_cmp_admin_render_flavors(int $serverId, string $moduleUrl): void
{
  $savedFlavors   = (array)cloudpe_cmp_admin_get_setting($serverId, 'selected_flavors', []);
  $flavorNames    = (array)cloudpe_cmp_admin_get_setting($serverId, 'flavor_names', []);
  $flavorPrices   = (array)cloudpe_cmp_admin_get_setting($serverId, 'flavor_prices', []);
  $flavorApiNames = (array)cloudpe_cmp_admin_get_setting($serverId, 'flavor_api_names', []);
  // Specs persisted per-flavor so vCPU/RAM survive page reload.
  // Shape: { "<flavorId>": { "vcpu": 2, "memory_gb": 4 }, ... }
  $flavorSpecs    = (array)cloudpe_cmp_admin_get_setting($serverId, 'flavor_specs', []);
  $currencies     = Capsule::table('tblcurrencies')
    ->whereIn('code', ['INR', 'USD'])
    ->orderBy('id')
    ->get(['id', 'code']);
  $firstCurrId    = $currencies->count() ? (int)$currencies->first()->id : 0;
  ?>
  <div class="cmp-section">
    <h4>Flavors</h4>
    <div class="cmp-toolbar">
      <button class="btn btn-primary" id="btn-load-flavors">
        <i class="fa fa-refresh"></i> Load from API
      </button>
      <span id="flavors-loading" style="display:none;"><i class="fa fa-spinner fa-spin"></i> <span id="flavors-loading-text">Loading...</span></span>
      <input type="text" id="flavors-search" class="form-control cmp-search" placeholder="Filter flavors...">
      <span id="flavors-saving" style="display:none;"><i class="fa fa-spinner fa-spin"></i> <span id="flavors-saving-text">Saving configuration...</span></span>
      <span id="flavors-save-msg" class="cmp-save-msg" style="display:none;"></span>
      <button class="btn btn-success" id="btn-save-flavors">
        <i class="fa fa-save"></i> Save Configuration
      </button>
    </div>
    <div id="flavors-error" class="alert alert-danger" style="display:none;"></div>
    <div id="flavors-price-changes" class="alert alert-warning" style="display:none;"></div>

    <style>
      /* Keep the latest-price icon sitting next to the price input, not below it. */
      #flavors-table td .flv-price { display: inline-block; width: 80px; vertical-align: middle; }
      .flv-price-apply { display: inline-block; vertical-align: middle; margin-left: 4px; color: #f0ad4e; cursor: pointer; font-size: 14px; }
      .flv-price-apply:hover { color: #ec971f; }
    </style>

    <div class="cmp-table-wrap">
    <table class="table table-bordered cmp-resource-table" id="flavors-table">
      <thead>
        <tr>
          <th style="width:35px;">#</th>
          <th style="width:32px;"><input type="checkbox" id="check-all-flavors" title="Select/deselect all"></th>
          <th>Name</th>
          <th>vCPU</th>
          <th>RAM (GB)</th>
          <th>Flavor ID</th>
          <th>Display Name</th>
          <?php foreach ($currencies as $c): ?>
          <th><?php echo htmlspecialchars($c->code); ?> /mo</th>
          <?php endforeach; ?>
        </tr>
      </thead>
      <tbody>
        <?php
        foreach ($savedFlavors as $i => $flvId):
          $savedName   = $flavorNames[$flvId] ?? '';
          $displayName = ($savedName !== '' && $savedName !== $flvId) ? $savedName : '';
          $priceEntry  = $flavorPrices[$flvId] ?? null;
        ?>
        <?php
          $spec     = (array)($flavorSpecs[$flvId] ?? []);
          $specVcpu = isset($spec['vcpu']) ? (int)$spec['vcpu'] : null;
          $specRam  = isset($spec['memory_gb']) ? (float)$spec['memory_gb'] : null;
        ?>
        <tr data-id="<?php echo htmlspecialchars($flvId); ?>" data-saved="1">
          <td class="row-num"><?php echo $i + 1; ?></td>
          <td><input type="checkbox" class="flv-check" checked></td>
          <td class="flv-api-name"><?php echo htmlspecialchars($flavorApiNames[$flvId] ?? $flvId); ?></td>
          <td class="flv-vcpu"><?php echo $specVcpu !== null ? $specVcpu : '—'; ?></td>
          <td class="flv-ram"><?php echo $specRam !== null ? $specRam : '—'; ?></td>
          <td><small class="text-muted"><?php echo htmlspecialchars($flvId); ?></small></td>
          <td><input type="text" class="form-control input-sm flv-name"
               value="<?php echo htmlspecialchars($displayName); ?>"></td>
          <?php foreach ($currencies as $c):
            if (is_array($priceEntry)) {
              $cellVal = $priceEntry[$c->id] ?? ($priceEntry[(string)$c->id] ?? '0');
            } else {
              $cellVal = ((int)$c->id === $firstCurrId) ? ($priceEntry ?? '0') : '0';
            }
          ?>
          <td><input type="number" step="0.01" min="0" class="form-control input-sm flv-price"
               data-currency="<?php echo (int)$c->id; ?>"
               value="<?php echo htmlspecialchars((string)$cellVal); ?>" style="width:90px;"></td>
          <?php endforeach; ?>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    </div>

    <?php if (empty($savedFlavors)): ?>
    <p class="text-muted">No flavors configured yet. Click <strong>Load from API</strong> to fetch available flavors.</p>
    <?php endif; ?>
  </div>

  <script>
  (function() {
    var serverId  = <?php echo $serverId; ?>;
    var moduleUrl = '<?php echo $moduleUrl; ?>';

    var savedFlavorIds  = <?php echo json_encode(array_values($savedFlavors)); ?>;
    var savedNames      = <?php echo json_encode((object)($flavorNames ?: new stdClass())); ?>;
    var savedPrices     = <?php echo json_encode((object)($flavorPrices ?: new stdClass())); ?>;
    var currencies      = <?php echo json_encode($currencies->toArray()); ?>;
    var firstCurrId     = <?php echo (int)$firstCurrId; ?>;

    // Build the auto Display Name shown to customers: "X vCPU, Y GB RAM".
    function autoDisplayName(flv) {
      var vcpu = parseInt(flv.vcpu) || 0;
      var ram  = parseFloat(flv.memory_gb) || 0;
      return vcpu + ' vCPU, ' + ram + ' GB RAM';
    }

    function reNumber() {
      var n = 0;
      $('#flavors-table tbody tr').each(function() {
        $(this).find('.row-num').text($(this).is(':visible') ? ++n : '');
      });
    }

    // Collect price-change notifications for the banner above the table.
    var priceChangeNotes = [];

    function codeForCurrencyId(id) {
      var match = currencies.filter(function(c) { return parseInt(c.id) === parseInt(id); });
      return match.length ? match[0].code : String(id);
    }

    // Compare saved input against API-returned price and, if different,
    // show a "!" button next to the input that applies the new price on click.
    function markPriceDiff($input, newValue, flvLabel) {
      if (newValue === undefined || newValue === null || newValue === '') return;
      var current = parseFloat($input.val());
      var latest  = parseFloat(newValue);
      if (isNaN(latest)) return;
      if (!isNaN(current) && Math.abs(current - latest) < 0.001) return;

      var code = codeForCurrencyId($input.data('currency'));
      $input.siblings('.flv-price-apply').remove();
      var $icon = $('<i class="fa fa-exclamation-circle flv-price-apply" role="button" tabindex="0" title="Apply latest price: ' + latest + '"></i>');
      $icon.on('click', function() {
        $input.val(latest);
        $icon.remove();
      });
      $input.after($icon);
      priceChangeNotes.push(flvLabel + ' — ' + code + ': ' + (isNaN(current) ? '—' : current) + ' → ' + latest);
    }

    function renderPriceChangeBanner() {
      var $banner = $('#flavors-price-changes');
      if (!priceChangeNotes.length) { $banner.hide(); return; }
      var html = '<strong>' + priceChangeNotes.length + ' price change' + (priceChangeNotes.length === 1 ? '' : 's') + ' detected from API.</strong> Click the orange <code>!</code> next to a price to apply the latest value.';
      html += '<ul style="margin:6px 0 0 16px;">';
      priceChangeNotes.slice(0, 20).forEach(function(n) { html += '<li>' + $('<span>').text(n).html() + '</li>'; });
      if (priceChangeNotes.length > 20) html += '<li>…and ' + (priceChangeNotes.length - 20) + ' more</li>';
      html += '</ul>';
      $banner.html(html).show();
    }

    function addRows(items) {
      var existingIds = [];
      $('#flavors-table tbody tr').each(function() { existingIds.push(String($(this).data('id'))); });

      // Clear any stale "!" apply buttons from a previous load.
      $('#flavors-table .flv-price-apply').remove();
      priceChangeNotes = [];
      $('#flavors-price-changes').hide();

      $.each(items, function(i, flv) {
        var flvLabel = flv.name || flv.id;
        var apiByCode = { 'INR': flv.price_monthly_inr, 'USD': flv.price_monthly_usd };

        if (existingIds.indexOf(String(flv.id)) !== -1) {
          // Existing saved row: refresh specs + diff prices against API.
          var er = $('#flavors-table tbody tr[data-id="' + flv.id + '"]');
          if (flv.vcpu    !== undefined) er.find('.flv-vcpu').text(parseInt(flv.vcpu) || 0);
          if (flv.memory_gb !== undefined) er.find('.flv-ram').text(parseFloat(flv.memory_gb) || 0);
          var nameInput = er.find('.flv-name');
          if (!(nameInput.val() || '').trim()) {
            nameInput.val(autoDisplayName(flv));
          }
          er.find('.flv-price').each(function() {
            var $inp = $(this);
            var code = codeForCurrencyId($inp.data('currency'));
            markPriceDiff($inp, apiByCode[code], flvLabel);
          });
          return;
        }
        var isSaved = savedFlavorIds.indexOf(flv.id) !== -1;
        var row = $('<tr>').attr('data-id', flv.id).attr('data-saved', '0');
        var defaultName = savedNames[flv.id] || autoDisplayName(flv);
        var saved = savedPrices[flv.id];
        var savedIsMap = saved && typeof saved === 'object';
        var apiPriceByCode = {
          'INR': flv.price_monthly_inr,
          'USD': flv.price_monthly_usd,
        };
        var priceCells = '';
        currencies.forEach(function(c) {
          var v;
          if (savedIsMap && (saved[c.id] !== undefined || saved[String(c.id)] !== undefined)) {
            v = saved[c.id] !== undefined ? saved[c.id] : saved[String(c.id)];
          } else if (!savedIsMap && saved !== undefined && saved !== null && saved !== '' && parseInt(c.id) === firstCurrId) {
            v = saved;
          } else if (apiPriceByCode[c.code] !== undefined && apiPriceByCode[c.code] !== null) {
            // Fall back to the price the CMP API returned with the flavor.
            v = apiPriceByCode[c.code];
          } else {
            v = '0';
          }
          priceCells += '<td><input type="number" step="0.01" min="0" class="form-control input-sm flv-price" data-currency="' +
            parseInt(c.id) + '" style="width:90px;" value="' + $('<span>').text(v).html() + '"></td>';
        });
        row.html(
          '<td class="row-num"></td>' +
          '<td><input type="checkbox" class="flv-check"' + (isSaved ? ' checked' : '') + '></td>' +
          '<td class="flv-api-name">' + $('<span>').text(flv.name).html() + '</td>' +
          '<td class="flv-vcpu">' + (parseInt(flv.vcpu) || 0) + '</td>' +
          '<td class="flv-ram">' + (parseFloat(flv.memory_gb) || 0) + '</td>' +
          '<td><small class="text-muted">' + $('<span>').text(flv.id).html() + '</small></td>' +
          '<td><input type="text" class="form-control input-sm flv-name" value="' +
            $('<span>').text(defaultName).html() + '"></td>' +
          priceCells
        );
        $('#flavors-table tbody').append(row);
      });
      reNumber();
      renderPriceChangeBanner();
    }

    // Search filter: hide non-matching rows, always show checked rows
    $('#flavors-search').on('input', function() {
      var q = $(this).val().toLowerCase().trim();
      $('#flavors-table tbody tr').each(function() {
        var row = $(this);
        if (!q) { row.show(); return; }
        if (row.find('.flv-check').is(':checked')) { row.show(); return; }
        row.toggle(row.text().toLowerCase().indexOf(q) !== -1);
      });
    });

    // Load from API: fetch flavors for the server's region
    $('#btn-load-flavors').on('click', function() {
      var btn = $(this).prop('disabled', true);
      // Remove previously-loaded (unsaved) rows so re-fetch is a clean refresh
      $('#flavors-table tbody tr[data-saved="0"]').remove();
      $('#btn-save-flavors').prop('disabled', true);
      $('#flavors-loading').show();
      $('#flavors-loading-text').text('Loading flavors...');
      $('#flavors-error').hide();

      $.post(moduleUrl, { action: 'load_flavors', server_id: serverId }, function(fr) {
        if (fr && fr.success && fr.flavors && fr.flavors.length) {
          addRows(fr.flavors);
        } else {
          $('#flavors-error').text(fr.error || 'No flavors returned.').show();
        }
      }, 'json').always(function() {
        btn.prop('disabled', false);
        $('#btn-save-flavors').prop('disabled', false);
        $('#flavors-loading').hide();
      });
    });

    // Uncheck: confirm, then just uncheck (row stays in table)
    $('#flavors-table').on('change', '.flv-check', function() {
      var cb = $(this);
      if (!cb.is(':checked')) {
        var name = cb.closest('tr').find('.flv-api-name').text() || cb.closest('tr').data('id');
        if (!confirm('Deselect "' + name + '" from the flavor selection?')) {
          cb.prop('checked', true);
        }
      }
    });

    // Check-all header: check or uncheck all visible rows (no removal)
    $('#check-all-flavors').on('change', function() {
      var checked = $(this).is(':checked');
      $('#flavors-table tbody tr:visible').each(function() {
        $(this).find('.flv-check').prop('checked', checked);
      });
    });

    $('#btn-save-flavors').on('click', function() {
      var saveBtn = $(this).prop('disabled', true);
      $('#btn-load-flavors').prop('disabled', true);
      $('#flavors-saving').show();
      var ids = [], names = {}, prices = {}, specs = {}, apiNames = {};
      $('#flavors-table tbody tr').each(function() {
        var row = $(this);
        if (row.find('.flv-check').is(':checked')) {
          var id = row.data('id');
          ids.push(id);
          names[id]    = row.find('.flv-name').val();
          var perCurr = {};
          row.find('.flv-price').each(function() {
            perCurr[$(this).data('currency')] = $(this).val();
          });
          prices[id]   = perCurr;
          apiNames[id] = $.trim(row.find('.flv-api-name').text());
          // Persist vCPU/RAM from the rendered cells so they survive reload.
          var vcpuText = $.trim(row.find('.flv-vcpu').text());
          var ramText  = $.trim(row.find('.flv-ram').text());
          if (vcpuText && vcpuText !== '—') {
            specs[id] = { vcpu: parseInt(vcpuText) || 0, memory_gb: parseFloat(ramText) || 0 };
          }
        }
      });

      $.when(
        $.post(moduleUrl, { action: 'save_flavors',          server_id: serverId, selected_flavors: ids }, null, 'json'),
        $.post(moduleUrl, { action: 'save_flavor_names',     server_id: serverId, names: names          }, null, 'json'),
        $.post(moduleUrl, { action: 'save_flavor_prices',    server_id: serverId, prices: prices        }, null, 'json'),
        $.post(moduleUrl, { action: 'save_flavor_specs',     server_id: serverId, specs: specs          }, null, 'json'),
        $.post(moduleUrl, { action: 'save_flavor_api_names', server_id: serverId, api_names: apiNames   }, null, 'json')
      ).always(function() {
        savedFlavorIds = ids;
        saveBtn.prop('disabled', false);
        $('#btn-load-flavors').prop('disabled', false);
        $('#flavors-saving').hide();
        $('#flavors-save-msg').text('Configuration saved.').removeClass('error').show();
        setTimeout(function() { $('#flavors-save-msg').fadeOut(); }, 3000);
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

  $currencies  = Capsule::table('tblcurrencies')
    ->whereIn('code', ['INR', 'USD'])
    ->orderBy('id')
    ->get(['id', 'code']);
  $firstCurrId = $currencies->count() ? (int)$currencies->first()->id : 0;
  ?>
  <div class="cmp-section">
    <h4>Disk Size Options</h4>
    <p class="text-muted">Define the disk size options customers can choose from when ordering.</p>

    <div class="cmp-toolbar">
      <button class="btn btn-default btn-sm" id="btn-add-disk">
        <i class="fa fa-plus"></i> Add Disk Option
      </button>
      <span id="disks-loading" style="display:none;"><i class="fa fa-spinner fa-spin"></i> <span id="disks-loading-text">Saving configuration...</span></span>
      <span class="cmp-spacer"></span>
      <span id="disks-save-msg" class="cmp-save-msg" style="display:none;"></span>
      <button class="btn btn-success" id="btn-save-disks">
        <i class="fa fa-save"></i> Save Disk Sizes
      </button>
    </div>

    <table class="table table-bordered cmp-resource-table" id="disks-table">
      <thead>
        <tr>
          <th>Size (GB)</th>
          <th>Display Label</th>
          <?php foreach ($currencies as $c): ?>
          <th><?php echo htmlspecialchars($c->code); ?> /mo</th>
          <?php endforeach; ?>
          <th>Action</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($savedDisks as $disk):
          $priceEntry = $disk['prices'] ?? null;
          $legacy     = $disk['price']  ?? null;
        ?>
        <tr>
          <td><input type="number" min="1" class="form-control input-sm disk-size"
               value="<?php echo (int)($disk['size_gb'] ?? 0); ?>"></td>
          <td><input type="text" class="form-control input-sm disk-label"
               value="<?php echo htmlspecialchars($disk['label'] ?? ''); ?>"></td>
          <?php foreach ($currencies as $c):
            if (is_array($priceEntry)) {
              $cellVal = $priceEntry[$c->id] ?? $priceEntry[(string)$c->id] ?? '0';
            } else {
              $cellVal = ((int)$c->id === $firstCurrId) ? ($legacy ?? '0') : '0';
            }
          ?>
          <td><input type="number" step="0.01" min="0" class="form-control input-sm disk-price"
               data-currency="<?php echo (int)$c->id; ?>"
               value="<?php echo htmlspecialchars((string)$cellVal); ?>"></td>
          <?php endforeach; ?>
          <td><button class="btn btn-xs btn-danger btn-remove-disk">Remove</button></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>

  </div>

  <script>
  (function() {
    var serverId  = <?php echo $serverId; ?>;
    var moduleUrl = '<?php echo $moduleUrl; ?>';
    var currencies = <?php echo json_encode($currencies->toArray()); ?>;

    function priceCellsHtml() {
      var html = '';
      currencies.forEach(function(c) {
        html += '<td><input type="number" step="0.01" min="0" class="form-control input-sm disk-price" data-currency="' +
                parseInt(c.id) + '" value="0"></td>';
      });
      return html;
    }

    $('#btn-add-disk').on('click', function() {
      $('#disks-table tbody').append(
        '<tr>' +
        '<td><input type="number" min="1" class="form-control input-sm disk-size" placeholder="e.g. 50"></td>' +
        '<td><input type="text" class="form-control input-sm disk-label" placeholder="e.g. 50 GB SSD"></td>' +
        priceCellsHtml() +
        '<td><button class="btn btn-xs btn-danger btn-remove-disk">Remove</button></td>' +
        '</tr>'
      );
    });

    $(document).on('click', '.btn-remove-disk', function() {
      $(this).closest('tr').remove();
    });

    $('#btn-save-disks').on('click', function() {
      var saveBtn = $(this).prop('disabled', true);
      $('#disks-loading').show();
      $('#disks-loading-text').text('Saving configuration...');

      var disks = [];
      $('#disks-table tbody tr').each(function() {
        var size  = parseInt($(this).find('.disk-size').val());
        var label = $(this).find('.disk-label').val().trim();
        var prices = {};
        $(this).find('.disk-price').each(function() {
          prices[$(this).data('currency')] = parseFloat($(this).val()) || 0;
        });
        if (size > 0) {
          disks.push({ size_gb: size, label: label || (size + ' GB'), prices: prices });
        }
      });

      $.post(moduleUrl, { action: 'save_disks', server_id: serverId, disks: disks }, function(resp) {
        var msg = $('#disks-save-msg');
        if (resp.success) {
          msg.text('Disk sizes saved successfully.').removeClass('error').show();
        } else {
          msg.text(resp.message || 'Failed to save disk sizes.').addClass('error').show();
        }
        setTimeout(function() { msg.fadeOut(); }, 3000);
      }, 'json').always(function() {
        saveBtn.prop('disabled', false);
        $('#disks-loading').hide();
      });
    });
  }());
  </script>
  <?php
}

// ---------------------------------------------------------------------------
// Legacy tab renderers (kept for AJAX handler compatibility)
// ---------------------------------------------------------------------------

/**
 * Render the Projects tab.
 *
 * Single unified table. Saved items shown checked on load. Load from API
 * appends unchecked new items. Uncheck + confirm removes.
 *
 * @param int    $serverId  Active server ID
 * @param string $moduleUrl Base module URL
 */
function cloudpe_cmp_admin_render_projects(int $serverId, string $moduleUrl): void
{
  $savedProjects  = (array)cloudpe_cmp_admin_get_setting($serverId, 'selected_projects', []);
  $projectNames   = (array)cloudpe_cmp_admin_get_setting($serverId, 'project_names', []);
  ?>
  <div class="cmp-section">
    <h4>Projects</h4>
    <p class="text-muted">Projects scope VM resources. Select the projects you want to expose to customers and set a friendly Display Name.</p>
    <div class="cmp-toolbar">
      <button class="btn btn-primary" id="btn-load-projects">
        <i class="fa fa-refresh"></i> Load from API
      </button>
      <span id="projects-loading" style="display:none;"><i class="fa fa-spinner fa-spin"></i> Loading...</span>
      <input type="text" id="projects-search" class="form-control cmp-search" placeholder="Filter projects...">
      <button class="btn btn-success btn-save-right" id="btn-save-projects">
        <i class="fa fa-save"></i> Save Configuration
      </button>
    </div>
    <div id="projects-save-msg" style="display:none; margin-bottom:6px;"></div>
    <div id="projects-error" class="alert alert-danger" style="display:none;"></div>

    <div class="cmp-table-wrap">
    <table class="table table-bordered cmp-resource-table" id="projects-table">
      <thead>
        <tr>
          <th style="width:35px;">#</th>
          <th style="width:32px;"><input type="checkbox" id="check-all-projects" title="Select/deselect all"></th>
          <th>Name</th>
          <th>Project ID</th>
          <th>Display Name</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($savedProjects as $i => $projId):
          $displayName = $projectNames[$projId] ?? $projId;
        ?>
        <tr data-id="<?php echo htmlspecialchars($projId); ?>">
          <td class="row-num"><?php echo $i + 1; ?></td>
          <td><input type="checkbox" class="proj-check" checked></td>
          <td class="proj-api-name"><?php echo htmlspecialchars($displayName); ?></td>
          <td><small class="text-muted"><?php echo htmlspecialchars($projId); ?></small></td>
          <td><input type="text" class="form-control input-sm proj-name"
               value="<?php echo htmlspecialchars($displayName); ?>"></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    </div>

    <?php if (empty($savedProjects)): ?>
    <p class="text-muted">No projects configured yet. Click <strong>Load from API</strong> or enter a Project ID manually in the server Access Hash field.</p>
    <?php endif; ?>
  </div>

  <script>
  (function() {
    var serverId        = <?php echo $serverId; ?>;
    var moduleUrl       = '<?php echo $moduleUrl; ?>';
    var savedProjIds    = <?php echo json_encode(array_values($savedProjects)); ?>;
    var savedNames      = <?php echo json_encode((object)($projectNames ?: new stdClass())); ?>;

    function reNumber() {
      $('#projects-table tbody tr').each(function(i) { $(this).find('.row-num').text(i + 1); });
    }

    // Search filter: hide non-matching rows, always show checked rows
    $('#projects-search').on('input', function() {
      var q = $(this).val().toLowerCase().trim();
      $('#projects-table tbody tr').each(function() {
        var row = $(this);
        if (!q) { row.show(); return; }
        if (row.find('.proj-check').is(':checked')) { row.show(); return; }
        row.toggle(row.text().toLowerCase().indexOf(q) !== -1);
      });
    });

    $('#btn-load-projects').on('click', function() {
      var btn = $(this).prop('disabled', true);
      $('#projects-loading').show();
      $('#projects-error').hide();
      $.post(moduleUrl, { action: 'load_projects', server_id: serverId }, function(resp) {
        btn.prop('disabled', false);
        $('#projects-loading').hide();
        if (!resp.success) {
          $('#projects-error').text(resp.error || 'Failed to load projects.').show();
          return;
        }
        if (!resp.projects || resp.projects.length === 0) {
          $('#projects-error').text('No projects returned by the API. Enter a Project ID in the server Access Hash field.').show();
          return;
        }

        var existingIds = [];
        $('#projects-table tbody tr').each(function() { existingIds.push(String($(this).data('id'))); });

        $.each(resp.projects, function(i, p) {
          if (existingIds.indexOf(String(p.id)) !== -1) return;
          var isSaved = savedProjIds.indexOf(p.id) !== -1;
          var row = $('<tr>').attr('data-id', p.id);
          row.html(
            '<td class="row-num"></td>' +
            '<td><input type="checkbox" class="proj-check"' + (isSaved ? ' checked' : '') + '></td>' +
            '<td class="proj-api-name">' + $('<span>').text(p.name).html() + '</td>' +
            '<td><small class="text-muted">' + $('<span>').text(p.id).html() + '</small></td>' +
            '<td><input type="text" class="form-control input-sm proj-name" value="' +
              $('<span>').text(savedNames[p.id] || p.name).html() + '"></td>'
          );
          $('#projects-table tbody').append(row);
        });
        reNumber();
      }, 'json').fail(function() {
        btn.prop('disabled', false);
        $('#projects-loading').hide();
        $('#projects-error').text('Request failed. Check server connectivity.').show();
      });
    });

    // Uncheck: confirm, then just uncheck (row stays in table)
    $('#projects-table').on('change', '.proj-check', function() {
      var cb = $(this);
      if (!cb.is(':checked')) {
        var name = cb.closest('tr').find('.proj-api-name').text() || cb.closest('tr').data('id');
        if (!confirm('Deselect "' + name + '" from the project selection?')) {
          cb.prop('checked', true);
        }
      }
    });

    // Check-all header: check or uncheck all visible rows (no removal)
    $('#check-all-projects').on('change', function() {
      var checked = $(this).is(':checked');
      $('#projects-table tbody tr:visible').each(function() {
        $(this).find('.proj-check').prop('checked', checked);
      });
    });

    $('#btn-save-projects').on('click', function() {
      var ids = [], names = {};
      $('#projects-table tbody tr').each(function() {
        var row = $(this);
        if (row.find('.proj-check').is(':checked')) {
          var id = row.data('id');
          ids.push(id);
          names[id] = row.find('.proj-name').val();
        }
      });

      $.when(
        $.post(moduleUrl, { action: 'save_projects',      server_id: serverId, selected_projects: ids }, null, 'json'),
        $.post(moduleUrl, { action: 'save_project_names', server_id: serverId, names: names           }, null, 'json')
      ).always(function() {
        savedProjIds = ids;
        $('#projects-save-msg').text('Project configuration saved.').removeClass('alert-danger').addClass('alert alert-success').show();
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
 * Single unified table. Saved items shown checked on load. Load from API
 * appends unchecked new items. Uncheck + confirm removes.
 *
 * @param int    $serverId  Active server ID
 * @param string $moduleUrl Base module URL
 */
function cloudpe_cmp_admin_render_security_groups(int $serverId, string $moduleUrl): void
{
  $savedSgs = (array)cloudpe_cmp_admin_get_setting($serverId, 'selected_security_groups', []);
  $sgNames  = (array)cloudpe_cmp_admin_get_setting($serverId, 'security_group_names', []);
  ?>
  <div class="cmp-section">
    <h4>Security Groups</h4>
    <p class="text-muted">Security groups define firewall rules for VMs. Select the project to load its security groups.</p>
    <div class="cmp-toolbar" style="flex-wrap:wrap;">
      <label style="margin:0; white-space:nowrap;"><strong>Project:</strong></label>
      <select id="sgs-project-select" class="form-control" style="width:auto; min-width:200px;">
        <?php
        $savedProjects = (array)cloudpe_cmp_admin_get_setting($serverId, 'selected_projects', []);
        $projectNames  = (array)cloudpe_cmp_admin_get_setting($serverId, 'project_names', []);
        $serverAccessHash = Capsule::table('tblservers')->where('id', $serverId)->value('accesshash') ?? '';
        if ($serverAccessHash): ?>
        <option value="<?php echo htmlspecialchars($serverAccessHash); ?>">
          <?php echo htmlspecialchars($serverAccessHash); ?> (server default)
        </option>
        <?php endif;
        foreach ($savedProjects as $pid):
          if ($pid === $serverAccessHash) continue;
        ?>
        <option value="<?php echo htmlspecialchars($pid); ?>">
          <?php echo htmlspecialchars($projectNames[$pid] ?? $pid); ?>
        </option>
        <?php endforeach; ?>
        <option value="">— enter manually —</option>
      </select>
      <input type="text" id="sgs-project-manual" class="form-control" style="width:220px; display:none;"
             placeholder="Paste project UUID">
      <button class="btn btn-primary" id="btn-load-sgs">
        <i class="fa fa-refresh"></i> Load from API
      </button>
      <span id="sgs-loading" style="display:none;"><i class="fa fa-spinner fa-spin"></i> Loading...</span>
      <input type="text" id="sgs-search" class="form-control cmp-search" placeholder="Filter security groups...">
      <button class="btn btn-success btn-save-right" id="btn-save-sgs">
        <i class="fa fa-save"></i> Save Configuration
      </button>
    </div>
    <div id="sgs-save-msg" style="display:none; margin-bottom:6px;"></div>
    <div id="sgs-error" class="alert alert-danger" style="display:none;"></div>

    <div class="cmp-table-wrap">
    <table class="table table-bordered cmp-resource-table" id="sgs-table">
      <thead>
        <tr>
          <th style="width:35px;">#</th>
          <th style="width:32px;"><input type="checkbox" id="check-all-sgs" title="Select/deselect all"></th>
          <th>Name</th>
          <th>Description</th>
          <th>SG ID</th>
          <th>Display Name</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($savedSgs as $i => $sgId):
          $displayName = $sgNames[$sgId] ?? $sgId;
        ?>
        <tr data-id="<?php echo htmlspecialchars($sgId); ?>">
          <td class="row-num"><?php echo $i + 1; ?></td>
          <td><input type="checkbox" class="sg-check" checked></td>
          <td class="sg-api-name"><?php echo htmlspecialchars($displayName); ?></td>
          <td class="sg-desc text-muted">—</td>
          <td><small class="text-muted"><?php echo htmlspecialchars($sgId); ?></small></td>
          <td><input type="text" class="form-control input-sm sg-name"
               value="<?php echo htmlspecialchars($displayName); ?>"></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    </div>

    <?php if (empty($savedSgs)): ?>
    <p class="text-muted">No security groups configured yet. Click <strong>Load from API</strong> to fetch from the server's project.</p>
    <?php endif; ?>
  </div>

  <script>
  (function() {
    var serverId  = <?php echo $serverId; ?>;
    var moduleUrl = '<?php echo $moduleUrl; ?>';
    var savedSgIds = <?php echo json_encode(array_values($savedSgs)); ?>;
    var savedNames = <?php echo json_encode((object)($sgNames ?: new stdClass())); ?>;

    // Show manual project input when "— enter manually —" selected
    $('#sgs-project-select').on('change', function() {
      $('#sgs-project-manual').toggle($(this).val() === '');
    });

    function reNumber() {
      $('#sgs-table tbody tr').each(function(i) { $(this).find('.row-num').text(i + 1); });
    }

    // Search filter: hide non-matching rows, always show checked rows
    $('#sgs-search').on('input', function() {
      var q = $(this).val().toLowerCase().trim();
      $('#sgs-table tbody tr').each(function() {
        var row = $(this);
        if (!q) { row.show(); return; }
        if (row.find('.sg-check').is(':checked')) { row.show(); return; }
        row.toggle(row.text().toLowerCase().indexOf(q) !== -1);
      });
    });

    $('#btn-load-sgs').on('click', function() {
      var projectId = $('#sgs-project-select').val() === ''
        ? $('#sgs-project-manual').val().trim()
        : $('#sgs-project-select').val();
      if (!projectId) {
        $('#sgs-error').text('Please select or enter a project ID.').show();
        return;
      }
      var btn = $(this).prop('disabled', true);
      $('#sgs-loading').show();
      $('#sgs-error').hide();
      // Security groups are project-scoped; region is implicit to the server
      $.post(moduleUrl, { action: 'load_security_groups', server_id: serverId, project_id: projectId }, function(resp) {
        btn.prop('disabled', false);
        $('#sgs-loading').hide();
        if (!resp.success) {
          $('#sgs-error').text(resp.error || 'Failed to load security groups.').show();
          return;
        }
        if (!resp.security_groups || resp.security_groups.length === 0) {
          $('#sgs-error').text('No security groups found for this project.').show();
          return;
        }

        var existingIds = [];
        $('#sgs-table tbody tr').each(function() { existingIds.push(String($(this).data('id'))); });

        $.each(resp.security_groups, function(i, sg) {
          if (existingIds.indexOf(String(sg.id)) !== -1) {
            $('#sgs-table tbody tr[data-id="' + sg.id + '"] .sg-desc').text(sg.description || '');
            return;
          }
          var isSaved = savedSgIds.indexOf(sg.id) !== -1;
          var row = $('<tr>').attr('data-id', sg.id);
          row.html(
            '<td class="row-num"></td>' +
            '<td><input type="checkbox" class="sg-check"' + (isSaved ? ' checked' : '') + '></td>' +
            '<td class="sg-api-name">' + $('<span>').text(sg.name).html() + '</td>' +
            '<td class="sg-desc text-muted">' + $('<span>').text(sg.description || '').html() + '</td>' +
            '<td><small class="text-muted">' + $('<span>').text(sg.id).html() + '</small></td>' +
            '<td><input type="text" class="form-control input-sm sg-name" value="' +
              $('<span>').text(savedNames[sg.id] || sg.name).html() + '"></td>'
          );
          $('#sgs-table tbody').append(row);
        });
        reNumber();
      }, 'json').fail(function() {
        btn.prop('disabled', false);
        $('#sgs-loading').hide();
        $('#sgs-error').text('Request failed. Check server connectivity.').show();
      });
    });

    // Uncheck: confirm, then just uncheck (row stays in table)
    $('#sgs-table').on('change', '.sg-check', function() {
      var cb = $(this);
      if (!cb.is(':checked')) {
        var name = cb.closest('tr').find('.sg-api-name').text() || cb.closest('tr').data('id');
        if (!confirm('Deselect "' + name + '" from the security group selection?')) {
          cb.prop('checked', true);
        }
      }
    });

    // Check-all header: check or uncheck all visible rows (no removal)
    $('#check-all-sgs').on('change', function() {
      var checked = $(this).is(':checked');
      $('#sgs-table tbody tr:visible').each(function() {
        $(this).find('.sg-check').prop('checked', checked);
      });
    });

    $('#btn-save-sgs').on('click', function() {
      var ids = [], names = {};
      $('#sgs-table tbody tr').each(function() {
        var row = $(this);
        if (row.find('.sg-check').is(':checked')) {
          var id = row.data('id');
          ids.push(id);
          names[id] = row.find('.sg-name').val();
        }
      });

      $.when(
        $.post(moduleUrl, { action: 'save_security_groups',      server_id: serverId, selected_security_groups: ids }, null, 'json'),
        $.post(moduleUrl, { action: 'save_security_group_names', server_id: serverId, names: names                  }, null, 'json')
      ).always(function() {
        savedSgIds = ids;
        $('#sgs-save-msg').text('Security group configuration saved.').removeClass('alert-danger').addClass('alert alert-success').show();
        setTimeout(function() { $('#sgs-save-msg').hide(); }, 3000);
      });
    });
  }());
  </script>
  <?php
}

/**
 * Render the Volume Types (Storage Policies) tab.
 *
 * @param int    $serverId  Active server ID
 * @param string $moduleUrl Base module URL
 */
function cloudpe_cmp_admin_render_volume_types(int $serverId, string $moduleUrl): void
{
  $savedTypes = (array)cloudpe_cmp_admin_get_setting($serverId, 'selected_volume_types', []);
  $typeNames  = (array)cloudpe_cmp_admin_get_setting($serverId, 'volume_type_names', []);
  ?>
  <div class="cmp-section">
    <h4>Storage Policies (Volume Types)</h4>
    <p class="text-muted">Volume types define the storage backend (e.g. SSD, NVMe).</p>
    <div class="cmp-toolbar">
      <button class="btn btn-primary" id="btn-load-vtypes">
        <i class="fa fa-refresh"></i> Load from API
      </button>
      <span id="vtypes-loading" style="display:none;"><i class="fa fa-spinner fa-spin"></i> <span id="vtypes-loading-text">Loading...</span></span>
      <input type="text" id="vtypes-search" class="form-control cmp-search" placeholder="Filter storage policies...">
      <button class="btn btn-success btn-save-right" id="btn-save-vtypes">
        <i class="fa fa-save"></i> Save Configuration
      </button>
    </div>
    <div id="vtypes-save-msg" style="display:none; margin-bottom:6px;"></div>
    <div id="vtypes-error" class="alert alert-danger" style="display:none;"></div>

    <div class="cmp-table-wrap">
    <table class="table table-bordered cmp-resource-table" id="vtypes-table">
      <thead>
        <tr>
          <th style="width:35px;">#</th>
          <th style="width:32px;"><input type="checkbox" id="check-all-vtypes" title="Select/deselect all"></th>
          <th>Name</th>
          <th>Volume Type ID</th>
          <th>Display Name</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($savedTypes as $i => $vtId):
          $displayName = $typeNames[$vtId] ?? $vtId;
        ?>
        <tr data-id="<?php echo htmlspecialchars($vtId); ?>">
          <td class="row-num"><?php echo $i + 1; ?></td>
          <td><input type="checkbox" class="vt-check" checked></td>
          <td class="vt-api-name"><?php echo htmlspecialchars($displayName); ?></td>
          <td><small class="text-muted"><?php echo htmlspecialchars($vtId); ?></small></td>
          <td><input type="text" class="form-control input-sm vt-name"
               value="<?php echo htmlspecialchars($displayName); ?>"></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    </div>

    <?php if (empty($savedTypes)): ?>
    <p class="text-muted">No storage policies configured yet. Click <strong>Load from API</strong> to fetch available volume types.</p>
    <?php endif; ?>
  </div>

  <script>
  (function() {
    var serverId   = <?php echo $serverId; ?>;
    var moduleUrl  = '<?php echo $moduleUrl; ?>';
    var savedVtIds = <?php echo json_encode(array_values($savedTypes)); ?>;
    var savedNames = <?php echo json_encode((object)($typeNames ?: new stdClass())); ?>;

    function reNumber() {
      $('#vtypes-table tbody tr').each(function(i) { $(this).find('.row-num').text(i + 1); });
    }

    function addVtypeRows(items) {
      var existingIds = [];
      $('#vtypes-table tbody tr').each(function() { existingIds.push(String($(this).data('id'))); });

      $.each(items, function(i, vt) {
        if (existingIds.indexOf(String(vt.id)) !== -1) return; // skip duplicates
        var isSaved = savedVtIds.indexOf(vt.id) !== -1;
        var row = $('<tr>').attr('data-id', vt.id);
        row.html(
          '<td class="row-num"></td>' +
          '<td><input type="checkbox" class="vt-check"' + (isSaved ? ' checked' : '') + '></td>' +
          '<td class="vt-api-name">' + $('<span>').text(vt.name).html() + '</td>' +
          '<td><small class="text-muted">' + $('<span>').text(vt.id).html() + '</small></td>' +
          '<td><input type="text" class="form-control input-sm vt-name" value="' +
            $('<span>').text(savedNames[vt.id] || vt.name).html() + '"></td>'
        );
        $('#vtypes-table tbody').append(row);
      });
      reNumber();
    }

    // Search filter: hide non-matching rows, always show checked rows
    $('#vtypes-search').on('input', function() {
      var q = $(this).val().toLowerCase().trim();
      $('#vtypes-table tbody tr').each(function() {
        var row = $(this);
        if (!q) { row.show(); return; }
        if (row.find('.vt-check').is(':checked')) { row.show(); return; }
        row.toggle(row.text().toLowerCase().indexOf(q) !== -1);
      });
    });

    // Load from API: fetch volume types for the server's region
    $('#btn-load-vtypes').on('click', function() {
      var btn = $(this).prop('disabled', true);
      $('#btn-save-vtypes').prop('disabled', true);
      $('#vtypes-loading').show();
      $('#vtypes-loading-text').text('Loading...');
      $('#vtypes-error').hide();

      $.post(moduleUrl, { action: 'load_volume_types', server_id: serverId }, function(resp) {
        if (resp.success && resp.volume_types && resp.volume_types.length) {
          addVtypeRows(resp.volume_types);
        } else {
          $('#vtypes-error').text(resp.error || 'No volume types returned.').show();
        }
      }, 'json').fail(function() {
        $('#vtypes-error').text('Request failed. Check server connectivity.').show();
      }).always(function() {
        btn.prop('disabled', false);
        $('#btn-save-vtypes').prop('disabled', false);
        $('#vtypes-loading').hide();
      });
    });

    // Uncheck: confirm, then just uncheck (row stays in table)
    $('#vtypes-table').on('change', '.vt-check', function() {
      var cb = $(this);
      if (!cb.is(':checked')) {
        var name = cb.closest('tr').find('.vt-api-name').text() || cb.closest('tr').data('id');
        if (!confirm('Deselect "' + name + '" from the storage policy selection?')) {
          cb.prop('checked', true);
        }
      }
    });

    // Check-all header: check or uncheck all visible rows (no removal)
    $('#check-all-vtypes').on('change', function() {
      var checked = $(this).is(':checked');
      $('#vtypes-table tbody tr:visible').each(function() {
        $(this).find('.vt-check').prop('checked', checked);
      });
    });

    $('#btn-save-vtypes').on('click', function() {
      var saveBtn = $(this).prop('disabled', true);
      $('#btn-load-vtypes').prop('disabled', true);
      $('#vtypes-loading').show();
      $('#vtypes-loading-text').text('Saving configuration...');
      var ids = [], names = {};
      $('#vtypes-table tbody tr').each(function() {
        var row = $(this);
        if (row.find('.vt-check').is(':checked')) {
          var id = row.data('id');
          ids.push(id);
          names[id] = row.find('.vt-name').val();
        }
      });

      $.when(
        $.post(moduleUrl, { action: 'save_volume_types',      server_id: serverId, selected_volume_types: ids }, null, 'json'),
        $.post(moduleUrl, { action: 'save_volume_type_names', server_id: serverId, names: names               }, null, 'json')
      ).always(function() {
        savedVtIds = ids;
        saveBtn.prop('disabled', false);
        $('#btn-load-vtypes').prop('disabled', false);
        $('#vtypes-loading').hide();
        $('#vtypes-save-msg').text('Storage policy configuration saved.').removeClass('alert-danger').addClass('alert alert-success').show();
        setTimeout(function() { $('#vtypes-save-msg').hide(); }, 3000);
      });
    });
  }());
  </script>
  <?php
}

/**
 * Render the Create Config Group tab.
 *
 * Shows existing config groups and a form to create a new one.
 *
 * @param int    $serverId  Active server ID
 * @param string $moduleUrl Base module URL
 */
function cloudpe_cmp_admin_render_create_group(int $serverId, string $moduleUrl): void
{
  $savedImages  = cloudpe_cmp_admin_get_setting($serverId, 'selected_images', []);
  $savedFlavors = cloudpe_cmp_admin_get_setting($serverId, 'selected_flavors', []);
  $savedDisks   = cloudpe_cmp_admin_get_setting($serverId, 'disk_sizes', []);

  $cmpProducts = Capsule::table('tblproducts')
    ->where('servertype', 'cloudpe_cmp')
    ->orderBy('name')
    ->get(['id', 'name']);

  // Fetch configurable option groups that are linked to products using this module
  $existingGroups = Capsule::table('tblproductconfiggroups as g')
    ->join('tblproductconfiglinks as l', 'l.gid', '=', 'g.id')
    ->join('tblproducts as p', 'p.id', '=', 'l.pid')
    ->where('p.servertype', 'cloudpe_cmp')
    ->select('g.id', 'g.name')
    ->distinct()
    ->orderBy('g.name')
    ->get();
  ?>
  <div class="cmp-section">
    <h4>Configurable Options Groups</h4>
    <p class="text-muted">
      Create a WHMCS configurable options group pre-populated with your saved images, flavors,
      and disk sizes. Link the group to any product in
      <strong>Products/Services &rarr; [Product] &rarr; Configurable Options</strong>.
    </p>

    <!-- Create new group -->
    <div class="panel panel-default" style="margin-bottom:20px;">
      <div class="panel-heading"><h4 class="panel-title">Create New Group</h4></div>
      <div class="panel-body">
        <?php if (empty($savedImages) && empty($savedFlavors) && empty($savedDisks)): ?>
        <div class="alert alert-warning">
          No resources are configured yet. Please configure images, flavors, and disk sizes before creating a group.
        </div>
        <?php else: ?>
        <form id="create-group-form">
          <div class="form-group">
            <label for="group-name">Group Name</label>
            <input type="text" id="group-name" name="group_name" class="form-control"
                   placeholder="e.g. Linux VPS Options" required>
          </div>

          <div class="form-group">
            <label>Link to Products</label>
            <button type="button" class="btn btn-xs btn-default" id="cg-select-all">Select All</button>
            <button type="button" class="btn btn-xs btn-default" id="cg-select-none">Select None</button>
            <div style="max-height:200px; overflow-y:auto; border:1px solid #ddd; padding:10px; margin-top:5px;">
              <?php if (count($cmpProducts) === 0): ?>
                <p class="text-muted" style="margin:0;">No CloudPe CMP products found. Create a product first under <strong>Products/Services</strong>.</p>
              <?php else: foreach ($cmpProducts as $p): ?>
                <label style="display:block; font-weight:normal;">
                  <input type="checkbox" name="products" value="<?php echo (int)$p->id; ?>">
                  <?php echo htmlspecialchars($p->name); ?>
                </label>
              <?php endforeach; endif; ?>
            </div>
          </div>

          <div class="form-group">
            <label>Include Options</label><br>
            <label style="font-weight:normal;"><input type="checkbox" name="include_os" checked>
              Operating System (<?php echo count((array)$savedImages); ?> image<?php echo count((array)$savedImages) === 1 ? '' : 's'; ?>)</label><br>
            <label style="font-weight:normal;"><input type="checkbox" name="include_size" checked>
              Server Size (<?php echo count((array)$savedFlavors); ?> flavor<?php echo count((array)$savedFlavors) === 1 ? '' : 's'; ?>)</label><br>
            <label style="font-weight:normal;"><input type="checkbox" name="include_disk" checked>
              Disk Space (<?php echo count((array)$savedDisks); ?> size<?php echo count((array)$savedDisks) === 1 ? '' : 's'; ?>)</label>
          </div>

          <div class="form-group">
            <label>Billing Cycle Multipliers (from monthly)</label>
            <div class="row">
              <div class="col-md-2"><label class="small">Quarterly</label><input type="number" step="0.1" name="mult_q" class="form-control input-sm" value="3"></div>
              <div class="col-md-2"><label class="small">Semi-Annual</label><input type="number" step="0.1" name="mult_s" class="form-control input-sm" value="6"></div>
              <div class="col-md-2"><label class="small">Annual</label><input type="number" step="0.1" name="mult_a" class="form-control input-sm" value="12"></div>
              <div class="col-md-2"><label class="small">Biennial</label><input type="number" step="0.1" name="mult_b" class="form-control input-sm" value="24"></div>
              <div class="col-md-2"><label class="small">Triennial</label><input type="number" step="0.1" name="mult_t" class="form-control input-sm" value="36"></div>
            </div>
          </div>

          <button type="submit" class="btn btn-success" id="btn-create-group">
            <i class="fa fa-plus-circle"></i> Create Configurable Options Group
          </button>
          <button type="button" class="btn btn-warning" id="btn-repair-data">
            <i class="fa fa-wrench"></i> Repair Data
          </button>
          <div id="create-group-msg" style="display:none; margin-top:10px;"></div>
          <div id="repair-result" style="margin-top:10px;"></div>
        </form>
        <?php endif; ?>
      </div>
    </div>

    <!-- Existing groups -->
    <?php if (!empty((array)$existingGroups)): ?>
    <div class="panel panel-default" style="margin-bottom:20px;">
      <div class="panel-heading">
        <h4 class="panel-title">
          Existing Configurable Option Groups
          <a href="configproductoptions.php" target="_blank" class="btn btn-xs btn-default pull-right">
            <i class="fa fa-external-link"></i> View all
          </a>
        </h4>
      </div>
      <style>
        #cmp-groups-table tbody tr { cursor: pointer; }
        #cmp-groups-table tbody tr:hover { background-color: #f5f5f5; }
      </style>
      <table id="cmp-groups-table" class="table table-striped" style="margin-bottom:0; table-layout:fixed; width:100%;"
             title="Click a row to edit the group">
        <colgroup>
          <col style="width:60px;">
          <col>
          <col style="width:120px;">
          <col style="width:160px;">
        </colgroup>
        <thead>
          <tr>
            <th>ID</th>
            <th>Name</th>
            <th>Options</th>
            <th>Linked Products</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($existingGroups as $grp):
            $optionCount = Capsule::table('tblproductconfigoptions')->where('gid', $grp->id)->count();
            $linkedCount = Capsule::table('tblproductconfiglinks')->where('gid', $grp->id)->count();
            $groupUrl    = 'configproductoptions.php?action=managegroup&id=' . (int)$grp->id;
          ?>
          <tr data-href="<?php echo htmlspecialchars($groupUrl); ?>">
            <td><?php echo (int)$grp->id; ?></td>
            <td><?php echo htmlspecialchars($grp->name); ?></td>
            <td><?php echo (int)$optionCount; ?></td>
            <td><?php echo (int)$linkedCount; ?></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
      <script>
        (function() {
          $('#cmp-groups-table tbody').on('click', 'tr[data-href]', function() {
            window.open($(this).data('href'), '_blank');
          });
        }());
      </script>
    </div>
    <?php endif; ?>
  </div>

  <script>
  (function() {
    var serverId  = <?php echo $serverId; ?>;
    var moduleUrl = '<?php echo $moduleUrl; ?>';

    $('#cg-select-all').on('click', function()  { $('input[name="products"]').prop('checked', true);  });
    $('#cg-select-none').on('click', function() { $('input[name="products"]').prop('checked', false); });

    $('#btn-repair-data').on('click', function() {
      if (!confirm('This will repair stored settings by fixing Unicode/HTML-entity quote characters. Continue?')) return;
      var btn = $(this).prop('disabled', true);
      $('#repair-result').html('<p><i class="fa fa-spinner fa-spin"></i> Repairing data...</p>');
      $.post(moduleUrl, { action: 'repair_settings', server_id: serverId }, function(r) {
        btn.prop('disabled', false);
        var html = '';
        if (r.success) {
          if ((r.repaired_count || 0) > 0) {
            html = '<div class="alert alert-success"><i class="fa fa-check"></i> Repaired ' + r.repaired_count + ' setting(s).</div>' +
                   '<pre style="max-height:200px; overflow:auto;">' + $('<div>').text(JSON.stringify(r.repaired, null, 2)).html() + '</pre>';
          } else {
            html = '<div class="alert alert-info"><i class="fa fa-info-circle"></i> No settings needed repair — all data is valid.</div>';
          }
        } else {
          html = '<div class="alert alert-danger"><i class="fa fa-times"></i> ' + (r.error || r.message || 'Repair failed.') + '</div>';
        }
        $('#repair-result').html(html);
      }, 'json').fail(function() {
        btn.prop('disabled', false);
        $('#repair-result').html('<div class="alert alert-danger">Request failed.</div>');
      });
    });

    $('#create-group-form').on('submit', function(e) {
      e.preventDefault();

      var groupName = $('#group-name').val().trim();
      if (!groupName) { alert('Please enter a group name.'); return; }

      var products = [];
      $('input[name="products"]:checked').each(function() { products.push($(this).val()); });
      if (products.length === 0) { alert('Please select at least one product to link.'); return; }

      var includeOs   = $('input[name="include_os"]').is(':checked')   ? 1 : 0;
      var includeSize = $('input[name="include_size"]').is(':checked') ? 1 : 0;
      var includeDisk = $('input[name="include_disk"]').is(':checked') ? 1 : 0;
      if (!includeOs && !includeSize && !includeDisk) {
        alert('Please include at least one option type (OS, Size, or Disk).');
        return;
      }

      var btn = $('#btn-create-group').prop('disabled', true).html('<i class="fa fa-spinner fa-spin"></i> Creating...');

      $.post(moduleUrl, {
        action:       'create_config_group',
        server_id:    serverId,
        group_name:   groupName,
        products:     products,
        include_os:   includeOs,
        include_size: includeSize,
        include_disk: includeDisk,
        mult_q:       $('input[name="mult_q"]').val(),
        mult_s:       $('input[name="mult_s"]').val(),
        mult_a:       $('input[name="mult_a"]').val(),
        mult_b:       $('input[name="mult_b"]').val(),
        mult_t:       $('input[name="mult_t"]').val()
      }, function(resp) {
        btn.prop('disabled', false).html('<i class="fa fa-plus-circle"></i> Create Configurable Options Group');
        var msg = $('#create-group-msg');
        if (resp.success) {
          msg.html(resp.message + ' <a href="configproductoptions.php" target="_blank">View groups &rarr;</a>')
             .removeClass('alert-danger').addClass('alert alert-success').show();
          setTimeout(function() { location.reload(); }, 2500);
        } else {
          msg.text(resp.message || 'Failed to create group.').removeClass('alert-success').addClass('alert alert-danger').show();
        }
      }, 'json').fail(function() {
        btn.prop('disabled', false).html('<i class="fa fa-plus-circle"></i> Create Configurable Options Group');
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
  $currentVersion = CLOUDPE_CMP_MODULE_VERSION;
  ?>
  <div class="panel panel-default">
    <div class="panel-heading"><h3 class="panel-title"><i class="fa fa-cloud-download"></i> Module Updates</h3></div>
    <div class="panel-body">

      <div class="row">
        <div class="col-md-6">
          <h4>Current Installation</h4>
          <table class="table table-bordered">
            <tr><td><strong>Module Version</strong></td><td><span class="label label-primary"><?php echo htmlspecialchars($currentVersion); ?></span></td></tr>
            <tr><td><strong>PHP Version</strong></td><td><?php echo PHP_VERSION; ?></td></tr>
            <tr><td><strong>WHMCS Version</strong></td><td><?php echo htmlspecialchars($GLOBALS['CONFIG']['Version'] ?? 'Unknown'); ?></td></tr>
          </table>
        </div>
        <div class="col-md-6">
          <h4>Update Status</h4>
          <div id="update-status">
            <p><i class="fa fa-spinner fa-spin"></i> Checking for updates...</p>
          </div>
        </div>
      </div>

      <hr>

      <div id="update-actions" style="display:none;">
        <h4>Available Update</h4>
        <div id="update-info"></div>
        <button type="button" class="btn btn-success btn-lg" id="btn-install-update" style="display:none;">
          <i class="fa fa-download"></i> Download &amp; Install Update
        </button>
        <div id="update-progress" style="display:none; margin-top:15px;">
          <div class="progress"><div class="progress-bar progress-bar-striped active" style="width:100%">Installing...</div></div>
        </div>
      </div>

      <div id="update-result" style="display:none; margin-top:15px;"></div>

    </div>
  </div>

  <div class="panel panel-default">
    <div class="panel-heading"><h3 class="panel-title"><i class="fa fa-upload"></i> Manual Install</h3></div>
    <div class="panel-body">
      <p class="text-muted">
        If automatic download fails (firewall, proxy timeout, etc.), download the ZIP from
        <a href="https://github.com/Leapswitch-Networks/cloudpe-cmp-whmcs/releases" target="_blank">GitHub Releases</a>
        and upload it here.
      </p>
      <div style="display:flex; align-items:center; gap:10px; flex-wrap:wrap;">
        <input type="file" id="manual-zip-file" accept=".zip" class="form-control" style="width:auto; max-width:320px;">
        <button class="btn btn-primary" id="btn-manual-install">
          <i class="fa fa-upload"></i> Upload &amp; Install
        </button>
        <span id="manual-install-loading" style="display:none;"><i class="fa fa-spinner fa-spin"></i> Installing...</span>
      </div>
      <div id="manual-install-result" style="display:none; margin-top:10px;"></div>
    </div>
  </div>

  <div class="panel panel-default">
    <div class="panel-heading"><h3 class="panel-title"><i class="fa fa-history"></i> All Releases</h3></div>
    <div class="panel-body">
      <p class="text-muted">View all available releases with release notes. You can install any version including downgrades.</p>
      <button class="btn btn-default" onclick="loadAllReleases()"><i class="fa fa-refresh"></i> Load Releases</button>
      <div id="all-releases-container" style="margin-top:15px;"></div>
    </div>
  </div>

  <script>
  var cmpModuleUrl     = '<?php echo addslashes($moduleUrl); ?>';
  var cmpCurrentVer    = '<?php echo addslashes($currentVersion); ?>';
  var cmpDownloadUrl   = '';

  $(document).ready(function() {
    checkForUpdates();
    loadAllReleases();
  });

  function checkForUpdates() {
    $.ajax({
      url: cmpModuleUrl,
      type: 'POST',
      data: { action: 'check_update' },
      dataType: 'json',
      success: function(data) {
        if (data.success) {
          if (data.update_available) {
            $('#update-status').html(
              '<div class="alert alert-warning">' +
              '<i class="fa fa-exclamation-triangle"></i> ' +
              '<strong>Update Available!</strong> Version ' + escapeHtml(data.latest_version) + ' is available.' +
              '</div>'
            );

            var info = '<table class="table table-bordered">';
            info += '<tr><td><strong>Latest Version</strong></td><td><span class="label label-success">' + escapeHtml(data.latest_version) + '</span></td></tr>';
            info += '<tr><td><strong>Your Version</strong></td><td><span class="label label-default">' + escapeHtml(data.current_version) + '</span></td></tr>';
            info += '<tr><td><strong>Released</strong></td><td>' + escapeHtml(data.released || '') + '</td></tr>';
            info += '</table>';

            if (data.changelog && data.changelog.length > 0) {
              info += '<h5>Changelog:</h5><ul>';
              data.changelog.forEach(function(item) {
                info += '<li>' + escapeHtml(item) + '</li>';
              });
              info += '</ul>';
            }

            $('#update-info').html(info);
            $('#update-actions').show();
            cmpDownloadUrl = data.download_url || '';
            if (cmpDownloadUrl) {
              $('#btn-install-update').show();
            }
          } else {
            $('#update-status').html(
              '<div class="alert alert-success">' +
              '<i class="fa fa-check-circle"></i> ' +
              '<strong>Up to date!</strong> You are running the latest version (' + escapeHtml(data.current_version) + ').' +
              '</div>'
            );
          }
        } else {
          $('#update-status').html(
            '<div class="alert alert-danger">' +
            '<i class="fa fa-times-circle"></i> ' +
            'Failed to check for updates: ' + escapeHtml(data.error || 'Unknown error') +
            '</div>' +
            '<button class="btn btn-default" onclick="checkForUpdates()">Retry</button>'
          );
        }
      },
      error: function() {
        $('#update-status').html(
          '<div class="alert alert-danger">' +
          '<i class="fa fa-times-circle"></i> Failed to connect to update server.' +
          '</div>' +
          '<button class="btn btn-default" onclick="checkForUpdates()">Retry</button>'
        );
      }
    });
  }

  $('#btn-install-update').on('click', function() {
    if (!cmpDownloadUrl) {
      alert('No download URL found. Please check for updates first.');
      return;
    }
    if (!confirm('Install the update now? A backup will be created. Continue?')) {
      return;
    }
    $('#btn-install-update').prop('disabled', true).html('<i class="fa fa-spinner fa-spin"></i> Installing...');
    $('#update-progress').show();

    $.ajax({
      url: cmpModuleUrl,
      type: 'POST',
      data: { action: 'install_update', download_url: cmpDownloadUrl },
      dataType: 'json',
      success: function(resp) {
        $('#btn-install-update').prop('disabled', false).html('<i class="fa fa-download"></i> Download &amp; Install Update');
        $('#update-progress').hide();

        // Include per-file diagnostic stats in the result message
        var detail = '';
        if (resp.stats) {
          detail = ' (' + resp.stats.files_written + ' written, '
            + resp.stats.files_failed + ' failed, '
            + resp.stats.opcache_invalidated + ' opcache cleared)';
          if (resp.stats.failures && resp.stats.failures.length) {
            detail += '<br><small>' + resp.stats.failures.slice(0, 5).map(function(f) {
              return escapeHtml(f);
            }).join('<br>') + '</small>';
          }
          if (resp.stats.backup_path) {
            detail += '<br><small class="text-muted">Backup: ' + escapeHtml(resp.stats.backup_path) + '</small>';
          }
        }

        if (resp.success) {
          $('#update-result').html(
            '<div class="alert alert-success"><i class="fa fa-check"></i> ' +
            escapeHtml(resp.message || 'Update installed.') + detail +
            ' Reloading in <strong><span id="cmp-reload-countdown">3</span>s</strong>...</div>'
          ).show();
          cmpStartReloadCountdown('cmp-reload-countdown');
        } else {
          $('#update-result').html(
            '<div class="alert alert-danger"><i class="fa fa-times"></i> ' +
            escapeHtml(resp.message || 'Update failed.') + detail + '</div>'
          ).show();
          $('#btn-install-update').show();
        }
      },
      error: function(xhr) {
        $('#btn-install-update').prop('disabled', false).html('<i class="fa fa-download"></i> Download &amp; Install Update');
        $('#update-progress').hide();
        var preview = xhr.responseText ? xhr.responseText.substring(0, 400) : '(empty response)';
        $('#update-result').html(
          '<div class="alert alert-danger">' +
          '<strong>Install request failed.</strong> HTTP ' + xhr.status + '.<br>' +
          'The server may have timed out or the response was not valid JSON. ' +
          'Try the <strong>Manual Install</strong> panel above instead.<br>' +
          '<small class="text-muted">Response preview: ' + escapeHtml(preview) + '</small>' +
          '</div>'
        ).show();
      }
    });
  });

  function loadAllReleases() {
    $('#all-releases-container').html('<p><i class="fa fa-spinner fa-spin"></i> Loading releases from GitHub...</p>');

    $.ajax({
      url: cmpModuleUrl,
      type: 'POST',
      data: { action: 'get_releases' },
      dataType: 'json',
      success: function(data) {
        if (data.success && Array.isArray(data.releases) && data.releases.length) {
          renderAllReleases(data.releases);
        } else {
          $('#all-releases-container').html(
            data.success
              ? '<p class="text-muted">No releases found.</p>'
              : '<div class="alert alert-danger"><i class="fa fa-times-circle"></i> Failed to load releases: ' + escapeHtml(data.error || '') + '</div>'
          );
        }
      },
      error: function() {
        $('#all-releases-container').html('<div class="alert alert-danger"><i class="fa fa-times-circle"></i> Failed to connect to GitHub.</div>');
      }
    });
  }

  function renderAllReleases(releases) {
    var html = '<div class="panel-group" id="releases-accordion">';

    releases.forEach(function(release, index) {
      var ver        = release.version || '';
      var isInstalled = (ver === cmpCurrentVer);
      var isNewer     = compareVersions(ver, cmpCurrentVer) > 0;
      var isOlder     = compareVersions(ver, cmpCurrentVer) < 0;

      var versionBadge = '';
      if (isInstalled)      versionBadge = ' <span class="label label-success">Installed</span>';
      else if (isNewer)     versionBadge = ' <span class="label label-warning">Newer</span>';
      else if (isOlder)     versionBadge = ' <span class="label label-default">Older</span>';
      if (release.prerelease) versionBadge += ' <span class="label label-info">Pre-release</span>';

      var panelClass = isInstalled ? 'panel-success' : (isNewer ? 'panel-warning' : 'panel-default');

      html += '<div class="panel ' + panelClass + '">';
      html += '<div class="panel-heading" style="cursor:pointer;" data-toggle="collapse" data-target="#release-' + index + '">';
      html += '<h4 class="panel-title">';
      html += '<i class="fa fa-tag"></i> v' + escapeHtml(ver) + versionBadge;
      html += '<span class="pull-right text-muted" style="font-size:12px; font-weight:normal;">' + escapeHtml(release.published_at || '') + '</span>';
      html += '</h4></div>';

      html += '<div id="release-' + index + '" class="panel-collapse collapse' + (index === 0 ? ' in' : '') + '">';
      html += '<div class="panel-body">';

      if (release.name && release.name !== release.tag) {
        html += '<h5>' + escapeHtml(release.name) + '</h5>';
      }

      if (release.body) {
        html += '<div class="well well-sm" style="word-break:break-word; overflow-wrap:anywhere; overflow:hidden; font-family:inherit; margin-bottom:10px;">'
          + formatReleaseNotes(release.body) + '</div>';
      } else {
        html += '<p class="text-muted">No release notes available.</p>';
      }

      html += '<div>';
      if (!isInstalled && release.download_url) {
        var btnClass = isNewer ? 'btn-success' : 'btn-warning';
        var btnText  = isNewer ? 'Upgrade to v' + escapeHtml(ver) : 'Downgrade to v' + escapeHtml(ver);
        html += '<button class="btn ' + btnClass + '" onclick="installVersion(\'' + escapeHtml(release.download_url).replace(/'/g, "\\'") + '\', \'' + escapeHtml(ver).replace(/'/g, "\\'") + '\')">'
          + '<i class="fa fa-download"></i> ' + btnText + '</button> ';
      } else if (isInstalled) {
        html += '<button class="btn btn-default" disabled><i class="fa fa-check"></i> Currently Installed</button> ';
      } else {
        html += '<button class="btn btn-default" disabled><i class="fa fa-ban"></i> No Download Available</button> ';
      }
      html += '<a href="' + escapeHtml(release.html_url || '#') + '" target="_blank" class="btn btn-default"><i class="fa fa-external-link"></i> View on GitHub</a>';
      html += '</div>';

      html += '</div></div></div>';
    });

    html += '</div>';
    $('#all-releases-container').html(html);
  }

  function installVersion(url, version) {
    var action = compareVersions(version, cmpCurrentVer) > 0 ? 'upgrade to' : 'downgrade to';
    if (!confirm('This will ' + action + ' version ' + version + '. A backup will be created. Continue?')) {
      return;
    }
    $('#all-releases-container').prepend(
      '<div id="install-progress-inline" class="alert alert-info">' +
      '<i class="fa fa-spinner fa-spin"></i> Installing v' + escapeHtml(version) + '... Please wait.' +
      '</div>'
    );
    $.ajax({
      url: cmpModuleUrl,
      type: 'POST',
      data: { action: 'install_update', download_url: url },
      dataType: 'json',
      success: function(resp) {
        $('#install-progress-inline').remove();
        var detail = '';
        if (resp.stats) {
          detail = ' (' + resp.stats.files_written + ' written, ' + resp.stats.files_failed + ' failed, ' + resp.stats.opcache_invalidated + ' opcache cleared)';
        }
        if (resp.success) {
          var countId = 'cmp-reload-countdown-' + Date.now();
          $('#all-releases-container').prepend(
            '<div class="alert alert-success"><i class="fa fa-check"></i> ' +
            escapeHtml(resp.message || 'Installed.') + detail +
            ' Reloading in <strong><span id="' + countId + '">3</span>s</strong>...</div>'
          );
          cmpStartReloadCountdown(countId);
        } else {
          $('#all-releases-container').prepend(
            '<div class="alert alert-danger"><i class="fa fa-times"></i> ' +
            escapeHtml(resp.message || 'Failed.') + detail + '</div>'
          );
        }
      },
      error: function(xhr) {
        $('#install-progress-inline').remove();
        var preview = xhr.responseText ? xhr.responseText.substring(0, 400) : '(empty response)';
        $('#all-releases-container').prepend(
          '<div class="alert alert-danger"><i class="fa fa-times-circle"></i> ' +
          '<strong>Install request failed.</strong> HTTP ' + xhr.status + '. ' +
          'The server may have timed out. Try the <strong>Manual Install</strong> panel instead.<br>' +
          '<small class="text-muted">Response preview: ' + escapeHtml(preview) + '</small>' +
          '</div>'
        );
      }
    });
  }

  function compareVersions(v1, v2) {
    // Split "1.2.3-beta.7" into base "1.2.3" and pre-release "beta.7"
    var parse = function(v) {
      var idx  = (v || '').indexOf('-');
      var base = idx === -1 ? v : v.slice(0, idx);
      var pre  = idx === -1 ? '' : v.slice(idx + 1); // e.g. "beta.7"
      return { base: base.split('.').map(Number), pre: pre };
    };

    var a = parse(v1), b = parse(v2);

    // 1. Compare base version numbers
    for (var i = 0; i < Math.max(a.base.length, b.base.length); i++) {
      var n1 = a.base[i] || 0, n2 = b.base[i] || 0;
      if (n1 > n2) return 1;
      if (n1 < n2) return -1;
    }

    // 2. Stable release > pre-release of the same base
    if (!a.pre && b.pre)  return 1;
    if (a.pre  && !b.pre) return -1;
    if (!a.pre && !b.pre) return 0;

    // 3. Both pre-release: compare segment by segment ("beta.7" vs "beta.6")
    var pa = a.pre.split('.'), pb = b.pre.split('.');
    for (var j = 0; j < Math.max(pa.length, pb.length); j++) {
      var sa = pa[j] !== undefined ? pa[j] : '0';
      var sb = pb[j] !== undefined ? pb[j] : '0';
      var na = parseInt(sa, 10), nb = parseInt(sb, 10);
      // If both segments are pure numbers compare numerically, else lexically
      if (!isNaN(na) && !isNaN(nb)) {
        if (na > nb) return 1;
        if (na < nb) return -1;
      } else {
        if (sa > sb) return 1;
        if (sa < sb) return -1;
      }
    }
    return 0;
  }

  function escapeHtml(text) {
    var d = document.createElement('div');
    d.appendChild(document.createTextNode(text || ''));
    return d.innerHTML;
  }

  function formatReleaseNotes(text) {
    var escaped = escapeHtml(text);
    escaped = escaped.replace(/\*\*(.+?)\*\*/g, '<strong>$1</strong>');
    escaped = escaped.replace(/^### (.+)$/gm, '<h5 style="margin:8px 0 4px;">$1</h5>');
    escaped = escaped.replace(/^## (.+)$/gm,  '<h4 style="margin:10px 0 5px;">$1</h4>');
    escaped = escaped.replace(/`([^`]+)`/g,   '<code>$1</code>');
    // Convert markdown list items and wrap in <ul> to avoid loose <li> elements
    escaped = escaped.replace(/((?:^- .+\n?)+)/gm, function(block) {
      var items = block.replace(/^- (.+)$/gm, '<li>$1</li>');
      return '<ul style="padding-left:20px; margin:4px 0;">' + items + '</ul>';
    });
    // Convert remaining newlines to <br> for readability
    escaped = escaped.replace(/\n/g, '<br>');
    return escaped;
  }

  // Manual ZIP upload installer
  $('#btn-manual-install').on('click', function() {
    var file = $('#manual-zip-file')[0].files[0];
    if (!file) { alert('Please select a ZIP file first.'); return; }
    if (!file.name.match(/\.zip$/i)) { alert('Please select a .zip file.'); return; }

    var formData = new FormData();
    formData.append('action',     'install_from_upload');
    formData.append('module_zip', file);

    var btn = $(this).prop('disabled', true);
    $('#manual-install-loading').show();
    $('#manual-install-result').hide();

    $.ajax({
      url:         cmpModuleUrl,
      type:        'POST',
      data:        formData,
      dataType:    'json',
      processData: false,
      contentType: false,
      success: function(resp) {
        btn.prop('disabled', false);
        $('#manual-install-loading').hide();
        var detail = '';
        if (resp.stats) {
          detail = ' (' + resp.stats.files_written + ' written, ' + resp.stats.files_failed + ' failed, ' + resp.stats.opcache_invalidated + ' opcache cleared)';
        }
        if (resp.success) {
          var countId = 'cmp-manual-countdown-' + Date.now();
          $('#manual-install-result').html(
            '<div class="alert alert-success"><i class="fa fa-check"></i> ' +
            escapeHtml(resp.message || 'Installed.') + detail +
            ' Reloading in <strong><span id="' + countId + '">3</span>s</strong>...</div>'
          ).show();
          cmpStartReloadCountdown(countId);
        } else {
          $('#manual-install-result').html(
            '<div class="alert alert-danger"><i class="fa fa-times"></i> ' +
            escapeHtml(resp.message || 'Install failed.') + detail + '</div>'
          ).show();
        }
      },
      error: function(xhr) {
        btn.prop('disabled', false);
        $('#manual-install-loading').hide();
        var preview = xhr.responseText ? xhr.responseText.substring(0, 300) : '(empty)';
        $('#manual-install-result').html(
          '<div class="alert alert-danger"><i class="fa fa-times"></i> ' +
          'Upload request failed (HTTP ' + xhr.status + '). ' +
          'Check <code>upload_max_filesize</code> and <code>post_max_size</code> in php.ini.<br>' +
          '<small class="text-muted">' + escapeHtml(preview) + '</small></div>'
        ).show();
      }
    });
  });

  // Countdown timer that updates the element with id=countId then reloads the page.
  function cmpStartReloadCountdown(countId) {
    var n = 3;
    var t = setInterval(function() {
      n--;
      var el = document.getElementById(countId);
      if (el) el.textContent = n;
      if (n <= 0) {
        clearInterval(t);
        location.reload();
      }
    }, 1000);
  }
  </script>
  <?php
}

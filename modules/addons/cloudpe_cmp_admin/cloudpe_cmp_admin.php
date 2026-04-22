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

define('CLOUDPE_CMP_MODULE_VERSION', '1.1.1');
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
function cloudpe_cmp_admin_load_images(int $serverId, string $regionId = ''): array
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
    $result = $api->listImages('', $regionId);

    if (!$result['success']) {
      $msg = $result['error'] ?? 'Failed to load images.';
      if (!empty($result['httpCode'])) {
        $msg = 'HTTP ' . $result['httpCode'] . ': ' . $msg;
      }
      return ['success' => false, 'error' => $msg];
    }

    $images = [];
    foreach ((array)($result['images'] ?? []) as $img) {
      $id = $img['id'] ?? $img['slug'] ?? '';
      // Prefer a human-readable label over raw API name (which may be a UUID).
      $name = $img['display_name']
           ?? $img['os_name']
           ?? $img['label']
           ?? $img['title']
           ?? null;
      // If still unset, try composing from os_distro + os_version.
      if (!$name && !empty($img['os_distro'])) {
        $name = ucfirst($img['os_distro']);
        if (!empty($img['os_version'])) {
          $name .= ' ' . $img['os_version'];
        }
      }
      // Last resort: use the API name field (may be UUID) or the ID itself.
      if (!$name || $name === $id) {
        $name = $img['name'] ?? $id;
      }
      $images[] = ['id' => $id, 'name' => $name];
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
function cloudpe_cmp_admin_load_flavors(int $serverId, string $regionId = ''): array
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
    $result = $api->listFlavors(false, $regionId);

    if (!$result['success']) {
      $msg = $result['error'] ?? 'Failed to load flavors.';
      if (!empty($result['httpCode'])) {
        $msg = 'HTTP ' . $result['httpCode'] . ': ' . $msg;
      }
      return ['success' => false, 'error' => $msg];
    }

    $flavors = [];
    foreach ((array)($result['flavors'] ?? []) as $flv) {
      $id = $flv['id'] ?? $flv['slug'] ?? '';
      // RAM: CMP API commonly returns MB (`ram`, `ram_mb`, `memory_mb`).
      // Some responses use `memory_gb`/`ram_gb`. Prefer MB→GB conversion,
      // fall back to GB fields if no MB source is present.
      $ramMb = $flv['ram_mb'] ?? $flv['memory_mb'] ?? $flv['ram'] ?? null;
      $ramGb = $flv['memory_gb'] ?? $flv['ram_gb'] ?? $flv['memory'] ?? null;
      $memoryGb = 0;
      if ($ramMb !== null && (float)$ramMb > 0) {
        $memoryGb = round((float)$ramMb / 1024, 1);
      } elseif ($ramGb !== null) {
        $memoryGb = (float)$ramGb;
      }
      $flavors[] = [
        'id'        => $id,
        'name'      => $flv['name'] ?? $flv['display_name'] ?? $id,
        'vcpu'      => (int)($flv['vcpus'] ?? $flv['vcpu'] ?? $flv['cpu'] ?? 0),
        'memory_gb' => $memoryGb,
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
function cloudpe_cmp_admin_load_projects(int $serverId, string $regionId = ''): array
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
    $result = $api->listProjects($regionId);

    if (!$result['success']) {
      return ['success' => false, 'error' => $result['error'] ?? 'Failed to load projects.'];
    }

    $projects = [];
    foreach ((array)($result['projects'] ?? []) as $proj) {
      $projRegion = $proj['region_id'] ?? $proj['region'] ?? '';
      // Client-side filter: if a region was requested and the project has a
      // region field, only include projects that match.
      if ($regionId !== '' && $projRegion !== '' && $projRegion !== $regionId) {
        continue;
      }
      $projects[] = [
        'id'        => $proj['id'] ?? $proj['uuid'] ?? '',
        'name'      => $proj['name'] ?? $proj['display_name'] ?? $proj['id'] ?? '',
        'region_id' => $projRegion,
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
function cloudpe_cmp_admin_load_security_groups(int $serverId, string $projectId = '', string $regionId = ''): array
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
    $result = $api->listSecurityGroups($projectId, $regionId);

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
 * Load available regions from the CMP API.
 *
 * @param int    $serverId WHMCS server ID
 * @param string $service  Optional service filter (e.g. 'vm')
 * @return array  Keys: success (bool), regions (array)|error (string)
 */
function cloudpe_cmp_admin_load_regions(int $serverId, string $service = 'vm'): array
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
    $result = $api->listRegions($service);

    if (!$result['success']) {
      return ['success' => false, 'error' => $result['error'] ?? 'Failed to load regions.'];
    }

    $regions = [];
    foreach ((array)($result['regions'] ?? []) as $r) {
      $regions[] = [
        'id'   => $r['id'] ?? $r['slug'] ?? '',
        'name' => $r['name'] ?? $r['display_name'] ?? $r['id'] ?? '',
      ];
    }

    return ['success' => true, 'regions' => $regions];
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
function cloudpe_cmp_admin_load_volume_types(int $serverId, string $regionId = ''): array
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

  if (empty($regionId)) {
    return ['success' => false, 'error' => 'Region is required. Please select a region before loading storage policies.'];
  }

  $params = [
    'serverhostname'   => $server->hostname,
    'serverpassword'   => decrypt($server->password),
    'serveraccesshash' => $server->accesshash,
    'serversecure'     => $server->secure,
  ];

  try {
    $api    = new CloudPeCmpAPI($params);
    $result = $api->listVolumeTypes($regionId);

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
 * Load flavor groups for a region.
 *
 * @param int    $serverId  WHMCS server ID
 * @param string $regionId  Region ID / slug
 * @return array Keys: success (bool), groups (array)|error (string)
 */
function cloudpe_cmp_admin_load_flavor_groups(int $serverId, string $regionId = ''): array
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
    $result = $api->listFlavorGroups($regionId);

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
          'optionname'=> $imageId . '|' . $displayName,
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
        $displayName = $flavorDisplayNames[$flavorId] ?? $flavorNames[$flavorId] ?? $flavorId;
        $price       = (float)($flavorPrices[$flavorId] ?? 0);

        $subId = Capsule::table('tblproductconfigoptionssub')->insertGetId([
          'configid'  => $sizeOptionId,
          'optionname'=> $flavorId . '|' . $displayName,
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
          'optionname'=> $diskSizeGb . '|' . $diskLabel,
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

    // Auto-assign the new group to every product using the cloudpe_cmp
    // server module so admins don't have to hop into
    // Setup -> Products/Services -> [Product] -> Configurable Options
    // and link it manually.
    $cmpProductIds = Capsule::table('tblproducts')
      ->where('servertype', 'cloudpe_cmp')
      ->pluck('id')
      ->all();
    $assignedCount = 0;
    foreach ($cmpProductIds as $pid) {
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

    $msg = 'Configurable options group "' . $groupName . '" created successfully.';
    if ($assignedCount > 0) {
      $msg .= ' Assigned to ' . $assignedCount . ' CloudPe CMP product(s).';
    } elseif (empty($cmpProductIds)) {
      $msg .= ' No CloudPe CMP products found to assign — create a product first, then link manually.';
    }

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

      case 'load_regions':
        $service = trim($_POST['service'] ?? 'vm');
        echo json_encode(cloudpe_cmp_admin_load_regions($serverId, $service));
        exit;

      case 'load_images':
        $regionId = trim($_POST['region_id'] ?? '');
        echo json_encode(cloudpe_cmp_admin_load_images($serverId, $regionId));
        exit;

      case 'load_flavors':
        $regionId = trim($_POST['region_id'] ?? '');
        echo json_encode(cloudpe_cmp_admin_load_flavors($serverId, $regionId));
        exit;

      case 'load_projects':
        $projRegionId = trim($_POST['region_id'] ?? '');
        echo json_encode(cloudpe_cmp_admin_load_projects($serverId, $projRegionId));
        exit;

      case 'load_security_groups':
        $sgProjectId = trim($_POST['project_id'] ?? '');
        $sgRegionId  = trim($_POST['region_id']  ?? '');
        echo json_encode(cloudpe_cmp_admin_load_security_groups($serverId, $sgProjectId, $sgRegionId));
        exit;

      case 'load_volume_types':
        $vtRegionId = trim($_POST['region_id'] ?? '');
        echo json_encode(cloudpe_cmp_admin_load_volume_types($serverId, $vtRegionId));
        exit;

      case 'load_flavor_groups':
        $fgRegionId = trim($_POST['region_id'] ?? '');
        echo json_encode(cloudpe_cmp_admin_load_flavor_groups($serverId, $fgRegionId));
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
        echo json_encode(['success' => true]);
        exit;

      case 'save_selected_regions':
        $selRegions = $_POST['selected_regions'] ?? [];
        $regNames   = $_POST['region_names'] ?? [];
        cloudpe_cmp_admin_save_setting($serverId, 'selected_regions', $selRegions);
        cloudpe_cmp_admin_save_setting($serverId, 'region_names', $regNames);
        echo json_encode(['success' => true]);
        exit;

      case 'save_default_region_project':
        $region  = trim($_POST['default_region']  ?? '');
        $project = trim($_POST['default_project'] ?? '');
        cloudpe_cmp_admin_save_setting($serverId, 'default_region', $region);
        cloudpe_cmp_admin_save_setting($serverId, 'default_project', $project);
        echo json_encode(['success' => true, 'message' => 'Default region and project saved.']);
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

      case 'save_image_regions':
        $regions = $_POST['regions'] ?? [];
        // Merge with existing so regions from other loads aren't lost
        $existing = cloudpe_cmp_admin_get_setting($serverId, 'image_regions', []) ?: [];
        cloudpe_cmp_admin_save_setting($serverId, 'image_regions', array_merge((array)$existing, $regions));
        echo json_encode(['success' => true]);
        exit;

      case 'save_flavor_regions':
        $regions = $_POST['regions'] ?? [];
        $existing = cloudpe_cmp_admin_get_setting($serverId, 'flavor_regions', []) ?: [];
        cloudpe_cmp_admin_save_setting($serverId, 'flavor_regions', array_merge((array)$existing, $regions));
        echo json_encode(['success' => true]);
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

    <!-- Server + Region selector -->
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
      <div style="display:flex; align-items:center; gap:8px;">
        <label style="margin:0;"><strong>Region:</strong></label>
        <select id="cmp-region-select" class="form-control" style="width:auto; min-width:160px;">
          <option value="">Loading regions...</option>
        </select>
      </div>
    </div>
    <script>
    // Global region state — shared across all tabs.
    window.cmpServerId  = <?php echo $serverId; ?>;
    window.cmpModuleUrl = '<?php echo $moduleUrl; ?>';
    window.cmpRegionId  = '';
    window.cmpRegions   = {};  // id → name

    $.post(window.cmpModuleUrl, { action: 'load_regions', server_id: window.cmpServerId, service: 'vm' }, function(resp) {
      var $sel = $('#cmp-region-select').empty();
      if (resp.success && resp.regions && resp.regions.length) {
        resp.regions.forEach(function(r) {
          window.cmpRegions[r.id] = r.name || r.id;
          $sel.append('<option value="' + $('<span>').text(r.id).html() + '">' + $('<span>').text(r.name || r.id).html() + '</option>');
        });
        window.cmpRegionId = resp.regions[0].id;
      } else {
        $sel.append('<option value="">No regions found</option>');
      }
      $(document).trigger('cmp:regions-loaded');
    }, 'json').fail(function() {
      $('#cmp-region-select').empty().append('<option value="">Failed to load</option>');
      $(document).trigger('cmp:regions-loaded');
    });

    function cmpFilterTablesByRegion(regionId) {
      ['#images-table', '#flavors-table'].forEach(function(tbl) {
        var n = 0;
        $(tbl + ' tbody tr').each(function() {
          var rowRegion = $(this).attr('data-region') || '';
          var show = !regionId || rowRegion === '' || rowRegion === regionId;
          $(this).toggle(show);
          $(this).find('.row-num').text(show ? ++n : '');
        });
      });
    }

    $('#cmp-region-select').on('change', function() {
      window.cmpRegionId = $(this).val();
      cmpFilterTablesByRegion(window.cmpRegionId);
    });

    $(document).on('cmp:regions-loaded', function() {
      cmpFilterTablesByRegion(window.cmpRegionId);
    });
    </script>

    <!-- Tab navigation -->
    <ul class="nav nav-tabs" role="tablist">
      <?php
      $tabs = [
        'dashboard'       => 'Dashboard',
        'images'          => 'Images',
        'flavors'         => 'Flavors',
        'disks'           => 'Disk Sizes',
        'additional'      => 'Additional',
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
        case 'additional':
          cloudpe_cmp_admin_render_additional($serverId, $moduleUrl);
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
          <th>Region</th>
          <th>Image ID</th>
          <th>Display Name</th>
          <th>Monthly Price</th>
        </tr>
      </thead>
      <tbody>
        <?php
        $imageRegions = (array)cloudpe_cmp_admin_get_setting($serverId, 'image_regions', []);
        foreach ($savedImages as $i => $imgId):
          $savedName   = $imageNames[$imgId] ?? '';
          $displayName = ($savedName !== '' && $savedName !== $imgId) ? $savedName : '';
          $region      = $imageRegions[$imgId] ?? '—';
        ?>
        <tr data-id="<?php echo htmlspecialchars($imgId); ?>" data-region="<?php echo htmlspecialchars($imageRegions[$imgId] ?? ''); ?>" data-saved="1">
          <td class="row-num"><?php echo $i + 1; ?></td>
          <td><input type="checkbox" class="img-check" checked></td>
          <td class="img-api-name"><?php echo htmlspecialchars($displayName); ?></td>
          <td class="img-region text-muted" style="white-space:nowrap;"><?php echo htmlspecialchars($region); ?></td>
          <td><small class="text-muted"><?php echo htmlspecialchars($imgId); ?></small></td>
          <td><input type="text" class="form-control input-sm img-name"
               value="<?php echo htmlspecialchars($displayName); ?>"></td>
          <td><input type="number" step="0.01" min="0" class="form-control input-sm img-price" style="width:90px;"
               value="<?php echo htmlspecialchars($imagePrices[$imgId] ?? '0'); ?>"></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    </div>

    <?php if (empty($savedImages)): ?>
    <p class="text-muted">No images configured yet. Click <strong>Load from API</strong> to fetch available images from all regions.</p>
    <?php endif; ?>
  </div>

  <script>
  (function() {
    var serverId  = <?php echo $serverId; ?>;
    var moduleUrl = '<?php echo $moduleUrl; ?>';

    var savedImageIds   = <?php echo json_encode(array_values($savedImages)); ?>;
    var savedNames      = <?php echo json_encode((object)($imageNames ?: new stdClass())); ?>;
    var savedPrices     = <?php echo json_encode((object)($imagePrices ?: new stdClass())); ?>;
    var savedImgRegions = <?php echo json_encode((object)($imageRegions ?: new stdClass())); ?>;

    // Resolve region IDs to names for saved rows — wait for global region map
    function resolveImgRegionNames() {
      $('#images-table tbody tr').each(function() {
        var rId = $(this).attr('data-region') || '';
        if (rId && window.cmpRegions[rId]) $(this).find('.img-region').text(window.cmpRegions[rId]);
      });
    }
    $(document).on('cmp:regions-loaded', resolveImgRegionNames);

    function reNumber() {
      var n = 0;
      $('#images-table tbody tr').each(function() {
        $(this).find('.row-num').text($(this).is(':visible') ? ++n : '');
      });
    }

    function addRows(items, regionId, regionLabel) {
      var existingIds = [];
      $('#images-table tbody tr').each(function() { existingIds.push(String($(this).data('id'))); });

      $.each(items, function(i, img) {
        if (existingIds.indexOf(String(img.id)) !== -1) return; // skip duplicates
        var isSaved = savedImageIds.indexOf(img.id) !== -1;
        var row = $('<tr>').attr('data-id', img.id).attr('data-region', regionId || '').attr('data-saved', '0');
        row.html(
          '<td class="row-num"></td>' +
          '<td><input type="checkbox" class="img-check"' + (isSaved ? ' checked' : '') + '></td>' +
          '<td class="img-api-name">' + $('<span>').text(img.name).html() + '</td>' +
          '<td class="img-region text-muted" style="white-space:nowrap;">' + $('<span>').text(regionLabel || regionId || '—').html() + '</td>' +
          '<td><small class="text-muted">' + $('<span>').text(img.id).html() + '</small></td>' +
          '<td><input type="text" class="form-control input-sm img-name" value="' +
            $('<span>').text(savedNames[img.id] || img.name).html() + '"></td>' +
          '<td><input type="number" step="0.01" min="0" class="form-control input-sm img-price" style="width:90px;" value="' +
            $('<span>').text(savedPrices[img.id] || '0').html() + '"></td>'
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

    // Load from API: fetch images for the currently selected region
    $('#btn-load-images').on('click', function() {
      var btn      = $(this).prop('disabled', true);
      var regionId = window.cmpRegionId || '';
      var regionLabel = window.cmpRegions[regionId] || regionId || '—';
      // Remove previously-loaded (unsaved) rows for this region so re-fetch is a clean refresh
      $('#images-table tbody tr[data-region="' + regionId + '"][data-saved="0"]').remove();
      $('#btn-save-images').prop('disabled', true);
      $('#images-loading').show();
      $('#images-loading-text').text('Loading images...');
      $('#images-error').hide();

      $.post(moduleUrl, { action: 'load_images', server_id: serverId, region_id: regionId }, function(resp) {
        if (resp.success && resp.images && resp.images.length) {
          addRows(resp.images, regionId, regionLabel);
        } else {
          $('#images-error').text(resp.error || 'No images returned for the selected region.').show();
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
      var ids = [], names = {}, prices = {}, regions = {};
      $('#images-table tbody tr').each(function() {
        var row = $(this);
        if (row.find('.img-check').is(':checked')) {
          var id = row.data('id');
          ids.push(id);
          names[id]   = row.find('.img-name').val();
          prices[id]  = row.find('.img-price').val();
          var rId = row.attr('data-region') || '';
          if (rId) regions[id] = rId;
        }
      });

      $.when(
        $.post(moduleUrl, { action: 'save_images',        server_id: serverId, selected_images: ids }, null, 'json'),
        $.post(moduleUrl, { action: 'save_image_names',   server_id: serverId, names: names         }, null, 'json'),
        $.post(moduleUrl, { action: 'save_image_prices',  server_id: serverId, prices: prices       }, null, 'json'),
        $.post(moduleUrl, { action: 'save_image_regions', server_id: serverId, regions: regions     }, null, 'json')
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
  ?>
  <div class="cmp-section">
    <h4>Flavors</h4>
    <div class="cmp-toolbar">
      <button class="btn btn-primary" id="btn-load-flavors">
        <i class="fa fa-refresh"></i> Load from API
      </button>
      <span id="flavors-loading" style="display:none;"><i class="fa fa-spinner fa-spin"></i> <span id="flavors-loading-text">Fetching regions...</span></span>
      <input type="text" id="flavors-search" class="form-control cmp-search" placeholder="Filter flavors...">
      <span id="flavors-saving" style="display:none;"><i class="fa fa-spinner fa-spin"></i> <span id="flavors-saving-text">Saving configuration...</span></span>
      <span id="flavors-save-msg" class="cmp-save-msg" style="display:none;"></span>
      <button class="btn btn-success" id="btn-save-flavors">
        <i class="fa fa-save"></i> Save Configuration
      </button>
    </div>
    <div id="flavors-error" class="alert alert-danger" style="display:none;"></div>

    <div class="cmp-table-wrap">
    <table class="table table-bordered cmp-resource-table" id="flavors-table">
      <thead>
        <tr>
          <th style="width:35px;">#</th>
          <th style="width:32px;"><input type="checkbox" id="check-all-flavors" title="Select/deselect all"></th>
          <th>Name</th>
          <th>Region</th>
          <th>vCPU</th>
          <th>RAM (GB)</th>
          <th>Flavor ID</th>
          <th>Display Name</th>
          <th>Monthly Price</th>
        </tr>
      </thead>
      <tbody>
        <?php
        $flavorRegions = (array)cloudpe_cmp_admin_get_setting($serverId, 'flavor_regions', []);
        foreach ($savedFlavors as $i => $flvId):
          $savedName   = $flavorNames[$flvId] ?? '';
          $displayName = ($savedName !== '' && $savedName !== $flvId) ? $savedName : '';
          $region      = $flavorRegions[$flvId] ?? '—';
        ?>
        <?php
          $spec     = (array)($flavorSpecs[$flvId] ?? []);
          $specVcpu = isset($spec['vcpu']) ? (int)$spec['vcpu'] : null;
          $specRam  = isset($spec['memory_gb']) ? (float)$spec['memory_gb'] : null;
        ?>
        <tr data-id="<?php echo htmlspecialchars($flvId); ?>" data-region="<?php echo htmlspecialchars($flavorRegions[$flvId] ?? ''); ?>" data-saved="1">
          <td class="row-num"><?php echo $i + 1; ?></td>
          <td><input type="checkbox" class="flv-check" checked></td>
          <td class="flv-api-name"><?php echo htmlspecialchars($flavorApiNames[$flvId] ?? $flvId); ?></td>
          <td class="flv-region text-muted" style="white-space:nowrap;"><?php echo htmlspecialchars($region); ?></td>
          <td class="flv-vcpu"><?php echo $specVcpu !== null ? $specVcpu : '—'; ?></td>
          <td class="flv-ram"><?php echo $specRam !== null ? $specRam : '—'; ?></td>
          <td><small class="text-muted"><?php echo htmlspecialchars($flvId); ?></small></td>
          <td><input type="text" class="form-control input-sm flv-name"
               value="<?php echo htmlspecialchars($displayName); ?>"></td>
          <td><input type="number" step="0.01" min="0" class="form-control input-sm flv-price" style="width:90px;"
               value="<?php echo htmlspecialchars($flavorPrices[$flvId] ?? '0'); ?>"></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    </div>

    <?php if (empty($savedFlavors)): ?>
    <p class="text-muted">No flavors configured yet. Click <strong>Load from API</strong> to fetch available flavors from all regions.</p>
    <?php endif; ?>
  </div>

  <script>
  (function() {
    var serverId  = <?php echo $serverId; ?>;
    var moduleUrl = '<?php echo $moduleUrl; ?>';

    var savedFlavorIds  = <?php echo json_encode(array_values($savedFlavors)); ?>;
    var savedNames      = <?php echo json_encode((object)($flavorNames ?: new stdClass())); ?>;
    var savedPrices     = <?php echo json_encode((object)($flavorPrices ?: new stdClass())); ?>;
    var savedFlvRegions = <?php echo json_encode((object)($flavorRegions ?: new stdClass())); ?>;

    // Build the auto Display Name shown to customers: "X vCPU, Y GB RAM".
    function autoDisplayName(flv) {
      var vcpu = parseInt(flv.vcpu) || 0;
      var ram  = parseFloat(flv.memory_gb) || 0;
      return vcpu + ' vCPU, ' + ram + ' GB RAM';
    }

    // Resolve region IDs to names for saved rows — wait for global region map
    function resolveFlvRegionNames() {
      $('#flavors-table tbody tr').each(function() {
        var rId = $(this).attr('data-region') || '';
        if (rId && window.cmpRegions[rId]) $(this).find('.flv-region').text(window.cmpRegions[rId]);
      });
    }
    $(document).on('cmp:regions-loaded', resolveFlvRegionNames);

    function reNumber() {
      var n = 0;
      $('#flavors-table tbody tr').each(function() {
        $(this).find('.row-num').text($(this).is(':visible') ? ++n : '');
      });
    }

    function addRows(items, regionId, regionLabel) {
      var existingIds = [];
      $('#flavors-table tbody tr').each(function() { existingIds.push(String($(this).data('id'))); });

      $.each(items, function(i, flv) {
        if (existingIds.indexOf(String(flv.id)) !== -1) {
          // Update vCPU, RAM, region for existing (saved) rows. Never
          // overwrite the admin-edited Display Name — they chose it.
          var er = $('#flavors-table tbody tr[data-id="' + flv.id + '"]');
          if (flv.vcpu    !== undefined) er.find('.flv-vcpu').text(parseInt(flv.vcpu) || 0);
          if (flv.memory_gb !== undefined) er.find('.flv-ram').text(parseFloat(flv.memory_gb) || 0);
          if (regionLabel || regionId) {
            er.find('.flv-region').text(regionLabel || regionId);
            er.attr('data-region', regionId || '');
          }
          // Only auto-fill the name when it is completely empty.
          var nameInput = er.find('.flv-name');
          if (!(nameInput.val() || '').trim()) {
            nameInput.val(autoDisplayName(flv));
          }
          return; // don't add duplicate row
        }
        var isSaved = savedFlavorIds.indexOf(flv.id) !== -1;
        var row = $('<tr>').attr('data-id', flv.id).attr('data-region', regionId || '').attr('data-saved', '0');
        var defaultName = savedNames[flv.id] || autoDisplayName(flv);
        row.html(
          '<td class="row-num"></td>' +
          '<td><input type="checkbox" class="flv-check"' + (isSaved ? ' checked' : '') + '></td>' +
          '<td class="flv-api-name">' + $('<span>').text(flv.name).html() + '</td>' +
          '<td class="flv-region text-muted" style="white-space:nowrap;">' + $('<span>').text(regionLabel || regionId || '—').html() + '</td>' +
          '<td class="flv-vcpu">' + (parseInt(flv.vcpu) || 0) + '</td>' +
          '<td class="flv-ram">' + (parseFloat(flv.memory_gb) || 0) + '</td>' +
          '<td><small class="text-muted">' + $('<span>').text(flv.id).html() + '</small></td>' +
          '<td><input type="text" class="form-control input-sm flv-name" value="' +
            $('<span>').text(defaultName).html() + '"></td>' +
          '<td><input type="number" step="0.01" min="0" class="form-control input-sm flv-price" style="width:90px;" value="' +
            $('<span>').text(savedPrices[flv.id] || '0').html() + '"></td>'
        );
        $('#flavors-table tbody').append(row);
      });
      reNumber();
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

    // Load from API: fetch flavors for the currently selected region
    $('#btn-load-flavors').on('click', function() {
      var btn         = $(this).prop('disabled', true);
      var regionId    = window.cmpRegionId || '';
      var regionLabel = window.cmpRegions[regionId] || regionId || '—';
      // Remove previously-loaded (unsaved) rows for this region so re-fetch is a clean refresh
      $('#flavors-table tbody tr[data-region="' + regionId + '"][data-saved="0"]').remove();
      $('#btn-save-flavors').prop('disabled', true);
      $('#flavors-loading').show();
      $('#flavors-loading-text').text('Loading flavors...');
      $('#flavors-error').hide();

      $.post(moduleUrl, { action: 'load_flavors', server_id: serverId, region_id: regionId }, function(fr) {
        if (fr && fr.success && fr.flavors && fr.flavors.length) {
          addRows(fr.flavors, regionId, regionLabel);
        } else {
          $('#flavors-error').text(fr.error || 'No flavors returned for the selected region.').show();
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
      var ids = [], names = {}, prices = {}, regions = {}, specs = {}, apiNames = {};
      $('#flavors-table tbody tr').each(function() {
        var row = $(this);
        if (row.find('.flv-check').is(':checked')) {
          var id = row.data('id');
          ids.push(id);
          names[id]    = row.find('.flv-name').val();
          prices[id]   = row.find('.flv-price').val();
          apiNames[id] = $.trim(row.find('.flv-api-name').text());
          var rId = row.attr('data-region') || '';
          if (rId) regions[id] = rId;
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
        $.post(moduleUrl, { action: 'save_flavor_regions',   server_id: serverId, regions: regions      }, null, 'json'),
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
    <span id="disks-loading" style="display:none; margin-left:10px;">
      <i class="fa fa-spinner fa-spin"></i> <span id="disks-loading-text">Saving configuration...</span>
    </span>
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
      var saveBtn = $(this).prop('disabled', true);
      $('#disks-loading').show();
      $('#disks-loading-text').text('Saving configuration...');

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
      }, 'json').always(function() {
        saveBtn.prop('disabled', false);
        $('#disks-loading').hide();
      });
    });
  }());
  </script>
  <?php
}

/**
 * Render the Additional tab.
 *
 * Two global dropdowns: Default Region and Default Project. Clients cannot
 * pick a region/project on the order form — admin sets these once, and
 * provisioning uses them for every VM created against this server.
 *
 * @param int    $serverId  Active server ID
 * @param string $moduleUrl Base module URL
 */
function cloudpe_cmp_admin_render_additional(int $serverId, string $moduleUrl): void
{
  $defaultRegion  = (string)cloudpe_cmp_admin_get_setting($serverId, 'default_region',  '');
  $defaultProject = (string)cloudpe_cmp_admin_get_setting($serverId, 'default_project', '');
  ?>
  <div class="cmp-section">
    <div class="cmp-toolbar">
      <span id="additional-loading" style="display:none;"><i class="fa fa-spinner fa-spin"></i> Loading projects...</span>
      <span class="cmp-spacer"></span>
      <span id="additional-save-msg" class="cmp-save-msg" style="display:none;"></span>
      <button class="btn btn-success" id="btn-save-additional">
        <i class="fa fa-save"></i> Save Configuration
      </button>
    </div>
    <div id="additional-error" class="alert alert-danger" style="display:none;"></div>

    <div class="cmp-table-wrap">
    <table class="table table-bordered cmp-resource-table" id="projects-table">
      <thead>
        <tr>
          <th style="width:32px;"></th>
          <th>Region</th>
          <th>Project</th>
        </tr>
      </thead>
      <tbody>
        <tr><td colspan="3" class="text-muted text-center">Loading...</td></tr>
      </tbody>
    </table>
    </div>
  </div>

  <script>
  (function() {
    var serverId       = <?php echo $serverId; ?>;
    var moduleUrl      = '<?php echo $moduleUrl; ?>';
    var defaultProject = <?php echo json_encode($defaultProject); ?>;
    var defaultRegion  = <?php echo json_encode($defaultRegion); ?>;

    function esc(s) { return $('<span>').text(s).html(); }

    function loadProjects() {
      var regionId    = window.cmpRegionId || '';
      var regionLabel = window.cmpRegions[regionId] || regionId || '—';
      $('#additional-loading').show();
      $('#additional-error').hide();

      $.post(moduleUrl, { action: 'load_projects', server_id: serverId, region_id: regionId }, function(pData) {
        $('#additional-loading').hide();
        var projects = (pData && pData.success) ? (pData.projects || []) : [];
        var $tbody = $('#projects-table tbody').empty();

        if (!projects.length) {
          $tbody.append('<tr><td colspan="3" class="text-muted text-center">No projects found for this region.</td></tr>');
          return;
        }

        projects.forEach(function(p) {
          var pRegionId   = p.region_id || regionId;
          var pRegionName = window.cmpRegions[pRegionId] || pRegionId || regionLabel;
          var isChecked   = (p.id === defaultProject && pRegionId === defaultRegion);
          $tbody.append(
            '<tr data-project-id="' + esc(p.id) + '" data-region-id="' + esc(pRegionId) + '">' +
            '<td><input type="radio" name="default_project" value="' + esc(p.id) + '"' + (isChecked ? ' checked' : '') + '></td>' +
            '<td>' + esc(pRegionName) + '</td>' +
            '<td>' + esc(p.name || p.id) + '</td>' +
            '</tr>'
          );
        });
      }, 'json').fail(function() {
        $('#additional-loading').hide();
        $('#additional-error').text('Failed to load projects. Check server connectivity.').show();
      });
    }

    // Load after global regions are ready (ensures cmpRegionId is set), reload on region change
    $(document).on('cmp:regions-loaded', loadProjects);
    $('#cmp-region-select').on('change', loadProjects);

    $('#btn-save-additional').on('click', function() {
      var btn = $(this).prop('disabled', true);
      $('#additional-save-msg').hide();

      var $checked = $('#projects-table tbody input[type="radio"]:checked');
      var project  = $checked.val() || '';
      var region   = $checked.closest('tr').data('region-id') || '';

      $.post(moduleUrl, {
        action:          'save_default_region_project',
        server_id:       serverId,
        default_region:  region,
        default_project: project,
      }, function(resp) {
        btn.prop('disabled', false);
        defaultProject = project;
        defaultRegion  = region;
        var msg = $('#additional-save-msg');
        if (resp.success) {
          msg.text(resp.message || 'Saved.').removeClass('error').show();
        } else {
          msg.text(resp.error || 'Save failed.').addClass('error').show();
        }
        setTimeout(function() { msg.fadeOut(); }, 3000);
      }, 'json').fail(function() {
        btn.prop('disabled', false);
        $('#additional-save-msg').text('Request failed.').addClass('error').show();
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
  $projectRegions = (array)cloudpe_cmp_admin_get_setting($serverId, 'project_regions', []);
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
          <th>Region</th>
          <th>Project ID</th>
          <th>Display Name</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($savedProjects as $i => $projId):
          $displayName = $projectNames[$projId] ?? $projId;
          $projRegion  = $projectRegions[$projId] ?? '—';
        ?>
        <tr data-id="<?php echo htmlspecialchars($projId); ?>" data-region="<?php echo htmlspecialchars($projectRegions[$projId] ?? ''); ?>">
          <td class="row-num"><?php echo $i + 1; ?></td>
          <td><input type="checkbox" class="proj-check" checked></td>
          <td class="proj-api-name"><?php echo htmlspecialchars($displayName); ?></td>
          <td class="proj-region text-muted" style="white-space:nowrap;"><?php echo htmlspecialchars($projRegion); ?></td>
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
    var savedProjRegions = <?php echo json_encode((object)($projectRegions ?: new stdClass())); ?>;
    var regionNames     = {}; // id -> display name

    // Background: load regions to resolve saved region IDs to names
    $.post(moduleUrl, { action: 'load_regions', server_id: serverId, service: 'vm' }, function(resp) {
      if (resp.success && resp.regions) {
        $.each(resp.regions, function(i, r) { regionNames[r.id] = r.name; });
        $('#projects-table tbody tr').each(function() {
          var rId = $(this).attr('data-region') || '';
          if (rId && regionNames[rId]) $(this).find('.proj-region').text(regionNames[rId]);
        });
      }
    }, 'json');

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
          var isSaved  = savedProjIds.indexOf(p.id) !== -1;
          var rId      = p.region_id || p.region || '';
          var rLabel   = (rId && regionNames[rId]) ? regionNames[rId] : (rId || '—');
          var row = $('<tr>').attr('data-id', p.id).attr('data-region', rId);
          row.html(
            '<td class="row-num"></td>' +
            '<td><input type="checkbox" class="proj-check"' + (isSaved ? ' checked' : '') + '></td>' +
            '<td class="proj-api-name">' + $('<span>').text(p.name).html() + '</td>' +
            '<td class="proj-region text-muted" style="white-space:nowrap;">' + $('<span>').text(rLabel).html() + '</td>' +
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
      var ids = [], names = {}, regions = {};
      $('#projects-table tbody tr').each(function() {
        var row = $(this);
        if (row.find('.proj-check').is(':checked')) {
          var id = row.data('id');
          ids.push(id);
          names[id] = row.find('.proj-name').val();
          var rId = row.attr('data-region') || '';
          if (rId) regions[id] = rId;
        }
      });

      $.when(
        $.post(moduleUrl, { action: 'save_projects',        server_id: serverId, selected_projects: ids }, null, 'json'),
        $.post(moduleUrl, { action: 'save_project_names',   server_id: serverId, names: names           }, null, 'json'),
        $.post(moduleUrl, { action: 'save_project_regions', server_id: serverId, regions: regions       }, null, 'json')
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
      // Load without region filter — security groups are project-scoped
      $.post(moduleUrl, { action: 'load_security_groups', server_id: serverId, project_id: projectId, region_id: '' }, function(resp) {
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
    <p class="text-muted">Volume types define the storage backend (e.g. SSD, NVMe). Loads from all regions automatically.</p>
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
    <p class="text-muted">No storage policies configured yet. Click <strong>Load from API</strong> to fetch available volume types from all regions.</p>
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

    // Load from API: fetch all regions, then load volume types per region
    $('#btn-load-vtypes').on('click', function() {
      var btn = $(this).prop('disabled', true);
      $('#btn-save-vtypes').prop('disabled', true);
      $('#vtypes-loading').show();
      $('#vtypes-loading-text').text('Fetching regions...');
      $('#vtypes-error').hide();

      $.post(moduleUrl, { action: 'load_regions', server_id: serverId, service: 'vm' }, function(regResp) {
        var regions = (regResp.success && regResp.regions && regResp.regions.length)
          ? regResp.regions : [];

        if (regions.length === 0) {
          btn.prop('disabled', false);
          $('#btn-save-vtypes').prop('disabled', false);
          $('#vtypes-loading').hide();
          $('#vtypes-error').text('No regions found. Cannot load volume types without a region.').show();
          return;
        }

        var total = regions.length, done = 0, anySuccess = false;

        $.each(regions, function(ri, region) {
          $.post(moduleUrl, { action: 'load_volume_types', server_id: serverId, region_id: region.id }, function(resp) {
            if (resp.success && resp.volume_types && resp.volume_types.length) {
              anySuccess = true;
              addVtypeRows(resp.volume_types);
            }
          }, 'json').always(function() {
            done++;
            $('#vtypes-loading-text').text('Fetching volume types from region (' + done + '/' + total + ')...');
            if (done === total) {
              btn.prop('disabled', false);
              $('#btn-save-vtypes').prop('disabled', false);
              $('#vtypes-loading').hide();
              if (!anySuccess) {
                $('#vtypes-error').text('No volume types returned from any region.').show();
              }
            }
          });
        });
      }, 'json').fail(function() {
        btn.prop('disabled', false);
        $('#btn-save-vtypes').prop('disabled', false);
        $('#vtypes-loading').hide();
        $('#vtypes-error').text('Failed to fetch regions. Check server connectivity.').show();
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
    <div class="panel panel-default" style="max-width:640px; margin-bottom:20px;">
      <div class="panel-heading"><h4 class="panel-title">Create New Group</h4></div>
      <div class="panel-body">
        <h5>Options to be created (in this order):</h5>
        <ol>
          <li><strong>Operating System</strong> — <strong><?php echo count((array)$savedImages); ?></strong> option(s)
            <?php if (empty($savedImages)): ?><span class="text-danger"> — <a href="<?php echo $moduleUrl; ?>&tab=images&server_id=<?php echo $serverId; ?>">configure images first</a></span><?php endif; ?>
          </li>
          <li><strong>Server Size</strong> (grouped) — <strong><?php echo count((array)$savedFlavors); ?></strong> option(s)
            <?php if (empty($savedFlavors)): ?><span class="text-danger"> — <a href="<?php echo $moduleUrl; ?>&tab=flavors&server_id=<?php echo $serverId; ?>">configure flavors first</a></span><?php endif; ?>
          </li>
          <li><strong>Disk Space</strong> — <strong><?php echo count((array)$savedDisks); ?></strong> option(s)
            <?php if (empty($savedDisks)): ?><span class="text-danger"> — <a href="<?php echo $moduleUrl; ?>&tab=disks&server_id=<?php echo $serverId; ?>">configure disk sizes first</a></span><?php endif; ?>
          </li>
        </ol>

        <?php if (empty($savedImages) && empty($savedFlavors) && empty($savedDisks)): ?>
        <div class="alert alert-warning">
          No resources are configured yet. Please configure images, flavors, and disk sizes before creating a group.
        </div>
        <?php else: ?>
        <hr>
        <div class="form-group">
          <label for="group-name">Group Name</label>
          <input type="text" id="group-name" class="form-control" value="CloudPe CMP Options"
                 placeholder="e.g. CloudPe VM Options">
        </div>
        <button class="btn btn-primary" id="btn-create-group">
          <i class="fa fa-plus-circle"></i> Create Group
        </button>
        <div id="create-group-msg" style="display:none; margin-top:10px;"></div>
        <?php endif; ?>
      </div>
    </div>

    <!-- Existing groups -->
    <?php if (!empty((array)$existingGroups)): ?>
    <div class="panel panel-default" style="margin-bottom:20px;">
      <div class="panel-heading">
        <h4 class="panel-title">Existing Config Groups
          <a href="configproductoptions.php" target="_blank" class="btn btn-xs btn-default pull-right">
            <i class="fa fa-external-link"></i> View all
          </a>
        </h4>
      </div>
      <table class="table table-bordered" style="margin-bottom:0;">
        <thead>
          <tr><th>#</th><th>Group Name</th><th>Actions</th></tr>
        </thead>
        <tbody>
          <?php foreach ($existingGroups as $i => $grp): ?>
          <tr>
            <td><?php echo $i + 1; ?></td>
            <td><?php echo htmlspecialchars($grp->name); ?></td>
            <td>
              <a href="configproductoptions.php?action=managegroup&id=<?php echo (int)$grp->id; ?>" target="_blank"
                 class="btn btn-xs btn-default">
                <i class="fa fa-edit"></i> Edit
              </a>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php endif; ?>
  </div>

  <script>
  (function() {
    var serverId  = <?php echo $serverId; ?>;
    var moduleUrl = '<?php echo $moduleUrl; ?>';

    $('#btn-create-group').on('click', function() {
      var groupName = $('#group-name').val().trim();
      if (!groupName) { alert('Please enter a group name.'); return; }

      $(this).prop('disabled', true).html('<i class="fa fa-spinner fa-spin"></i> Creating...');

      $.post(moduleUrl, {
        action:     'create_config_group',
        server_id:  serverId,
        group_name: groupName,
      }, function(resp) {
        $('#btn-create-group').prop('disabled', false).html('<i class="fa fa-plus-circle"></i> Create Group');
        var msg = $('#create-group-msg');
        if (resp.success) {
          msg.html(resp.message + ' <a href="configproductoptions.php" target="_blank">View groups &rarr;</a>')
             .removeClass('alert-danger').addClass('alert alert-success').show();
          // Reload page after short delay so new group appears in the existing list
          setTimeout(function() { location.reload(); }, 2500);
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

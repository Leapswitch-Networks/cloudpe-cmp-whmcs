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

define('CLOUDPE_CMP_MODULE_VERSION', '1.1.2-beta.5');
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
 * Replace Unicode/HTML-entity smart-quotes with plain ASCII quotes so JSON decoding works.
 */
function cloudpe_cmp_admin_sanitize_quotes(?string $value): ?string
{
  if ($value === null || $value === '') return $value;
  $map = [
    "\xE2\x80\x98" => "'", "\xE2\x80\x99" => "'",
    "\xE2\x80\x9C" => '"', "\xE2\x80\x9D" => '"',
    '&#8216;' => "'", '&#8217;' => "'",
    '&#8220;' => '"', '&#8221;' => '"',
    '&lsquo;' => "'", '&rsquo;' => "'",
    '&ldquo;' => '"', '&rdquo;' => '"',
    '&quot;'  => '"', '&apos;' => "'",
  ];
  return strtr($value, $map);
}

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

/**
 * Read a region-scoped slice of a region-nested setting map.
 *
 * Storage format for region-scoped keys:
 *   { "<regionId>": <value>, ... }
 *
 * @param int    $serverId
 * @param string $regionId
 * @param string $key
 * @param mixed  $default
 * @return mixed
 */
function cloudpe_cmp_admin_get_region_setting(int $serverId, string $regionId, string $key, $default = null)
{
  $map = cloudpe_cmp_admin_get_setting($serverId, $key, []);
  if (!is_array($map)) return $default;
  if (isset($map[$regionId])) return $map[$regionId];
  return $default;
}

/**
 * Write a region-scoped slice into a region-nested setting map.
 *
 * @param int    $serverId
 * @param string $regionId
 * @param string $key
 * @param mixed  $value
 */
function cloudpe_cmp_admin_save_region_setting(int $serverId, string $regionId, string $key, $value): void
{
  if ($regionId === '') return;
  $map = cloudpe_cmp_admin_get_setting($serverId, $key, []);
  if (!is_array($map)) $map = [];
  $map[$regionId] = $value;
  cloudpe_cmp_admin_save_setting($serverId, $key, $map);
}

/**
 * Detect whether an existing setting value is already region-nested.
 *
 * Heuristic: the map is already region-nested when every top-level key is
 * a non-numeric string (region IDs look like 'us-east-1', 'IN-WEST2', or
 * UUIDs — never purely numeric). A flat list [id1, id2] has numeric keys
 * and is considered legacy.
 *
 * @param mixed $value
 * @return bool
 */
function cloudpe_cmp_admin_is_region_nested($value): bool
{
  if (!is_array($value) || empty($value)) return false;
  foreach (array_keys($value) as $k) {
    if (is_int($k) || ctype_digit((string)$k)) return false;
  }
  return true;
}

/**
 * One-shot migration of legacy flat settings to the region-scoped shape.
 *
 * Uses sidecar `image_regions` / `flavor_regions` maps (id -> regionId) to
 * bucket each entry into the correct region slice. For entries with no
 * known region, parks them under an empty-string key so nothing is dropped.
 * `disk_sizes` (no sidecar) is stored under the empty-string key only.
 *
 * Runs once per server — guarded by the `migrated_region_scoped_v2` flag.
 *
 * @param int $serverId
 */
function cloudpe_cmp_admin_migrate_region_scoped(int $serverId): void
{
  if (!$serverId) return;
  $flag = cloudpe_cmp_admin_get_setting($serverId, 'migrated_region_scoped_v2', null);
  if ($flag) return;

  $imageRegions   = (array)cloudpe_cmp_admin_get_setting($serverId, 'image_regions', []);
  $flavorRegions  = (array)cloudpe_cmp_admin_get_setting($serverId, 'flavor_regions', []);
  $projectRegions = (array)cloudpe_cmp_admin_get_setting($serverId, 'project_regions', []);

  // Keys bucketed by image_regions sidecar
  $imageKeys = ['selected_images', 'image_names', 'image_prices'];
  foreach ($imageKeys as $key) {
    $val = cloudpe_cmp_admin_get_setting($serverId, $key, null);
    if (!is_array($val) || empty($val)) continue;
    if (cloudpe_cmp_admin_is_region_nested($val)) continue;

    $bucketed = [];
    if ($key === 'selected_images') {
      // Flat list of image IDs
      foreach ($val as $imgId) {
        $rId = $imageRegions[$imgId] ?? '';
        $bucketed[$rId][] = $imgId;
      }
    } else {
      // Map keyed by image ID — route each entry by its region
      foreach ($val as $imgId => $v) {
        $rId = $imageRegions[$imgId] ?? '';
        if (!isset($bucketed[$rId])) $bucketed[$rId] = [];
        $bucketed[$rId][$imgId] = $v;
      }
    }
    cloudpe_cmp_admin_save_setting($serverId, $key, $bucketed);
  }

  // Keys bucketed by flavor_regions sidecar
  $flavorKeys = ['selected_flavors', 'flavor_names', 'flavor_prices', 'flavor_api_names', 'flavor_specs', 'flavor_groups'];
  foreach ($flavorKeys as $key) {
    $val = cloudpe_cmp_admin_get_setting($serverId, $key, null);
    if (!is_array($val) || empty($val)) continue;
    if (cloudpe_cmp_admin_is_region_nested($val)) continue;

    $bucketed = [];
    if ($key === 'selected_flavors') {
      foreach ($val as $flvId) {
        $rId = $flavorRegions[$flvId] ?? '';
        $bucketed[$rId][] = $flvId;
      }
    } else {
      foreach ($val as $flvId => $v) {
        $rId = $flavorRegions[$flvId] ?? '';
        if (!isset($bucketed[$rId])) $bucketed[$rId] = [];
        $bucketed[$rId][$flvId] = $v;
      }
    }
    cloudpe_cmp_admin_save_setting($serverId, $key, $bucketed);
  }

  // Keys bucketed by project_regions sidecar
  $projectKeys = ['selected_projects', 'project_names'];
  foreach ($projectKeys as $key) {
    $val = cloudpe_cmp_admin_get_setting($serverId, $key, null);
    if (!is_array($val) || empty($val)) continue;
    if (cloudpe_cmp_admin_is_region_nested($val)) continue;

    $bucketed = [];
    if ($key === 'selected_projects') {
      foreach ($val as $projId) {
        $rId = $projectRegions[$projId] ?? '';
        $bucketed[$rId][] = $projId;
      }
    } else {
      foreach ($val as $projId => $v) {
        $rId = $projectRegions[$projId] ?? '';
        if (!isset($bucketed[$rId])) $bucketed[$rId] = [];
        $bucketed[$rId][$projId] = $v;
      }
    }
    cloudpe_cmp_admin_save_setting($serverId, $key, $bucketed);
  }

  // disk_sizes had no sidecar — migrate as empty-region slice.
  $disks = cloudpe_cmp_admin_get_setting($serverId, 'disk_sizes', null);
  if (is_array($disks) && !empty($disks) && !cloudpe_cmp_admin_is_region_nested($disks)) {
    cloudpe_cmp_admin_save_setting($serverId, 'disk_sizes', ['' => $disks]);
  }

  // Drop the sidecar maps — region is now the outer key.
  try {
    Capsule::table('mod_cloudpe_cmp_settings')
      ->where('server_id', $serverId)
      ->whereIn('setting_key', ['image_regions', 'flavor_regions', 'project_regions'])
      ->delete();
  } catch (\Exception $e) {
    // Non-fatal — flag is still set so we don't loop.
  }

  cloudpe_cmp_admin_save_setting($serverId, 'migrated_region_scoped_v2', 1);
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

    // Match cloudpe-cmp dashboard: keep only images that belong to an active group.
    $images = [];
    foreach ((array)($result['images'] ?? []) as $img) {
      if (empty($img['group_id']) || ($img['is_active'] ?? true) === false) {
        continue;
      }
      $id = $img['id'] ?? $img['slug'] ?? '';
      $display = $img['display_name'] ?? '';
      $apiName = $img['name'] ?? '';
      if ($display !== '' && $apiName !== '' && $display !== $apiName) {
        $name = $display . ' (' . $apiName . ')';
      } else {
        $name = $display ?: ($apiName ?: $id);
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

    // Match cloudpe-cmp dashboard: only flavors belonging to an active group.
    $activeGroupSlugs = null;
    $groupsResp = $api->listFlavorGroups($regionId);
    if (!empty($groupsResp['success']) && is_array($groupsResp['groups'] ?? null)) {
      $activeGroupSlugs = [];
      foreach ($groupsResp['groups'] as $g) {
        if (($g['is_active'] ?? true) !== false) {
          $slug = $g['slug'] ?? $g['id'] ?? '';
          if ($slug !== '') $activeGroupSlugs[$slug] = true;
        }
      }
    }

    $flavors = [];
    foreach ((array)($result['flavors'] ?? []) as $flv) {
      $flvGroup = $flv['flavor_group_slug'] ?? $flv['group_slug'] ?? $flv['group_id'] ?? '';
      if ($activeGroupSlugs !== null) {
        if ($flvGroup === '' || !isset($activeGroupSlugs[$flvGroup])) {
          continue;
        }
      }
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
      $display = $flv['display_name'] ?? '';
      $apiName = $flv['name'] ?? '';
      if ($display !== '' && $apiName !== '' && $display !== $apiName) {
        $label = $display . ' (' . $apiName . ')';
      } else {
        $label = $display ?: ($apiName ?: $id);
      }
      $flavors[] = [
        'id'                => $id,
        'name'              => $label,
        'vcpu'              => (int)($flv['vcpus'] ?? $flv['vcpu'] ?? $flv['cpu'] ?? 0),
        'memory_gb'         => $memoryGb,
        'price_monthly_inr' => isset($flv['price_monthly_inr']) ? (float)$flv['price_monthly_inr'] : null,
        'price_monthly_usd' => isset($flv['price_monthly_usd']) ? (float)$flv['price_monthly_usd'] : null,
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
      $id      = $r['id'] ?? $r['slug'] ?? '';
      $display = $r['display_name'] ?? '';
      $name    = $r['name'] ?? '';
      if ($display !== '' && $name !== '' && $display !== $name) {
        $label = $display . ' (' . $name . ')';
      } else {
        $label = $display ?: ($name ?: $id);
      }
      $regions[] = ['id' => $id, 'name' => $label];
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

    // CMP /volumes/types returns objects keyed on `vhi_name` + `display_name`
    // (no `id` / `name`). The value used as `volume.volume_type` in
    // /instances payloads is the `vhi_name` (or fall back to `name`/`id`).
    $types = [];
    foreach ((array)($result['volume_types'] ?? []) as $vt) {
      $id   = $vt['vhi_name'] ?? $vt['name'] ?? $vt['id'] ?? $vt['slug'] ?? '';
      $name = $vt['display_name'] ?? $vt['name'] ?? $vt['vhi_name'] ?? $id;
      if (!$id) continue;
      $types[] = ['id' => $id, 'name' => $name];
    }

    return [
      'success'      => true,
      'volume_types' => $types,
      'raw_preview'  => $result['raw_preview'] ?? '',
    ];
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
  if ($groupName === '') {
    $groupName = 'CloudPe CMP Options';
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

  // Load saved images / flavors as union of every region. Disks are flat.
  $rawImagesSel  = cloudpe_cmp_admin_get_setting($serverId, 'selected_images', []);
  $rawFlavorsSel = cloudpe_cmp_admin_get_setting($serverId, 'selected_flavors', []);
  $rawImageNames   = cloudpe_cmp_admin_get_setting($serverId, 'image_names', []);
  $rawImagePrices  = cloudpe_cmp_admin_get_setting($serverId, 'image_prices', []);
  $rawFlavorNames  = cloudpe_cmp_admin_get_setting($serverId, 'flavor_names', []);
  $rawFlavorPrices = cloudpe_cmp_admin_get_setting($serverId, 'flavor_prices', []);

  $unionList = function ($v) {
    if (!is_array($v)) return [];
    if (cloudpe_cmp_admin_is_region_nested($v)) {
      $u = [];
      foreach ($v as $slice) { if (is_array($slice)) foreach ($slice as $i) $u[] = $i; }
      return array_values(array_unique($u));
    }
    return $v;
  };
  $unionMap = function ($v) {
    if (!is_array($v)) return [];
    if (cloudpe_cmp_admin_is_region_nested($v)) {
      $m = [];
      foreach ($v as $slice) { if (is_array($slice)) foreach ($slice as $k => $val) $m[$k] = $val; }
      return $m;
    }
    return $v;
  };

  $savedImages  = $unionList($rawImagesSel);
  $savedFlavors = $unionList($rawFlavorsSel);
  $imageNames   = $unionMap($rawImageNames);
  $imagePrices  = $unionMap($rawImagePrices);
  $flavorNames  = $unionMap($rawFlavorNames);
  $flavorPrices = $unionMap($rawFlavorPrices);

  // Region-aware iteration data — used below to create one sub-option per
  // (region, image) and (region, flavor) pair so the cart cascade can
  // filter by region.
  $imagesByRegion  = cloudpe_cmp_admin_is_region_nested($rawImagesSel)  ? (array)$rawImagesSel  : [];
  $flavorsByRegion = cloudpe_cmp_admin_is_region_nested($rawFlavorsSel) ? (array)$rawFlavorsSel : [];

  // List of regions configured with at least one image or flavor; this drives
  // the cart's leading "Region" dropdown.
  $configuredRegionIds = [];
  foreach (array_merge(array_keys($imagesByRegion), array_keys($flavorsByRegion)) as $rId) {
    $rId = (string)$rId;
    if ($rId !== '' && !in_array($rId, $configuredRegionIds, true)) $configuredRegionIds[] = $rId;
  }

  // Region display names: prefer admin-saved `region_names`, else live API.
  $regionNamesMap = (array)cloudpe_cmp_admin_get_setting($serverId, 'region_names', []);
  if (empty($regionNamesMap) && !empty($configuredRegionIds)) {
    $live = cloudpe_cmp_admin_load_regions($serverId, '');
    if (!empty($live['success']) && !empty($live['regions'])) {
      foreach ($live['regions'] as $r) {
        if (!empty($r['id'])) $regionNamesMap[$r['id']] = $r['name'] ?? $r['id'];
      }
    }
  }

  // Disks are server-wide; flatten any legacy region nesting.
  $rawDisks = cloudpe_cmp_admin_get_setting($serverId, 'disk_sizes', []);
  $savedDisks = [];
  if (cloudpe_cmp_admin_is_region_nested($rawDisks)) {
      $seen = [];
      foreach ($rawDisks as $slice) { if (is_array($slice)) foreach ($slice as $d) {
          $sz = (int)($d['size_gb'] ?? 0);
          if ($sz > 0 && !isset($seen[$sz])) { $seen[$sz] = true; $savedDisks[] = $d; }
      } }
  } elseif (is_array($rawDisks)) {
      $savedDisks = $rawDisks;
  }

  if (empty($savedImages) && empty($savedFlavors) && empty($savedDisks)) {
    return ['success' => false, 'message' => 'No resources configured. Please configure images, flavors, and disk sizes first.'];
  }

  // Fallback name lookup is intentionally not run here — saved names are
  // expected to be filled in via the Images/Flavors tabs. Any saved-but-
  // unnamed entry will display its ID, which is preferable to issuing
  // per-region API calls (we don't know which region owns a given ID).

  // Customer-facing display names for flavors on the cart page.
  $flavorDisplayNames = [];
  foreach ((array)$savedFlavors as $flavorId) {
    $flavorDisplayNames[$flavorId] = $flavorNames[$flavorId] ?? $flavorId;
  }

  try {
    // Create the configurable options group
    $groupId = Capsule::table('tblproductconfiggroups')->insertGetId([
      'name'        => $groupName,
      'description' => 'Auto-generated by CloudPe CMP Manager.',
    ]);

    $sortOrder = 0;

    // Helper: insert pricing rows in every currency for a sub-option.
    $allCurrencies = Capsule::table('tblcurrencies')->get();
    $insertPricing = function (int $subId, $stored) use ($allCurrencies, $multQ, $multS, $multA, $multB, $multT) {
      foreach ($allCurrencies as $currency) {
        $price = is_array($stored)
          ? (float)($stored[$currency->id] ?? ($stored[(string)$currency->id] ?? 0))
          : (float)$stored;
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
    };

    // --- Region (cart cascade root) ---
    // Always added first when at least one configured region exists, so the
    // ClientAreaPageCart hook can filter OS/Size by selected region.
    if (!empty($configuredRegionIds)) {
      $regionOptionId = Capsule::table('tblproductconfigoptions')->insertGetId([
        'gid'        => $groupId,
        'optionname' => 'Region',
        'optiontype' => 1, // dropdown
        'qtyminimum' => 0,
        'qtymaximum' => 0,
        'order'      => $sortOrder++,
        'hidden'     => 0,
      ]);
      $regionSubOrder = 0;
      foreach ($configuredRegionIds as $rId) {
        $rLabel = $regionNamesMap[$rId] ?? $rId;
        $subId = Capsule::table('tblproductconfigoptionssub')->insertGetId([
          'configid'   => $regionOptionId,
          'optionname' => $rId . '|' . $rLabel,
          'sortorder'  => $regionSubOrder++,
          'hidden'     => 0,
        ]);
        $insertPricing($subId, 0);
      }
    }

    // --- Operating System (one sub-option per (region, image)) ---
    if ($includeOs && !empty($imagesByRegion)) {
      $osOptionId = Capsule::table('tblproductconfigoptions')->insertGetId([
        'gid'        => $groupId,
        'optionname' => 'Operating System',
        'optiontype' => 1,
        'qtyminimum' => 0,
        'qtymaximum' => 0,
        'order'      => $sortOrder++,
        'hidden'     => 0,
      ]);
      $osSubOrder = 0;
      foreach ($imagesByRegion as $rId => $imgIds) {
        if (!is_array($imgIds)) continue;
        $rLabel = $regionNamesMap[$rId] ?? $rId;
        $namesForRegion  = (array)((cloudpe_cmp_admin_is_region_nested($rawImageNames)  && isset($rawImageNames[$rId]))  ? $rawImageNames[$rId]  : $imageNames);
        $pricesForRegion = (array)((cloudpe_cmp_admin_is_region_nested($rawImagePrices) && isset($rawImagePrices[$rId])) ? $rawImagePrices[$rId] : $imagePrices);
        foreach ($imgIds as $imageId) {
          $displayName = $namesForRegion[$imageId] ?? ($imageNames[$imageId] ?? $imageId);
          $stored      = $pricesForRegion[$imageId] ?? ($imagePrices[$imageId] ?? 0);
          $label       = $displayName . ' — ' . $rLabel;
          $subId = Capsule::table('tblproductconfigoptionssub')->insertGetId([
            'configid'   => $osOptionId,
            'optionname' => $imageId . '|' . $label,
            'sortorder'  => $osSubOrder++,
            'hidden'     => 0,
          ]);
          $insertPricing($subId, $stored);
        }
      }
    }

    // --- Server Size (one sub-option per (region, flavor)) ---
    if ($includeSize && !empty($flavorsByRegion)) {
      $sizeOptionId = Capsule::table('tblproductconfigoptions')->insertGetId([
        'gid'        => $groupId,
        'optionname' => 'Server Size',
        'optiontype' => 1,
        'qtyminimum' => 0,
        'qtymaximum' => 0,
        'order'      => $sortOrder++,
        'hidden'     => 0,
      ]);
      $sizeSubOrder = 0;
      foreach ($flavorsByRegion as $rId => $flvIds) {
        if (!is_array($flvIds)) continue;
        $rLabel = $regionNamesMap[$rId] ?? $rId;
        $namesForRegion  = (array)((cloudpe_cmp_admin_is_region_nested($rawFlavorNames)  && isset($rawFlavorNames[$rId]))  ? $rawFlavorNames[$rId]  : $flavorNames);
        $pricesForRegion = (array)((cloudpe_cmp_admin_is_region_nested($rawFlavorPrices) && isset($rawFlavorPrices[$rId])) ? $rawFlavorPrices[$rId] : $flavorPrices);
        foreach ($flvIds as $flavorId) {
          $displayName = $namesForRegion[$flavorId] ?? ($flavorNames[$flavorId] ?? $flavorId);
          $stored      = $pricesForRegion[$flavorId] ?? ($flavorPrices[$flavorId] ?? 0);
          $label       = $displayName . ' — ' . $rLabel;
          $subId = Capsule::table('tblproductconfigoptionssub')->insertGetId([
            'configid'   => $sizeOptionId,
            'optionname' => $flavorId . '|' . $label,
            'sortorder'  => $sizeSubOrder++,
            'hidden'     => 0,
          ]);
          $insertPricing($subId, $stored);
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

      case 'load_regions':
        $service = trim($_POST['service'] ?? '');
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

      case 'save_projects': {
        // v1.1.2-beta.5: region-scoped. selected_projects[regionId] = [projectId].
        $regionId = trim($_POST['region_id'] ?? '');
        if ($regionId === '') { echo json_encode(['success' => false, 'error' => 'region_id required']); exit; }
        $selectedProjects = $_POST['selected_projects'] ?? [];
        cloudpe_cmp_admin_save_region_setting($serverId, $regionId, 'selected_projects', $selectedProjects);
        echo json_encode(['success' => true, 'message' => 'Project selection saved.']);
        exit;
      }

      case 'save_project_names': {
        $regionId = trim($_POST['region_id'] ?? '');
        if ($regionId === '') { echo json_encode(['success' => false, 'error' => 'region_id required']); exit; }
        $names = $_POST['names'] ?? [];
        cloudpe_cmp_admin_save_region_setting($serverId, $regionId, 'project_names', $names);
        echo json_encode(['success' => true, 'message' => 'Project display names saved.']);
        exit;
      }

      case 'save_selected_regions':
        $selRegions = $_POST['selected_regions'] ?? [];
        $regNames   = $_POST['region_names'] ?? [];
        cloudpe_cmp_admin_save_setting($serverId, 'selected_regions', $selRegions);
        cloudpe_cmp_admin_save_setting($serverId, 'region_names', $regNames);
        echo json_encode(['success' => true]);
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

      case 'save_disks':
        // Disks are server-wide (not region-scoped) per v1.1.2-beta.5.
        $disks = $_POST['disks'] ?? [];
        cloudpe_cmp_admin_save_setting($serverId, 'disk_sizes', $disks);
        echo json_encode(['success' => true, 'message' => 'Disk sizes saved.']);
        exit;

      case 'save_images':
      case 'save_flavors':
      case 'save_flavor_specs':
      case 'save_image_names':
      case 'save_flavor_names':
      case 'save_flavor_api_names':
      case 'save_image_prices':
      case 'save_flavor_prices':
      case 'save_flavor_groups': {
        $regionId = trim($_POST['region_id'] ?? '');
        if ($regionId === '') {
          echo json_encode(['success' => false, 'error' => 'region_id required']);
          exit;
        }
        // Map action -> [post key, setting key, response message]
        $map = [
          'save_images'           => ['selected_images',  'selected_images',  'Image selection saved.'],
          'save_flavors'          => ['selected_flavors', 'selected_flavors', 'Flavor selection saved.'],
          'save_flavor_specs'     => ['specs',            'flavor_specs',     'Flavor specs saved.'],
          'save_image_names'      => ['names',            'image_names',      'Image display names saved.'],
          'save_flavor_names'     => ['names',            'flavor_names',     'Flavor display names saved.'],
          'save_flavor_api_names' => ['api_names',        'flavor_api_names', 'Flavor API names saved.'],
          'save_image_prices'     => ['prices',           'image_prices',     'Image prices saved.'],
          'save_flavor_prices'    => ['prices',           'flavor_prices',    'Flavor prices saved.'],
          'save_flavor_groups'    => ['flavor_groups',    'flavor_groups',    'Flavor groups saved.'],
        ];
        [$postKey, $settingKey, $okMsg] = $map[$action];
        $value = $_POST[$postKey] ?? [];
        cloudpe_cmp_admin_save_region_setting($serverId, $regionId, $settingKey, $value);
        echo json_encode(['success' => true, 'message' => $okMsg]);
        exit;
      }

      case 'save_volume_types': {
        $regionId = trim($_POST['region_id'] ?? '');
        if ($regionId === '') { echo json_encode(['success' => false, 'error' => 'region_id required']); exit; }
        $selectedTypes = $_POST['selected_volume_types'] ?? [];
        cloudpe_cmp_admin_save_region_setting($serverId, $regionId, 'selected_volume_types', $selectedTypes);
        echo json_encode(['success' => true, 'message' => 'Volume type selection saved.']);
        exit;
      }

      case 'save_volume_type_names': {
        $regionId = trim($_POST['region_id'] ?? '');
        if ($regionId === '') { echo json_encode(['success' => false, 'error' => 'region_id required']); exit; }
        $names = $_POST['names'] ?? [];
        cloudpe_cmp_admin_save_region_setting($serverId, $regionId, 'volume_type_names', $names);
        echo json_encode(['success' => true, 'message' => 'Volume type names saved.']);
        exit;
      }

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

  // One-shot migration to region-scoped storage shape.
  if ($serverId) {
    cloudpe_cmp_admin_migrate_region_scoped($serverId);
  }

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

    <!-- Server selector (Region selector removed v1.1.2-beta.5 — tabs render
         all regions; loaders iterate regions internally) -->
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
    // Global region map (id -> display name). Populated once per page so
    // every tab can render region labels without re-querying the API.
    window.cmpServerId  = <?php echo $serverId; ?>;
    window.cmpModuleUrl = '<?php echo $moduleUrl; ?>';
    window.cmpRegions   = {};   // id -> name
    window.cmpRegionIds = [];   // ordered list of region IDs

    $.post(window.cmpModuleUrl, { action: 'load_regions', server_id: window.cmpServerId }, function(resp) {
      if (resp.success && resp.regions && resp.regions.length) {
        resp.regions.forEach(function(r) {
          window.cmpRegions[r.id] = r.name || r.id;
          window.cmpRegionIds.push(r.id);
        });
      }
      $(document).trigger('cmp:regions-loaded');
    }, 'json').fail(function() {
      $(document).trigger('cmp:regions-loaded');
    });
    </script>

    <!-- Tab navigation -->
    <ul class="nav nav-tabs" role="tablist">
      <?php
      $tabs = [
        'dashboard'       => 'Dashboard',
        'images'          => 'Images',
        'flavors'         => 'Flavors',
        'projects'        => 'Projects',
        'volume_types'    => 'Volume Types',
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
        case 'projects':
          cloudpe_cmp_admin_render_projects($serverId, $moduleUrl);
          break;
        case 'volume_types':
          cloudpe_cmp_admin_render_volume_types($serverId, $moduleUrl);
          break;
        case 'disks':
          cloudpe_cmp_admin_render_disks($serverId, $moduleUrl);
          break;
        case 'create_group':
          cloudpe_cmp_admin_render_create_group($serverId, $moduleUrl, '');
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
function cloudpe_cmp_admin_render_images(int $serverId, string $moduleUrl, string $regionId = ''): void
{
  // Region dropdown removed in v1.1.2-beta.5: render the union of all
  // regions. Each row carries data-region so save can group by region.
  $rawSelected = cloudpe_cmp_admin_get_setting($serverId, 'selected_images', []);
  $rawNames    = cloudpe_cmp_admin_get_setting($serverId, 'image_names', []);
  $rawPrices   = cloudpe_cmp_admin_get_setting($serverId, 'image_prices', []);

  // Normalize: legacy flat shape { 'id1': true } → { '': ['id1'] } so the
  // renderer can handle both shapes uniformly.
  $regionScopedSelected = cloudpe_cmp_admin_is_region_nested($rawSelected) ? (array)$rawSelected : ['' => (array)$rawSelected];
  $regionScopedNames    = cloudpe_cmp_admin_is_region_nested($rawNames)    ? (array)$rawNames    : ['' => (array)$rawNames];
  $regionScopedPrices   = cloudpe_cmp_admin_is_region_nested($rawPrices)   ? (array)$rawPrices   : ['' => (array)$rawPrices];

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
          <th>Region</th>
          <th>Image ID</th>
          <th>Display Name</th>
          <?php foreach ($currencies as $c): ?>
          <th><?php echo htmlspecialchars($c->code); ?> /mo</th>
          <?php endforeach; ?>
        </tr>
      </thead>
      <tbody>
        <?php
        $rowIdx = 0;
        foreach ($regionScopedSelected as $rId => $imgIds):
          if (!is_array($imgIds)) continue;
          $namesForRegion  = (array)($regionScopedNames[$rId]  ?? []);
          $pricesForRegion = (array)($regionScopedPrices[$rId] ?? []);
          foreach ($imgIds as $imgId):
            $savedName   = $namesForRegion[$imgId] ?? '';
            $displayName = ($savedName !== '' && $savedName !== $imgId) ? $savedName : '';
            $priceEntry  = $pricesForRegion[$imgId] ?? null;
            $rowIdx++;
        ?>
        <tr data-id="<?php echo htmlspecialchars($imgId); ?>" data-region="<?php echo htmlspecialchars((string)$rId); ?>" data-saved="1">
          <td class="row-num"><?php echo $rowIdx; ?></td>
          <td><input type="checkbox" class="img-check" checked></td>
          <td class="img-api-name"><?php echo htmlspecialchars($displayName); ?></td>
          <td class="img-region text-muted" style="white-space:nowrap;"><?php echo htmlspecialchars($rId !== '' ? $rId : '—'); ?></td>
          <td><small class="text-muted"><?php echo htmlspecialchars($imgId); ?></small></td>
          <td><input type="text" class="form-control input-sm img-name"
               value="<?php echo htmlspecialchars($displayName); ?>"></td>
          <?php foreach ($currencies as $c):
            if (is_array($priceEntry)) {
              $cellVal = $priceEntry[$c->id] ?? ($priceEntry[(string)$c->id] ?? '0');
            } else {
              $cellVal = ((int)$c->id === $firstCurrId) ? ($priceEntry ?? '0') : '0';
            }
          ?>
          <td><input type="number" step="0.01" min="0" class="form-control input-sm img-price"
               data-currency="<?php echo (int)$c->id; ?>"
               value="<?php echo htmlspecialchars((string)$cellVal); ?>" style="width:90px;"></td>
          <?php endforeach; ?>
        </tr>
        <?php endforeach; endforeach; ?>
      </tbody>
    </table>
    </div>

    <?php if ($rowIdx === 0): ?>
    <p class="text-muted">No images configured yet. Click <strong>Load from API</strong> to fetch available images from every region.</p>
    <?php endif; ?>
  </div>

  <script>
  (function() {
    var serverId  = <?php echo $serverId; ?>;
    var moduleUrl = '<?php echo $moduleUrl; ?>';

    // (id|region) -> true for rows already saved at page load. Used by
    // Load-from-API to decide which checkboxes start checked.
    var savedKey = function(id, region) { return String(id) + '|' + String(region || ''); };
    var savedKeys = {};
    <?php foreach ($regionScopedSelected as $rId => $imgIds):
      if (!is_array($imgIds)) continue;
      foreach ($imgIds as $imgId): ?>
    savedKeys[savedKey(<?php echo json_encode((string)$imgId); ?>, <?php echo json_encode((string)$rId); ?>)] = 1;
    <?php endforeach; endforeach; ?>

    var savedNames  = <?php echo json_encode((object)$regionScopedNames  ?: new stdClass()); ?>;
    var savedPrices = <?php echo json_encode((object)$regionScopedPrices ?: new stdClass()); ?>;
    var currencies  = <?php echo json_encode($currencies->toArray()); ?>;
    var firstCurrId = <?php echo (int)$firstCurrId; ?>;

    // Resolve region IDs to friendly names once the region list loads.
    function resolveImgRegionNames() {
      $('#images-table tbody tr').each(function() {
        var rId = $(this).data('region') || '';
        if (rId && window.cmpRegions[rId]) {
          $(this).find('.img-region').text(window.cmpRegions[rId]);
        }
      });
    }
    $(document).on('cmp:regions-loaded', resolveImgRegionNames);
    resolveImgRegionNames();

    function reNumber() {
      var n = 0;
      $('#images-table tbody tr').each(function() {
        $(this).find('.row-num').text($(this).is(':visible') ? ++n : '');
      });
    }

    function addRows(items, regionId, regionLabel) {
      // Skip duplicates *per region* — same image ID can legitimately
      // exist in multiple regions and each is a distinct row.
      var existing = {};
      $('#images-table tbody tr').each(function() {
        existing[String($(this).data('id')) + '|' + String($(this).data('region') || '')] = 1;
      });

      $.each(items, function(i, img) {
        var key = String(img.id) + '|' + String(regionId || '');
        if (existing[key]) return;
        var isSaved = !!savedKeys[key];
        var row = $('<tr>').attr('data-id', img.id).attr('data-region', regionId).attr('data-saved', '0');
        var savedForRegion = (savedPrices[regionId] && typeof savedPrices[regionId] === 'object') ? savedPrices[regionId] : {};
        var saved = savedForRegion[img.id];
        var savedIsMap = saved && typeof saved === 'object';
        var priceCells = '';
        currencies.forEach(function(c) {
          var v;
          if (savedIsMap) {
            v = saved[c.id] !== undefined ? saved[c.id] : (saved[String(c.id)] !== undefined ? saved[String(c.id)] : '0');
          } else if (saved !== undefined && saved !== null && saved !== '') {
            v = (parseInt(c.id) === firstCurrId) ? saved : '0';
          } else {
            v = '0';
          }
          priceCells += '<td><input type="number" step="0.01" min="0" class="form-control input-sm img-price" data-currency="' +
            parseInt(c.id) + '" style="width:90px;" value="' + $('<span>').text(v).html() + '"></td>';
        });
        var namesForRegion = (savedNames[regionId] && typeof savedNames[regionId] === 'object') ? savedNames[regionId] : {};
        var dispName = namesForRegion[img.id] || img.name;
        row.html(
          '<td class="row-num"></td>' +
          '<td><input type="checkbox" class="img-check"' + (isSaved ? ' checked' : '') + '></td>' +
          '<td class="img-api-name">' + $('<span>').text(img.name).html() + '</td>' +
          '<td class="img-region text-muted" style="white-space:nowrap;">' + $('<span>').text(regionLabel || regionId || '-').html() + '</td>' +
          '<td><small class="text-muted">' + $('<span>').text(img.id).html() + '</small></td>' +
          '<td><input type="text" class="form-control input-sm img-name" value="' +
            $('<span>').text(dispName).html() + '"></td>' +
          priceCells
        );
        $('#images-table tbody').append(row);
      });
      reNumber();
    }

    // Search filter
    $('#images-search').on('input', function() {
      var q = $(this).val().toLowerCase().trim();
      $('#images-table tbody tr').each(function() {
        var row = $(this);
        if (!q) { row.show(); return; }
        if (row.find('.img-check').is(':checked')) { row.show(); return; }
        row.toggle(row.text().toLowerCase().indexOf(q) !== -1);
      });
    });

    // Load from API: fetch list of regions, then iterate each region and
    // append its images to the table. Region-aware so each row's data-region
    // is correct on save.
    function runLoad() {
      $('#images-table tbody tr[data-saved="0"]').remove();
      $('#btn-load-images, #btn-save-images').prop('disabled', true);
      $('#images-loading').show();
      $('#images-error').hide();

      var regions = (window.cmpRegionIds || []).slice();
      if (!regions.length) {
        $('#images-error').text('No regions available — load_regions returned empty.').show();
        $('#btn-load-images, #btn-save-images').prop('disabled', false);
        $('#images-loading').hide();
        return;
      }

      var done = 0, total = regions.length;
      function next() {
        if (done >= total) {
          $('#btn-load-images, #btn-save-images').prop('disabled', false);
          $('#images-loading').hide();
          return;
        }
        var rId    = regions[done++];
        var rLabel = window.cmpRegions[rId] || rId;
        $('#images-loading-text').text('Loading images (' + done + '/' + total + ': ' + rLabel + ')...');
        $.post(moduleUrl, { action: 'load_images', server_id: serverId, region_id: rId }, function(resp) {
          if (resp && resp.success && resp.images) addRows(resp.images, rId, rLabel);
        }, 'json').always(next);
      }
      next();
    }

    $('#btn-load-images').on('click', function() {
      if (window.cmpRegionIds && window.cmpRegionIds.length) {
        runLoad();
      } else {
        // regions not yet loaded — wait for the event then run
        $(document).one('cmp:regions-loaded', runLoad);
      }
    });

    // Uncheck row: confirm
    $('#images-table').on('change', '.img-check', function() {
      var cb  = $(this);
      if (!cb.is(':checked')) {
        var name = cb.closest('tr').find('.img-api-name').text() || cb.closest('tr').data('id');
        if (!confirm('Deselect "' + name + '" from the image selection?')) {
          cb.prop('checked', true);
        }
      }
    });

    $('#check-all-images').on('change', function() {
      var checked = $(this).is(':checked');
      $('#images-table tbody tr:visible').each(function() {
        $(this).find('.img-check').prop('checked', checked);
      });
    });

    // Save: group rows by data-region and dispatch one save call per region.
    $('#btn-save-images').on('click', function() {
      var saveBtn = $(this).prop('disabled', true);
      $('#btn-load-images').prop('disabled', true);
      $('#images-saving').show();

      var byRegion = {}; // rId -> { ids:[], names:{}, prices:{} }
      $('#images-table tbody tr').each(function() {
        var row = $(this);
        if (!row.find('.img-check').is(':checked')) return;
        var rId = String(row.data('region') || '');
        if (!byRegion[rId]) byRegion[rId] = { ids: [], names: {}, prices: {} };
        var id = row.data('id');
        byRegion[rId].ids.push(id);
        byRegion[rId].names[id] = row.find('.img-name').val();
        var perCurr = {};
        row.find('.img-price').each(function() {
          perCurr[$(this).data('currency')] = $(this).val();
        });
        byRegion[rId].prices[id] = perCurr;
      });

      // Always include every region we know about so deselecting all rows
      // in a region clears that region's slice (instead of leaving stale data).
      (window.cmpRegionIds || []).forEach(function(rId) {
        if (!byRegion[rId]) byRegion[rId] = { ids: [], names: {}, prices: {} };
      });

      var calls = [];
      Object.keys(byRegion).forEach(function(rId) {
        if (!rId) return; // skip unassigned bucket — saves require a region
        var b = byRegion[rId];
        calls.push($.post(moduleUrl, { action: 'save_images',       server_id: serverId, region_id: rId, selected_images: b.ids   }, null, 'json'));
        calls.push($.post(moduleUrl, { action: 'save_image_names',  server_id: serverId, region_id: rId, names:           b.names }, null, 'json'));
        calls.push($.post(moduleUrl, { action: 'save_image_prices', server_id: serverId, region_id: rId, prices:          b.prices}, null, 'json'));
      });

      $.when.apply($, calls).always(function() {
        // Refresh savedKeys so the user can re-check just-cleared rows
        // and have them count as saved on next save click.
        savedKeys = {};
        $('#images-table tbody tr').each(function() {
          if ($(this).find('.img-check').is(':checked')) {
            savedKeys[savedKey($(this).data('id'), $(this).data('region'))] = 1;
          }
        });
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
function cloudpe_cmp_admin_render_flavors(int $serverId, string $moduleUrl, string $regionId = ''): void
{
  // Region dropdown removed in v1.1.2-beta.5. Render union of all regions.
  $rawSelected = cloudpe_cmp_admin_get_setting($serverId, 'selected_flavors', []);
  $rawNames    = cloudpe_cmp_admin_get_setting($serverId, 'flavor_names', []);
  $rawPrices   = cloudpe_cmp_admin_get_setting($serverId, 'flavor_prices', []);
  $rawApiNames = cloudpe_cmp_admin_get_setting($serverId, 'flavor_api_names', []);
  $rawSpecs    = cloudpe_cmp_admin_get_setting($serverId, 'flavor_specs', []);

  $regSelected = cloudpe_cmp_admin_is_region_nested($rawSelected) ? (array)$rawSelected : ['' => (array)$rawSelected];
  $regNames    = cloudpe_cmp_admin_is_region_nested($rawNames)    ? (array)$rawNames    : ['' => (array)$rawNames];
  $regPrices   = cloudpe_cmp_admin_is_region_nested($rawPrices)   ? (array)$rawPrices   : ['' => (array)$rawPrices];
  $regApiNames = cloudpe_cmp_admin_is_region_nested($rawApiNames) ? (array)$rawApiNames : ['' => (array)$rawApiNames];
  $regSpecs    = cloudpe_cmp_admin_is_region_nested($rawSpecs)    ? (array)$rawSpecs    : ['' => (array)$rawSpecs];
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
      <span id="flavors-loading" style="display:none;"><i class="fa fa-spinner fa-spin"></i> <span id="flavors-loading-text">Fetching regions...</span></span>
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
          <th>Region</th>
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
        $rowIdx = 0;
        foreach ($regSelected as $rId => $flvIds):
          if (!is_array($flvIds)) continue;
          $namesForRegion    = (array)($regNames[$rId]    ?? []);
          $pricesForRegion   = (array)($regPrices[$rId]   ?? []);
          $apiNamesForRegion = (array)($regApiNames[$rId] ?? []);
          $specsForRegion    = (array)($regSpecs[$rId]    ?? []);
          foreach ($flvIds as $flvId):
            $savedName   = $namesForRegion[$flvId] ?? '';
            $displayName = ($savedName !== '' && $savedName !== $flvId) ? $savedName : '';
            $priceEntry  = $pricesForRegion[$flvId] ?? null;
            $spec        = (array)($specsForRegion[$flvId] ?? []);
            $specVcpu    = isset($spec['vcpu']) ? (int)$spec['vcpu'] : null;
            $specRam     = isset($spec['memory_gb']) ? (float)$spec['memory_gb'] : null;
            $rowIdx++;
        ?>
        <tr data-id="<?php echo htmlspecialchars($flvId); ?>" data-region="<?php echo htmlspecialchars((string)$rId); ?>" data-saved="1">
          <td class="row-num"><?php echo $rowIdx; ?></td>
          <td><input type="checkbox" class="flv-check" checked></td>
          <td class="flv-api-name"><?php echo htmlspecialchars($apiNamesForRegion[$flvId] ?? $flvId); ?></td>
          <td class="flv-region text-muted" style="white-space:nowrap;"><?php echo htmlspecialchars($rId !== '' ? $rId : '—'); ?></td>
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
        <?php endforeach; endforeach; ?>
      </tbody>
    </table>
    </div>

    <?php if ($rowIdx === 0): ?>
    <p class="text-muted">No flavors configured yet. Click <strong>Load from API</strong> to fetch available flavors from every region.</p>
    <?php endif; ?>
  </div>

  <script>
  (function() {
    var serverId  = <?php echo $serverId; ?>;
    var moduleUrl = '<?php echo $moduleUrl; ?>';
    var currencies  = <?php echo json_encode($currencies->toArray()); ?>;
    var firstCurrId = <?php echo (int)$firstCurrId; ?>;
    var savedNames  = <?php echo json_encode((object)$regNames  ?: new stdClass()); ?>;
    var savedPrices = <?php echo json_encode((object)$regPrices ?: new stdClass()); ?>;

    var savedKey = function(id, region) { return String(id) + '|' + String(region || ''); };
    var savedKeys = {};
    <?php foreach ($regSelected as $rId => $flvIds):
      if (!is_array($flvIds)) continue;
      foreach ($flvIds as $flvId): ?>
    savedKeys[savedKey(<?php echo json_encode((string)$flvId); ?>, <?php echo json_encode((string)$rId); ?>)] = 1;
    <?php endforeach; endforeach; ?>

    function autoDisplayName(flv) {
      return (parseInt(flv.vcpu) || 0) + ' vCPU, ' + (parseFloat(flv.memory_gb) || 0) + ' GB RAM';
    }

    function resolveFlvRegionNames() {
      $('#flavors-table tbody tr').each(function() {
        var rId = $(this).data('region') || '';
        if (rId && window.cmpRegions[rId]) $(this).find('.flv-region').text(window.cmpRegions[rId]);
      });
    }
    $(document).on('cmp:regions-loaded', resolveFlvRegionNames);
    resolveFlvRegionNames();

    function reNumber() {
      var n = 0;
      $('#flavors-table tbody tr').each(function() {
        $(this).find('.row-num').text($(this).is(':visible') ? ++n : '');
      });
    }

    var priceChangeNotes = [];
    function codeForCurrencyId(id) {
      var m = currencies.filter(function(c) { return parseInt(c.id) === parseInt(id); });
      return m.length ? m[0].code : String(id);
    }
    function markPriceDiff($input, newValue, flvLabel) {
      if (newValue === undefined || newValue === null || newValue === '') return;
      var current = parseFloat($input.val()), latest = parseFloat(newValue);
      if (isNaN(latest)) return;
      if (!isNaN(current) && Math.abs(current - latest) < 0.001) return;
      var code = codeForCurrencyId($input.data('currency'));
      $input.siblings('.flv-price-apply').remove();
      var $icon = $('<i class="fa fa-exclamation-circle flv-price-apply" role="button" tabindex="0" title="Apply latest price: ' + latest + '"></i>');
      $icon.on('click', function() { $input.val(latest); $icon.remove(); });
      $input.after($icon);
      priceChangeNotes.push(flvLabel + ' — ' + code + ': ' + (isNaN(current) ? '—' : current) + ' → ' + latest);
    }
    function renderPriceChangeBanner() {
      var $b = $('#flavors-price-changes');
      if (!priceChangeNotes.length) { $b.hide(); return; }
      var html = '<strong>' + priceChangeNotes.length + ' price change' + (priceChangeNotes.length === 1 ? '' : 's') + ' detected from API.</strong> Click the orange <code>!</code> to apply.';
      html += '<ul style="margin:6px 0 0 16px;">';
      priceChangeNotes.slice(0, 20).forEach(function(n) { html += '<li>' + $('<span>').text(n).html() + '</li>'; });
      if (priceChangeNotes.length > 20) html += '<li>…and ' + (priceChangeNotes.length - 20) + ' more</li>';
      html += '</ul>';
      $b.html(html).show();
    }

    function addRows(items, regionId, regionLabel) {
      var existing = {};
      $('#flavors-table tbody tr').each(function() {
        existing[String($(this).data('id')) + '|' + String($(this).data('region') || '')] = $(this);
      });

      $.each(items, function(i, flv) {
        var key = String(flv.id) + '|' + String(regionId || '');
        var flvLabel = (flv.name || flv.id) + ' [' + (regionLabel || regionId) + ']';
        var apiByCode = { 'INR': flv.price_monthly_inr, 'USD': flv.price_monthly_usd };

        if (existing[key]) {
          var er = existing[key];
          if (flv.vcpu      !== undefined) er.find('.flv-vcpu').text(parseInt(flv.vcpu) || 0);
          if (flv.memory_gb !== undefined) er.find('.flv-ram').text(parseFloat(flv.memory_gb) || 0);
          if (regionLabel) er.find('.flv-region').text(regionLabel);
          var nameInput = er.find('.flv-name');
          if (!(nameInput.val() || '').trim()) nameInput.val(autoDisplayName(flv));
          er.find('.flv-price').each(function() {
            markPriceDiff($(this), apiByCode[codeForCurrencyId($(this).data('currency'))], flvLabel);
          });
          return;
        }
        var isSaved = !!savedKeys[key];
        var row = $('<tr>').attr('data-id', flv.id).attr('data-region', regionId).attr('data-saved', '0');
        var namesForRegion = (savedNames[regionId]  && typeof savedNames[regionId]  === 'object') ? savedNames[regionId]  : {};
        var pricesForReg   = (savedPrices[regionId] && typeof savedPrices[regionId] === 'object') ? savedPrices[regionId] : {};
        var defaultName = namesForRegion[flv.id] || autoDisplayName(flv);
        var saved = pricesForReg[flv.id];
        var savedIsMap = saved && typeof saved === 'object';
        var priceCells = '';
        currencies.forEach(function(c) {
          var v;
          if (savedIsMap && (saved[c.id] !== undefined || saved[String(c.id)] !== undefined)) {
            v = saved[c.id] !== undefined ? saved[c.id] : saved[String(c.id)];
          } else if (!savedIsMap && saved !== undefined && saved !== null && saved !== '' && parseInt(c.id) === firstCurrId) {
            v = saved;
          } else if (apiByCode[c.code] !== undefined && apiByCode[c.code] !== null) {
            v = apiByCode[c.code];
          } else { v = '0'; }
          priceCells += '<td><input type="number" step="0.01" min="0" class="form-control input-sm flv-price" data-currency="' +
            parseInt(c.id) + '" style="width:90px;" value="' + $('<span>').text(v).html() + '"></td>';
        });
        row.html(
          '<td class="row-num"></td>' +
          '<td><input type="checkbox" class="flv-check"' + (isSaved ? ' checked' : '') + '></td>' +
          '<td class="flv-api-name">' + $('<span>').text(flv.name).html() + '</td>' +
          '<td class="flv-region text-muted" style="white-space:nowrap;">' + $('<span>').text(regionLabel || regionId || '-').html() + '</td>' +
          '<td class="flv-vcpu">' + (parseInt(flv.vcpu) || 0) + '</td>' +
          '<td class="flv-ram">' + (parseFloat(flv.memory_gb) || 0) + '</td>' +
          '<td><small class="text-muted">' + $('<span>').text(flv.id).html() + '</small></td>' +
          '<td><input type="text" class="form-control input-sm flv-name" value="' + $('<span>').text(defaultName).html() + '"></td>' +
          priceCells
        );
        $('#flavors-table tbody').append(row);
      });
      reNumber();
    }

    $('#flavors-search').on('input', function() {
      var q = $(this).val().toLowerCase().trim();
      $('#flavors-table tbody tr').each(function() {
        var row = $(this);
        if (!q) { row.show(); return; }
        if (row.find('.flv-check').is(':checked')) { row.show(); return; }
        row.toggle(row.text().toLowerCase().indexOf(q) !== -1);
      });
    });

    function runLoad() {
      $('#flavors-table tbody tr[data-saved="0"]').remove();
      $('#flavors-table .flv-price-apply').remove();
      priceChangeNotes = [];
      $('#flavors-price-changes').hide();
      $('#btn-load-flavors, #btn-save-flavors').prop('disabled', true);
      $('#flavors-loading').show();
      $('#flavors-error').hide();

      var regions = (window.cmpRegionIds || []).slice();
      if (!regions.length) {
        $('#flavors-error').text('No regions available.').show();
        $('#btn-load-flavors, #btn-save-flavors').prop('disabled', false);
        $('#flavors-loading').hide();
        return;
      }
      var done = 0, total = regions.length;
      function next() {
        if (done >= total) {
          renderPriceChangeBanner();
          $('#btn-load-flavors, #btn-save-flavors').prop('disabled', false);
          $('#flavors-loading').hide();
          return;
        }
        var rId = regions[done++], rLabel = window.cmpRegions[rId] || rId;
        $('#flavors-loading-text').text('Loading flavors (' + done + '/' + total + ': ' + rLabel + ')...');
        $.post(moduleUrl, { action: 'load_flavors', server_id: serverId, region_id: rId }, function(fr) {
          if (fr && fr.success && fr.flavors) addRows(fr.flavors, rId, rLabel);
        }, 'json').always(next);
      }
      next();
    }

    $('#btn-load-flavors').on('click', function() {
      if (window.cmpRegionIds && window.cmpRegionIds.length) runLoad();
      else $(document).one('cmp:regions-loaded', runLoad);
    });

    $('#flavors-table').on('change', '.flv-check', function() {
      var cb = $(this);
      if (!cb.is(':checked')) {
        var name = cb.closest('tr').find('.flv-api-name').text() || cb.closest('tr').data('id');
        if (!confirm('Deselect "' + name + '" from the flavor selection?')) cb.prop('checked', true);
      }
    });

    $('#check-all-flavors').on('change', function() {
      var checked = $(this).is(':checked');
      $('#flavors-table tbody tr:visible').each(function() { $(this).find('.flv-check').prop('checked', checked); });
    });

    $('#btn-save-flavors').on('click', function() {
      var saveBtn = $(this).prop('disabled', true);
      $('#btn-load-flavors').prop('disabled', true);
      $('#flavors-saving').show();

      var byRegion = {}; // rId -> { ids, names, prices, specs, apiNames }
      $('#flavors-table tbody tr').each(function() {
        var row = $(this);
        if (!row.find('.flv-check').is(':checked')) return;
        var rId = String(row.data('region') || '');
        if (!byRegion[rId]) byRegion[rId] = { ids: [], names: {}, prices: {}, specs: {}, apiNames: {} };
        var id = row.data('id');
        byRegion[rId].ids.push(id);
        byRegion[rId].names[id]    = row.find('.flv-name').val();
        byRegion[rId].apiNames[id] = $.trim(row.find('.flv-api-name').text());
        var perCurr = {};
        row.find('.flv-price').each(function() { perCurr[$(this).data('currency')] = $(this).val(); });
        byRegion[rId].prices[id] = perCurr;
        var vcpuText = $.trim(row.find('.flv-vcpu').text()), ramText = $.trim(row.find('.flv-ram').text());
        if (vcpuText && vcpuText !== '—' && vcpuText !== '-') {
          byRegion[rId].specs[id] = { vcpu: parseInt(vcpuText) || 0, memory_gb: parseFloat(ramText) || 0 };
        }
      });

      (window.cmpRegionIds || []).forEach(function(rId) {
        if (!byRegion[rId]) byRegion[rId] = { ids: [], names: {}, prices: {}, specs: {}, apiNames: {} };
      });

      var calls = [];
      Object.keys(byRegion).forEach(function(rId) {
        if (!rId) return;
        var b = byRegion[rId];
        calls.push($.post(moduleUrl, { action: 'save_flavors',          server_id: serverId, region_id: rId, selected_flavors: b.ids   }, null, 'json'));
        calls.push($.post(moduleUrl, { action: 'save_flavor_names',     server_id: serverId, region_id: rId, names:            b.names }, null, 'json'));
        calls.push($.post(moduleUrl, { action: 'save_flavor_prices',    server_id: serverId, region_id: rId, prices:           b.prices}, null, 'json'));
        calls.push($.post(moduleUrl, { action: 'save_flavor_specs',     server_id: serverId, region_id: rId, specs:            b.specs }, null, 'json'));
        calls.push($.post(moduleUrl, { action: 'save_flavor_api_names', server_id: serverId, region_id: rId, api_names:        b.apiNames }, null, 'json'));
      });

      $.when.apply($, calls).always(function() {
        savedKeys = {};
        $('#flavors-table tbody tr').each(function() {
          if ($(this).find('.flv-check').is(':checked')) {
            savedKeys[savedKey($(this).data('id'), $(this).data('region'))] = 1;
          }
        });
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
function cloudpe_cmp_admin_render_disks(int $serverId, string $moduleUrl, string $regionId = ''): void
{
  // Disks are server-wide (not region-scoped). If legacy region-nested data
  // is found, flatten by taking the union of all sizes (dedup by size_gb).
  $raw = cloudpe_cmp_admin_get_setting($serverId, 'disk_sizes', []);
  $savedDisks = [];
  if (cloudpe_cmp_admin_is_region_nested($raw)) {
    $seen = [];
    foreach ($raw as $disks) {
      if (!is_array($disks)) continue;
      foreach ($disks as $d) {
        $sz = (int)($d['size_gb'] ?? 0);
        if ($sz <= 0 || isset($seen[$sz])) continue;
        $seen[$sz] = true;
        $savedDisks[] = $d;
      }
    }
  } elseif (is_array($raw)) {
    $savedDisks = $raw;
  }
  ?>
  <div class="cmp-section">
    <h4>Disk Size Options</h4>
    <p class="text-muted">Define the disk size options customers can choose from when ordering.</p>

    <table class="table table-bordered cmp-resource-table" id="disks-table">
      <thead>
        <tr>
          <th>Size (GB)</th>
          <th>Display Label</th>
          <th>USD /mo</th>
          <th>INR /mo</th>
          <th>Action</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($savedDisks as $disk):
          $priceUsd = $disk['price_usd'] ?? '';
          $priceInr = $disk['price_inr'] ?? '';
          if ($priceUsd === '' && $priceInr === '' && isset($disk['price'])) {
              $priceInr = $disk['price']; // legacy single-currency -> INR
          }
        ?>
        <tr>
          <td><input type="number" min="1" class="form-control input-sm disk-size"
               value="<?php echo (int)($disk['size_gb'] ?? 0); ?>"></td>
          <td><input type="text" class="form-control input-sm disk-label"
               value="<?php echo htmlspecialchars($disk['label'] ?? ''); ?>"></td>
          <td><input type="number" step="0.01" min="0" class="form-control input-sm disk-price-usd"
               value="<?php echo htmlspecialchars((string)$priceUsd); ?>"></td>
          <td><input type="number" step="0.01" min="0" class="form-control input-sm disk-price-inr"
               value="<?php echo htmlspecialchars((string)$priceInr); ?>"></td>
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
        '<td><input type="number" step="0.01" min="0" class="form-control input-sm disk-price-usd" value="0"></td>' +
        '<td><input type="number" step="0.01" min="0" class="form-control input-sm disk-price-inr" value="0"></td>' +
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

      var disks = [];
      $('#disks-table tbody tr').each(function() {
        var size     = parseInt($(this).find('.disk-size').val());
        var label    = $(this).find('.disk-label').val().trim();
        var priceUsd = parseFloat($(this).find('.disk-price-usd').val()) || 0;
        var priceInr = parseFloat($(this).find('.disk-price-inr').val()) || 0;
        if (size > 0) {
          disks.push({ size_gb: size, label: label || (size + ' GB'), price_usd: priceUsd, price_inr: priceInr });
        }
      });

      $.post(moduleUrl, { action: 'save_disks', server_id: serverId, disks: disks }, function(resp) {
        var msg = $('#disks-save-msg');
        if (resp.success) {
          msg.text('Disk sizes saved successfully.').removeClass('alert-danger').addClass('alert alert-success').show();
        } else {
          msg.text(resp.message || resp.error || 'Failed to save disk sizes.').removeClass('alert-success').addClass('alert alert-danger').show();
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
  // Region-rows view: one row per region with a single project dropdown.
  // Storage shape: selected_projects[regionId] = [chosenProjectId] (one).
  // project_names[regionId][projectId] = display name.
  $rawSelected = cloudpe_cmp_admin_get_setting($serverId, 'selected_projects', []);
  $rawNames    = cloudpe_cmp_admin_get_setting($serverId, 'project_names', []);
  $selByRegion   = cloudpe_cmp_admin_is_region_nested($rawSelected) ? (array)$rawSelected : [];
  $namesByRegion = cloudpe_cmp_admin_is_region_nested($rawNames)    ? (array)$rawNames    : [];
  ?>
  <div class="cmp-section">
    <h4>Projects</h4>
    <p class="text-muted">Pick one default project per region. Provisioned VMs in that region will use this project.</p>
    <div class="cmp-toolbar">
      <button class="btn btn-primary" id="btn-load-projects">
        <i class="fa fa-refresh"></i> Load Projects from API
      </button>
      <span id="projects-loading" style="display:none;"><i class="fa fa-spinner fa-spin"></i> <span id="projects-loading-text">Loading...</span></span>
      <span class="cmp-spacer"></span>
      <span id="projects-save-msg" class="cmp-save-msg" style="display:none;"></span>
      <button class="btn btn-success" id="btn-save-projects">
        <i class="fa fa-save"></i> Save Configuration
      </button>
    </div>
    <div id="projects-error" class="alert alert-danger" style="display:none;"></div>

    <div class="cmp-table-wrap">
    <table class="table table-bordered cmp-resource-table" id="projects-table">
      <thead>
        <tr>
          <th style="width:30%;">Region</th>
          <th>Project</th>
        </tr>
      </thead>
      <tbody>
        <!-- Rows rendered by JS once regions load -->
      </tbody>
    </table>
    </div>
    <p class="text-muted" id="projects-empty-hint" style="display:none;">No regions found for this server. Add regions on the CMP side first.</p>
  </div>

  <script>
  (function() {
    var serverId   = <?php echo $serverId; ?>;
    var moduleUrl  = '<?php echo $moduleUrl; ?>';
    var savedSel   = <?php echo json_encode((object)$selByRegion ?: new stdClass()); ?>;
    var savedNames = <?php echo json_encode((object)$namesByRegion ?: new stdClass()); ?>;
    var apiByRegion = {}; // regionId -> [{id, name}]

    function buildRows() {
      var $tbody = $('#projects-table tbody').empty();
      var ids = window.cmpRegionIds || [];
      if (!ids.length) { $('#projects-empty-hint').show(); return; }
      ids.forEach(function(rId) {
        var label   = window.cmpRegions[rId] || rId;
        var savedArr = savedSel[rId] || [];
        var savedId  = Array.isArray(savedArr) && savedArr.length ? String(savedArr[0]) : '';
        var $sel = $('<select class="form-control input-sm proj-select"></select>')
          .attr('data-region', rId)
          .attr('data-saved-id', savedId)
          .append('<option value="">— select project —</option>');
        if (savedId) {
          // Stub option so the saved selection's display name is visible
          // until "Load Projects from API" replaces it with the live list.
          var namesForRegion = (savedNames[rId] && typeof savedNames[rId] === 'object') ? savedNames[rId] : {};
          var savedLabel = namesForRegion[savedId] || savedId;
          $sel.append($('<option>').attr('value', savedId).text(savedLabel).prop('selected', true));
        }
        var $row = $('<tr>').attr('data-region', rId);
        $row.append($('<td>').html('<strong>' + $('<span>').text(label).html() + '</strong><br><small class="text-muted">' + $('<span>').text(rId).html() + '</small>'));
        $row.append($('<td>').append($sel));
        $tbody.append($row);
      });
    }

    function applyApiOptions() {
      $('#projects-table tbody .proj-select').each(function() {
        var $sel    = $(this);
        var rId     = $sel.attr('data-region');
        var savedId = $sel.attr('data-saved-id') || '';
        var projects = apiByRegion[rId] || [];
        $sel.empty().append('<option value="">— select project —</option>');
        var sawSaved = false;
        projects.forEach(function(p) {
          var $opt = $('<option>').attr('value', p.id).text(p.name || p.id);
          if (String(p.id) === savedId) { $opt.prop('selected', true); sawSaved = true; }
          $sel.append($opt);
        });
        if (!sawSaved && savedId) {
          // Saved project no longer in API — keep it visible as an orphan.
          $sel.append($('<option>').attr('value', savedId).text(savedId + ' (no longer in API)').prop('selected', true));
        }
      });
    }

    if (window.cmpRegionIds && window.cmpRegionIds.length) buildRows();
    else $(document).on('cmp:regions-loaded', buildRows);

    $('#btn-load-projects').on('click', function() {
      var btn = $(this).prop('disabled', true);
      $('#projects-error').hide();
      $('#projects-loading').show();
      var ids = window.cmpRegionIds || [];
      if (!ids.length) { btn.prop('disabled', false); $('#projects-loading').hide(); return; }
      var done = 0, total = ids.length;
      function next() {
        if (done >= total) {
          applyApiOptions();
          btn.prop('disabled', false);
          $('#projects-loading').hide();
          return;
        }
        var rId = ids[done++], rLabel = window.cmpRegions[rId] || rId;
        $('#projects-loading-text').text('Loading projects (' + done + '/' + total + ': ' + rLabel + ')...');
        $.post(moduleUrl, { action: 'load_projects', server_id: serverId, region_id: rId }, function(resp) {
          if (resp && resp.success && resp.projects) apiByRegion[rId] = resp.projects;
        }, 'json').always(next);
      }
      next();
    });

    $('#btn-save-projects').on('click', function() {
      var saveBtn = $(this).prop('disabled', true);
      var calls = [];
      var allNames = {}; // regionId -> { projectId: projectName }
      $('#projects-table tbody .proj-select').each(function() {
        var rId = $(this).attr('data-region');
        var pid = $(this).val() || '';
        var ids = pid ? [pid] : [];
        // Track display name for the chosen project, keyed by region.
        if (pid) {
          var $opt = $(this).find('option:selected');
          allNames[rId] = {}; allNames[rId][pid] = $opt.text();
        } else { allNames[rId] = {}; }
        calls.push($.post(moduleUrl, { action: 'save_projects',      server_id: serverId, region_id: rId, selected_projects: ids       }, null, 'json'));
        calls.push($.post(moduleUrl, { action: 'save_project_names', server_id: serverId, region_id: rId, names:             allNames[rId] }, null, 'json'));
      });
      $.when.apply($, calls).always(function() {
        saveBtn.prop('disabled', false);
        $('#projects-save-msg').text('Project configuration saved.').removeClass('error').show();
        setTimeout(function() { $('#projects-save-msg').fadeOut(); }, 3000);
      });
    });
  }());
  </script>
  <?php
}

/**
 * Render the Volume Types tab — Project-style: one row per region with a
 * single dropdown. Storage shape:
 *   selected_volume_types[regionId] = [chosenVtId]
 *   volume_type_names[regionId][vtId] = display name
 */
function cloudpe_cmp_admin_render_volume_types(int $serverId, string $moduleUrl): void
{
  $rawSelected = cloudpe_cmp_admin_get_setting($serverId, 'selected_volume_types', []);
  $rawNames    = cloudpe_cmp_admin_get_setting($serverId, 'volume_type_names', []);
  $selByRegion   = cloudpe_cmp_admin_is_region_nested($rawSelected) ? (array)$rawSelected : [];
  $namesByRegion = cloudpe_cmp_admin_is_region_nested($rawNames)    ? (array)$rawNames    : [];
  ?>
  <div class="cmp-section">
    <h4>Volume Types</h4>
    <p class="text-muted">Pick one default volume type (storage policy) per region. Provisioned VMs in that region will use this volume type.</p>
    <div class="cmp-toolbar">
      <button class="btn btn-primary" id="btn-load-vt">
        <i class="fa fa-refresh"></i> Load Volume Types from API
      </button>
      <span id="vt-loading" style="display:none;"><i class="fa fa-spinner fa-spin"></i> <span id="vt-loading-text">Loading...</span></span>
      <span class="cmp-spacer"></span>
      <span id="vt-save-msg" class="cmp-save-msg" style="display:none;"></span>
      <button class="btn btn-success" id="btn-save-vt">
        <i class="fa fa-save"></i> Save Configuration
      </button>
    </div>
    <div id="vt-error" class="alert alert-danger" style="display:none;"></div>

    <div class="cmp-table-wrap">
    <table class="table table-bordered cmp-resource-table" id="vt-table">
      <thead>
        <tr>
          <th style="width:30%;">Region</th>
          <th>Volume Type</th>
        </tr>
      </thead>
      <tbody>
        <!-- Rows rendered by JS once regions load -->
      </tbody>
    </table>
    </div>
    <p class="text-muted" id="vt-empty-hint" style="display:none;">No regions found for this server.</p>
  </div>

  <script>
  (function() {
    var serverId   = <?php echo $serverId; ?>;
    var moduleUrl  = '<?php echo $moduleUrl; ?>';
    var savedSel   = <?php echo json_encode((object)$selByRegion ?: new stdClass()); ?>;
    var savedNames = <?php echo json_encode((object)$namesByRegion ?: new stdClass()); ?>;
    var apiByRegion = {};

    function buildRows() {
      var $tbody = $('#vt-table tbody').empty();
      var ids = window.cmpRegionIds || [];
      if (!ids.length) { $('#vt-empty-hint').show(); return; }
      ids.forEach(function(rId) {
        var label    = window.cmpRegions[rId] || rId;
        var savedArr = savedSel[rId] || [];
        var savedId  = Array.isArray(savedArr) && savedArr.length ? String(savedArr[0]) : '';
        var $sel = $('<select class="form-control input-sm vt-select"></select>')
          .attr('data-region', rId)
          .attr('data-saved-id', savedId)
          .append('<option value="">— select volume type —</option>');
        if (savedId) {
          var namesForRegion = (savedNames[rId] && typeof savedNames[rId] === 'object') ? savedNames[rId] : {};
          var savedLabel = namesForRegion[savedId] || savedId;
          $sel.append($('<option>').attr('value', savedId).text(savedLabel).prop('selected', true));
        }
        var $row = $('<tr>').attr('data-region', rId);
        $row.append($('<td>').html('<strong>' + $('<span>').text(label).html() + '</strong><br><small class="text-muted">' + $('<span>').text(rId).html() + '</small>'));
        $row.append($('<td>').append($sel));
        $tbody.append($row);
      });
    }

    function applyApiOptions() {
      $('#vt-table tbody .vt-select').each(function() {
        var $sel    = $(this);
        var rId     = $sel.attr('data-region');
        var savedId = $sel.attr('data-saved-id') || '';
        var types   = apiByRegion[rId] || [];
        $sel.empty().append('<option value="">— select volume type —</option>');
        var sawSaved = false;
        types.forEach(function(vt) {
          var $opt = $('<option>').attr('value', vt.id).text(vt.name || vt.id);
          if (String(vt.id) === savedId) { $opt.prop('selected', true); sawSaved = true; }
          $sel.append($opt);
        });
        if (!sawSaved && savedId) {
          $sel.append($('<option>').attr('value', savedId).text(savedId + ' (no longer in API)').prop('selected', true));
        }
      });
    }

    if (window.cmpRegionIds && window.cmpRegionIds.length) buildRows();
    else $(document).on('cmp:regions-loaded', buildRows);

    $('#btn-load-vt').on('click', function() {
      var btn = $(this).prop('disabled', true);
      $('#vt-error').hide();
      $('#vt-loading').show();
      var ids = window.cmpRegionIds || [];
      if (!ids.length) { btn.prop('disabled', false); $('#vt-loading').hide(); return; }

      var done = 0, total = ids.length, errors = [];
      function next() {
        if (done >= total) {
          applyApiOptions();
          btn.prop('disabled', false);
          $('#vt-loading').hide();
          if (errors.length) {
            $('#vt-error').html('<strong>Some regions failed:</strong><ul style="margin:6px 0 0 18px;">' +
              errors.map(function(e) { return '<li>' + $('<span>').text(e).html() + '</li>'; }).join('') + '</ul>').show();
          }
          return;
        }
        var rId = ids[done++], rLabel = window.cmpRegions[rId] || rId;
        $('#vt-loading-text').text('Loading volume types (' + done + '/' + total + ': ' + rLabel + ')...');
        $.post(moduleUrl, { action: 'load_volume_types', server_id: serverId, region_id: rId }, function(resp) {
          if (resp && resp.success) {
            apiByRegion[rId] = resp.volume_types || [];
            if (!(resp.volume_types && resp.volume_types.length)) {
              errors.push(rLabel + ': API returned 0 volume types.');
            }
          } else {
            errors.push(rLabel + ': ' + ((resp && (resp.error || resp.message)) || 'unknown error'));
          }
        }, 'json').fail(function(xhr) {
          errors.push(rLabel + ': HTTP ' + xhr.status + ' ' + (xhr.statusText || ''));
        }).always(next);
      }
      next();
    });

    $('#btn-save-vt').on('click', function() {
      var saveBtn = $(this).prop('disabled', true);
      var calls = [];
      $('#vt-table tbody .vt-select').each(function() {
        var rId = $(this).attr('data-region');
        var vtId = $(this).val() || '';
        var ids = vtId ? [vtId] : [];
        var names = {};
        if (vtId) names[vtId] = $(this).find('option:selected').text();
        calls.push($.post(moduleUrl, { action: 'save_volume_types',      server_id: serverId, region_id: rId, selected_volume_types: ids   }, null, 'json'));
        calls.push($.post(moduleUrl, { action: 'save_volume_type_names', server_id: serverId, region_id: rId, names:                 names }, null, 'json'));
      });
      $.when.apply($, calls).always(function() {
        saveBtn.prop('disabled', false);
        $('#vt-save-msg').text('Configuration saved.').removeClass('error').show();
        setTimeout(function() { $('#vt-save-msg').fadeOut(); }, 3000);
      });
    });
  }());
  </script>
  <?php
}

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
 * Render the Create Config Group tab.
 *
 * Shows existing config groups and a form to create a new one.
 *
 * @param int    $serverId  Active server ID
 * @param string $moduleUrl Base module URL
 */
function cloudpe_cmp_admin_render_create_group(int $serverId, string $moduleUrl, string $regionId = ''): void
{
  // Region scoping removed in v1.1.2-beta.5: a created group includes the
  // union of every region's saved images / flavors plus the server-wide disks.
  $rawImages   = cloudpe_cmp_admin_get_setting($serverId, 'selected_images', []);
  $rawFlavors  = cloudpe_cmp_admin_get_setting($serverId, 'selected_flavors', []);
  $rawDisks    = cloudpe_cmp_admin_get_setting($serverId, 'disk_sizes', []);

  $flatten = function ($v) {
    if (!is_array($v)) return [];
    if (cloudpe_cmp_admin_is_region_nested($v)) {
      $u = [];
      foreach ($v as $slice) { if (is_array($slice)) foreach ($slice as $i) $u[] = $i; }
      return array_values(array_unique($u));
    }
    return $v;
  };
  $savedImages  = $flatten($rawImages);
  $savedFlavors = $flatten($rawFlavors);

  // Disks are flat already; if legacy region-nested, dedup by size.
  $savedDisks = [];
  if (cloudpe_cmp_admin_is_region_nested($rawDisks)) {
      $seen = [];
      foreach ($rawDisks as $slice) { if (is_array($slice)) foreach ($slice as $d) {
          $sz = (int)($d['size_gb'] ?? 0);
          if ($sz > 0 && !isset($seen[$sz])) { $seen[$sz] = true; $savedDisks[] = $d; }
      } }
  } elseif (is_array($rawDisks)) {
      $savedDisks = $rawDisks;
  }

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
            <small class="text-muted">Group will include the union of every region's saved images and flavors, plus the global disk sizes.</small>
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
    var regionId  = <?php echo json_encode($regionId); ?>;

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

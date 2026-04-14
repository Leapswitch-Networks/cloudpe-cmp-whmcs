# Changelog

All notable changes to the CloudPe CMP WHMCS Module will be documented in this file.

## [1.0.6] - 2026-04-14

### Fixed
- Installer was blocking updates on shared hosts with "Module directory is not writable by the web server". `is_writable()` returns false on many shared-hosting setups (suPHP, PHP-FPM as the site user, ACLs) even though `copy()` would actually succeed. Removed the blanket pre-check. Per-file copy failures are still individually tracked and shown in the install stats, so real permission errors are still surfaced.

## [1.0.5] - 2026-04-14

### Fixed
- **Flavors/Images: Display Name was saving as ID.** The name resolution used `savedName || loadedName || id` which is truthy even when `savedName` _is_ the ID. Changed to only trust the saved name when it's a non-empty string that differs from the ID; otherwise prefers the API-loaded name.
- **Projects: Region field was a `<select>` dropdown.** Replaced with a plain `<input type="text">` with a `<datalist>` for suggestions (populated when "Load from API" runs), matching the applied/saved table and the user's expectation.

### Added
- **Images tab**: Region column in both the fetch table (read-only from API) and the applied/saved table (editable text). Saved under `image_regions` and included in "Save Image Configuration".
- **Flavors tab**: Region column in both the fetch table (read-only from API) and the applied/saved table (editable text). Saved under `flavor_regions` and included in "Save Flavor Configuration".

## [1.0.4] - 2026-04-13

### Fixed
- **CRITICAL: Auto-updater was writing files to the wrong directory.** The installer had one too many `dirname()` calls when resolving the WHMCS root, so files were being written one level _above_ the WHMCS root. `copy()` succeeded (parent directory was typically writable), the status message reported "N files written", and `opcache_invalidate()` silently returned false because no cache entry existed at the (wrong) path — which is why "0 opcache entries cleared" appeared despite the successful-looking count. The module files on disk were never actually updated. The installer now resolves the root correctly and hard-fails if the resolved path doesn't contain `init.php` / `configuration.php`.

## [1.0.3] - 2026-04-13

### Fixed
- Create Config Group was writing the UUID as the Display Name when the saved `image_names` / `flavor_names` map was empty or equal to the ID. The group creator now live-fetches names from the CMP API as a fallback and persists them for next time.
- Nameserver prefix fields (`ns1prefix` / `ns2prefix`) were still showing on the cart configure page and the admin product-edit page. v1.0.1's hook only matched `ns1` / `ns2`, not the `*prefix` variants WHMCS actually renders for server-type products. The hook now hides both name variants on both the client cart and the admin `configproducts.php` page.

### Changed
- Projects tab now has a **Region** column on both the fetch table and the applied/saved table. Regions are picked from a dropdown populated by a live `/regions` query, and saved under `project_regions`.
- Removed the separate **Security Groups** tab from the admin navigation to keep the admin module focused. The loader/AJAX handlers are retained internally so nothing linked to them breaks.

## [1.0.2] - 2026-04-13

### Fixed
- **Auto-updater was writing files but serving old code**: PHP OPcache is now invalidated for every PHP file the installer writes (plus `opcache_reset()` at the end). Previously, files were updated on disk but OPcache continued to serve the old bytecode, making it look like the update didn't apply.
- Installer now pre-checks that module directories are writable and reports per-file copy failures instead of silently succeeding. The "Module updated successfully" message was being returned even when `copy()` failed.
- The Updates tab now shows a per-install diagnostic: files written, files failed, OPcache entries cleared, and the first failure reason. Makes permission/opcache issues visible at a glance.

## [1.0.1] - 2026-04-13

### Fixed
- Create Config Group failing with `tblconfigoptionsgroups doesn't exist` - switched to the correct WHMCS tables (`tblproductconfiggroups`, `tblproductconfigoptions`, `tblproductconfigoptionssub`) and added required columns (`qtyminimum`, `qtymaximum`, `order`)
- Selected Images / Flavors Configuration: "Display Name" now defaults to the resource's real name instead of its UUID. Names are auto-persisted on Apply so they survive page reloads.

### Added
- **Projects** tab in CloudPe CMP Manager - load Projects from the CMP API, curate the list, and set friendly display names. Backed by a new `listProjects()` API client method that handles missing endpoints gracefully.
- **Security Groups** tab in CloudPe CMP Manager - load security groups scoped to the server's project, pick which ones to expose, and set display names.
- Product module configuration: **Flavor**, **Image**, **Region**, **Security Group**, and **Storage Policy** are now proper dropdowns. Loaders prefer the admin-curated selection (with saved Display Names) and fall back to a live API listing when no curation exists yet.
- New product options: **Project** (per-product override of the server-level project) and **Default Disk Size** (dropdown sourced from the admin's Disk Sizes tab).
- Security groups are now included in the `POST /instances` request when a Default Security Group is configured.

### Changed
- Order configure page no longer shows `ns1` / `ns2` nameserver fields for CloudPe CMP products (irrelevant for cloud VMs). Injected via `ClientAreaHeadOutput` hook scoped to the `cart` / `confproduct` step.

## [1.0.0] - 2026-04-06

### Added
- Initial release of CloudPe CMP WHMCS provisioning module
- Full VM lifecycle management (create, suspend, unsuspend, terminate)
- Client area with start, stop, restart, VNC console, and password reset
- Console sharing with secure shareable links and management UI
- Boot log viewer in client area
- Admin module (CloudPe CMP Manager) for resource management
- Auto-generate WHMCS configurable options from CMP API resources
- Auto-update checking and one-click installation from GitHub releases
- API Key authentication with CloudPe CMP FastAPI backend
- Multi-region support with region-based resource filtering
- Billing period support (hourly/monthly)
- Volume type selection and disk resize on upgrade
- Disk downgrade prevention via shopping cart validation hook

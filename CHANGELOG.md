# Changelog

All notable changes to the CloudPe CMP WHMCS Module will be documented in this file.

## [1.1.1-beta.2] - 2026-04-15

### Added
- **Security Groups tab** in CloudPe CMP Admin — load from API, single unified table, save selection with display names. Was coded but not wired into the nav.
- **Storage Policies (Volume Types) tab** — new full tab to load, select, and name volume types from the CMP API.
- **Region selector** on Images and Flavors tabs — filter API loads by region before clicking Load from API.
- **IP Assignment** module config option — dropdown: IPv4 Only / IPv6 Only / Both IPv4 and IPv6.
- **Network** module config option — dropdown loaded from `/networks` API endpoint; defaults to project default if left blank.
- `listRegions()` and `listNetworks()` methods in `CloudPeCmpAPI`.
- `region_id` query param support in `listFlavors()` and `listImages()`.
- **Config Group creator** now lists all existing WHMCS configurable option groups with edit links; Storage Policy included as a 4th option in newly created groups.
- Dashboard now shows Security Group and Storage Policy configured counts.

### Changed
- **Unified single-table UI** for Images, Flavors, Projects, Security Groups, and Storage Policies: saved items shown checked on page load; Load from API appends unchecked new items; unchecking a row shows a confirmation dialog before removing; numbered rows (#); no more two-step select → apply flow.
- `cloudpe_cmp_VolumeTypeLoader` now reads from admin-saved `selected_volume_types` first (like other loaders), so the Storage Policy dropdown in Module Settings is populated.

### Fixed
- `listVolumes` called with stale empty-string arg (`listVolumes($projectId, '', $vmId)`) in `hooks.php` after region removal — now `listVolumes($projectId, $vmId)`.
- Nameserver prefix validation error "You must enter a prefix for both nameservers" on order continue — JS now pre-fills `ns1prefix`/`ns2prefix` with `ns1`/`ns2` so WHMCS server-side validation passes while the fields stay hidden.
- Release description overflow — removed `white-space:pre-wrap`, `<li>` items now wrapped in `<ul>`, container uses `overflow:hidden`.

## [1.1.1-beta.1] - 2026-04-15

### Changed
- Removed Region column from Images and Flavors tabs. Region is a query filter on the CMP API, not a per-image/flavor property, so the column added noise without value.
- Updates tab now matches the `cloudpe-whmcs` reference: auto-checks on load, auto-loads releases, accordion panels with Installed/Newer/Older badges, per-release Upgrade/Downgrade buttons, `compareVersions()`, `formatReleaseNotes()`, PHP/WHMCS version in Current Installation table.

### Fixed
- **"Failed to check for updates: Unknown error"** — `check_update` returned `current`/`latest` without a `success` key; JS checked `data.success` which was always `undefined`.
- **Release tags showed "V" not version** — `get_all_releases()` returned raw GitHub API objects; JS expected formatted fields (`version`, `download_url`). Now formats before returning, extracting `version` from `tag_name` and `download_url` from `assets[].browser_download_url`.
- **"No Download Available"** — raw GitHub assets were not mapped to `download_url`.
- **Release description overflowed left** — added `word-break` and `overflow-wrap` to the release notes block.

## [1.1.0] - 2026-04-14

### Fixed
- **Region column blank on "All Regions" load.** Now shows `(All)` when no region filter was selected, so the column is never empty.
- **Count mismatch explained.** Loading with "All Regions" now shows an info banner: _"Loaded N images (all regions combined). This count may exceed the sum of individual regions because some images are available across multiple regions."_ This explains why `all ≠ in-west3 + in-west2 + in-west2-zone-b`.

## [1.0.9] - 2026-04-14

### Fixed
- **Images API** (`GET /images`): error response now includes the HTTP status code and first 300 chars of the response body so a 404 or 401 is visible directly in the admin UI instead of a generic message. Also normalises flat-array, `{items:[]}`, and grouped `{groups:[{images:[]}]}` response shapes.
- **Flavors API** (`GET /flavors`): same HTTP-code + raw-body error surfacing and response shape normalisation.

### Added
- Info note on the **Images** tab: platform images are typically region-agnostic — the region selector tags images for deployment tracking; the list may not change per region.
- Info note on the **Flavors** tab: different regions have different flavors — select a region before loading to get the correct flavor list for that region.

## [1.0.8] - 2026-04-14

### Changed
- **Region column in Selected Images / Selected Flavors** configuration tables is now read-only (plain text, like the ID column). Region is stamped from the fetch region and stored in a `data-region` attribute on each row so it is still persisted correctly on Save Configuration.

## [1.0.7] - 2026-04-14

### Fixed
- **Images and Flavors: Region column was always empty after "Load from API".** The CMP API treats region as a *query filter*, not a per-item response field — no `region` property exists on individual image/flavor objects. Added a **Region selector dropdown** (auto-populated from `/regions` on page load) above each "Load from API" button. The chosen region is sent with the load request and stamped onto every returned row, so the Region column is filled automatically when you Apply the selection.

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

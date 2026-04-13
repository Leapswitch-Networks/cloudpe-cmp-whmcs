# Changelog

All notable changes to the CloudPe CMP WHMCS Module will be documented in this file.

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

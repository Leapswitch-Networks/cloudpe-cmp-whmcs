# CLAUDE.md - Project Guide for Claude Code

**Repository**: https://github.com/Leapswitch-Networks/cloudpe-cmp-whmcs

## Git Branching Workflow

**Claude Code MUST follow this workflow for EVERY task:**

### Before Starting ANY Work

```bash
git branch --show-current
git checkout main && git pull origin main
git checkout -b feature/<descriptive-name>
```

**Branch naming**: `feature/`, `fix/`, `hotfix/`, `refactor/`

### After Completing Work

Ask user before merging. If confirmed:

```bash
git push origin <branch-name>
git checkout main && git pull origin main
git merge --squash <branch-name>
git commit -m "Feature/fix description"
git push origin main
```

**NEVER commit without explicit user approval.**

## Git Commit Rules

- Do NOT add Claude co-authorship or attribution footer to commits
- Do NOT include "Generated with Claude Code" in commit messages
- Do NOT include "Co-Authored-By: Claude" in commit messages

## Project Overview

This is the CloudPe CMP WHMCS Module. It enables WHMCS resellers to provision and manage virtual machines through CloudPe's own CMP (Cloud Management Platform) FastAPI backend.

**Key difference from cloudpe-whmcs**: This module integrates with CloudPe's own FastAPI platform (`/api/v1/*`) using API Key authentication, NOT with Virtuozzo/OpenStack.

## Key Architecture

### Authentication

- Uses API Key (Bearer token) authentication
- API Key is stored in the WHMCS server Password field
- Project ID is stored in the Access Hash field
- Base URL: `https://{hostname}/api/v1`

### File Structure

```
modules/
├── addons/cloudpe_cmp_admin/        # Admin management module
│   └── cloudpe_cmp_admin.php        # Config options, updates, resource management
└── servers/cloudpe_cmp/             # Provisioning module
    ├── cloudpe_cmp.php              # WHMCS hooks (create, suspend, terminate, etc.)
    ├── hooks.php                    # Client area hooks
    ├── ajax.php                     # Client area AJAX endpoint
    ├── console_share.php            # Public console share page
    ├── console_share_api.php        # Public console share API
    ├── lib/
    │   ├── CloudPeCmpAPI.php        # CMP FastAPI client
    │   └── CloudPeCmpHelper.php     # Utility functions
    └── templates/                   # Client area templates
```

### CloudPeCmpAPI.php - URL Construction

```php
// In constructor:
$this->serverUrl = $protocol . rtrim($hostname, '/');
if (strpos($this->serverUrl, '/api/v1') === false) {
    $this->serverUrl .= '/api/v1';
}
// All requests use: Authorization: Bearer {apiKey}
```

### API Endpoints

After construction, API endpoints are relative to `/api/v1`:

- Instances: `/instances`, `/instances/{id}`, `/instances/{id}/actions`
- Flavors: `/flavors`
- Images: `/images`
- Regions: `/regions` (admin filtering only — not a customer config option)
- Networks: `/networks`
- Volumes: `/volumes`, `/volumes/types`, `/volumes/{id}/extend`
- Security Groups: `/security-groups`
- Console: `/instances/{id}/console`, `/instances/{id}/console/share`
- Billing: `/billing/estimate`

## Common Tasks

### Adding a New API Method

1. Add method to `CloudPeCmpAPI.php`
2. Use `$this->apiRequest('/endpoint', 'METHOD', $data)` for requests
3. Always wrap in try/catch
4. Return `['success' => bool, 'data' => ...]`

### Updating Version

1. Update `CLOUDPE_CMP_MODULE_VERSION` in `cloudpe_cmp_admin.php`
2. Update `@version` in `CloudPeCmpAPI.php` header
3. Update `version.json` in repository root
4. Update `CHANGELOG.md`

### Creating a Release

1. Update all version numbers
2. Create release ZIP: `zip -r cloudpe-cmp-whmcs-module-vX.X.X.zip modules/`
3. Create GitHub release with the ZIP (`gh release create ...`)
4. Remove the local ZIP after it's attached: `rm cloudpe-cmp-whmcs-module-vX.X.X.zip`
5. Update `version.json` download_url

## Testing

### Test Connection

1. WHMCS Admin -> Setup -> Servers -> Test Connection
2. Should return "Connected successfully to CloudPe CMP API."

### Test Resource Loading

1. Addons -> CloudPe CMP Manager -> Images/Flavors tabs
2. Click "Load from API" buttons
3. Resources should populate

### Test VM Creation

1. Create a test product with CloudPe CMP module
2. Place a test order
3. Check provisioning logs

## Dependencies

- PHP 7.4+ (uses typed properties)
- WHMCS 8.0+
- cURL extension
- ZipArchive (for updates)

## API Reference

CloudPe CMP API (FastAPI):

- Base URL: `https://app.cloudpe.com/api/v1`
- Auth: `Authorization: Bearer {api_key}`
- Docs: `https://app.cloudpe.com/api/docs`
- OpenAPI: `https://app.cloudpe.com/api/openapi.json`

## Deployment Release Protocol

**NEVER build or publish a release automatically.** After completing any feature or fix, always stop and suggest:

> "Work is done. Would you like me to build a **beta release** (`vX.X.X-beta.N`) or a **final/stable release** (`vX.X.X`)?"

Only proceed with a release when the user explicitly requests one.

### Beta Release (on explicit request)

Version format: `vX.X.X-beta.N` (e.g. `v1.1.1-beta.1`, increment N for successive betas)

Steps:
1. Update `CLOUDPE_CMP_MODULE_VERSION` to `X.X.X-beta.N`
2. Update `version.json` version + download_url to beta tag
3. Update `CHANGELOG.md` with `## [X.X.X-beta.N]` entry
4. Commit + push to `main`
5. Create ZIP: `zip -r cloudpe-cmp-whmcs-module-vX.X.X-beta.N.zip modules/`
6. `gh release create vX.X.X-beta.N ... --prerelease`
7. Remove local ZIP
8. Update `version.json` download_url

### Final / Stable Release (on explicit request)

Version format: `vX.X.X`

Steps:
1. Prepare branch and push
2. Squash merge to main
3. Update CHANGELOG.md
4. Update docs
5. Push main
6. Tag & GitHub release

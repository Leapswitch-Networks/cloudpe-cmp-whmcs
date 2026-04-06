# Installation Guide

## Prerequisites

- WHMCS 8.0 or higher installed and running
- PHP 7.4 or higher with cURL and ZipArchive extensions
- CloudPe CMP account with API access
- API Key generated from CloudPe CMP

## Step 1: Download the Module

### Option A: From GitHub Releases (Recommended)

1. Go to [Releases](https://github.com/Leapswitch-Networks/cloudpe-cmp-whmcs/releases)
2. Download the latest `cloudpe-cmp-whmcs-module-vX.X.X.zip`
3. Extract the ZIP file - you'll see a `modules/` directory

### Option B: Git Clone

```bash
cd /path/to/whmcs
git clone https://github.com/Leapswitch-Networks/cloudpe-cmp-whmcs.git temp_cloudpe_cmp
cp -r temp_cloudpe_cmp/modules/* modules/
rm -rf temp_cloudpe_cmp
```

## Step 2: Upload Files

Upload the extracted `modules/` directory to your WHMCS root. This adds:

```
whmcs/modules/
├── addons/cloudpe_cmp_admin/cloudpe_cmp_admin.php
└── servers/cloudpe_cmp/
    ├── cloudpe_cmp.php
    ├── hooks.php
    ├── ajax.php
    ├── console_share.php
    ├── console_share_api.php
    ├── lib/CloudPeCmpAPI.php
    ├── lib/CloudPeCmpHelper.php
    └── templates/*.tpl
```

Ensure file permissions allow the web server to read the files (typically 644 for files, 755 for directories).

## Step 3: Activate the Admin Module

1. Log into WHMCS Admin
2. Go to **Setup -> Addon Modules**
3. Find **CloudPe CMP Manager** in the list
4. Click **Activate**
5. Configure access control (which admin roles can access the module)

## Step 4: Add a Server

1. Go to **Setup -> Products/Services -> Servers**
2. Click **Add New Server**
3. Fill in:

| Field | Value |
|-------|-------|
| Name | CloudPe CMP (or your preferred name) |
| Hostname | `app.cloudpe.com` (or your CMP URL) |
| Username | (optional - your email for reference) |
| Password | Your CMP API Key |
| Access Hash | Your Project ID (UUID) |
| Type | CloudPe CMP |
| Secure | Checked (for HTTPS) |

4. Click **Test Connection** to verify everything works

## Step 5: Create a Product

1. Go to **Setup -> Products/Services -> Products/Services**
2. Create a new product or edit an existing one
3. Go to the **Module Settings** tab
4. Select **CloudPe CMP** as the module
5. Configure the options (flavor, image, region, etc.)
6. Create the required custom fields (see [Configuration Guide](configuration.md))

## Updating the Module

The module includes auto-update functionality:

1. Go to **Addons -> CloudPe CMP Manager**
2. Click the **Updates** tab
3. If an update is available, click **Download & Install Update**

The updater automatically backs up your current files before installing.

## Uninstallation

1. Deactivate the addon module in **Setup -> Addon Modules**
2. Remove the `modules/servers/cloudpe_cmp/` directory
3. Remove the `modules/addons/cloudpe_cmp_admin/` directory
4. (Optional) Drop the `mod_cloudpe_cmp_settings` and `mod_cloudpe_cmp_console_shares` database tables

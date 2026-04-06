# CloudPe CMP WHMCS Module

A comprehensive WHMCS provisioning module for CloudPe CMP (Cloud Management Platform) that enables partners to sell virtual machines to their customers.

## Features

- **VM Lifecycle Management**: Create, suspend, unsuspend, and terminate VMs
- **Customer Self-Service**: Start, stop, restart VMs and access VNC console
- **Console Sharing**: Generate shareable console access links with expiry
- **Boot Log Viewer**: View VM boot logs from the client area
- **Dynamic Resource Loading**: Fetch flavors, images, regions, and volume types from CMP API
- **Configurable Options**: Auto-generate WHMCS configurable options from CMP resources
- **Auto-Updates**: Automatic update checking and one-click installation
- **Multi-Region Support**: Support for multiple regions with region-based filtering
- **Billing Period**: Support for hourly and monthly billing periods
- **API Key Authentication**: Simple and secure Bearer token authentication

## Requirements

- WHMCS 8.0 or higher
- PHP 7.4 or higher
- CloudPe CMP account with API access
- API Key from CloudPe CMP

## Installation

### Method 1: Download Release

1. Download the latest release from [Releases](https://github.com/Leapswitch-Networks/cloudpe-cmp-whmcs/releases)
2. Extract the ZIP file
3. Upload the `modules` folder to your WHMCS root directory
4. Go to **Setup -> Addon Modules** and activate "CloudPe CMP Manager"
5. Go to **Setup -> Products/Services -> Servers** and add a new server

### Method 2: Git Clone

```bash
cd /path/to/whmcs
git clone https://github.com/Leapswitch-Networks/cloudpe-cmp-whmcs.git temp_cloudpe_cmp
cp -r temp_cloudpe_cmp/modules/* modules/
rm -rf temp_cloudpe_cmp
```

## Configuration

### Server Setup

1. Go to **Setup -> Products/Services -> Servers**
2. Click **Add New Server**
3. Configure:
   - **Name**: CloudPe CMP (or your preferred name)
   - **Hostname**: Your CMP hostname (e.g., `app.cloudpe.com`)
   - **Username**: (optional - email for reference)
   - **Password**: API Key from CMP
   - **Access Hash**: Project ID (UUID)
   - **Type**: CloudPe CMP
   - **Secure**: checked (HTTPS)

4. Click **Test Connection** to verify

### Getting Your API Key

1. Log into CloudPe CMP (https://app.cloudpe.com)
2. Navigate to API Keys section
3. Create a new API key
4. Copy the key (it's only shown once)
5. Use this key as the server Password in WHMCS

### Product Setup

1. Create a new product or edit existing
2. Go to **Module Settings** tab
3. Select **CloudPe CMP** as the module
4. Configure:
   - **Flavor**: Select VM size
   - **Default Image**: Select OS image
   - **Region**: Select deployment region
   - **Billing Period**: Monthly or Hourly
   - **Security Group**: (optional)
   - **Min Volume Size**: Minimum disk in GB (default: 30)
   - **Storage Policy**: Volume type

### Custom Fields Setup

For each CloudPe CMP product, create 3 custom fields:

| Field Name | Field Type | Description | Admin Only |
|------------|------------|-------------|------------|
| `VM ID` | Text Box | Stores the instance UUID | Yes |
| `Public IPv4` | Text Box | Stores the VM's public IPv4 address | Yes |
| `Public IPv6` | Text Box | Stores the VM's public IPv6 address | Yes |

**Important:** Field names must match exactly (case-sensitive).

### CloudPe CMP Manager (Admin Module)

Access via **Addons -> CloudPe CMP Manager**:

- **Dashboard**: Server info and connection test
- **Images Tab**: Load and select OS images, set display names and prices
- **Flavors Tab**: Load and select VM sizes, set display names and prices
- **Disk Sizes Tab**: Configure disk options with pricing
- **Create Config Group**: Auto-generate WHMCS configurable options
- **Updates Tab**: Check for and install module updates

## File Structure

```
modules/
├── addons/
│   └── cloudpe_cmp_admin/
│       └── cloudpe_cmp_admin.php    # Admin management module
└── servers/
    └── cloudpe_cmp/
        ├── cloudpe_cmp.php          # Main provisioning module
        ├── hooks.php                # WHMCS hooks
        ├── ajax.php                 # Client area AJAX endpoint
        ├── console_share.php        # Public console share page
        ├── console_share_api.php    # Console share API
        ├── lib/
        │   ├── CloudPeCmpAPI.php    # CMP API client
        │   └── CloudPeCmpHelper.php # Helper functions
        └── templates/
            ├── overview.tpl         # Client area VM overview
            ├── no_vm.tpl            # No VM template
            └── error.tpl            # Error template
```

## API Methods

The CloudPeCmpAPI class provides these methods:

### Connection
- `testConnection()` - Test API connectivity

### Instances
- `createInstance($params)` - Create a new VM
- `getInstance($id, $sync)` - Get instance details
- `listInstances($filters)` - List instances
- `updateInstance($id, $data)` - Update instance
- `deleteInstance($id)` - Delete instance

### Instance Actions
- `startInstance($id)` - Start a VM
- `stopInstance($id)` - Stop a VM
- `rebootInstance($id)` - Reboot a VM
- `suspendInstance($id)` - Suspend a VM
- `resumeInstance($id)` - Resume a VM
- `changePassword($id, $password)` - Change admin password

### Console
- `getConsoleUrl($id)` - Get VNC console URL
- `getConsoleOutput($id, $length)` - Get boot log
- `createConsoleShare($id)` - Create shareable console link
- `listConsoleShares($id)` - List share links
- `revokeConsoleShare($id, $shareId)` - Revoke share link

### Resources
- `listFlavors($region)` - List available flavors
- `listImages($region)` - List available images
- `listRegions($service)` - List available regions
- `listSecurityGroups($projectId, $region)` - List security groups
- `listVolumeTypes($region)` - List volume types

### Volumes
- `getVolume($id)` - Get volume details
- `listVolumes($projectId, $region, $vmId)` - List volumes
- `extendVolume($id, $newSize)` - Extend volume size

### Billing
- `estimateCost($params)` - Estimate VM cost

## Troubleshooting

### HTTP 401 Error
- Verify your API Key is correct
- Ensure the API key hasn't been revoked
- Check that the key has appropriate permissions

### HTTP 404 Error
- Check the hostname is correct
- Ensure `/api/v1` path is accessible
- Verify the instance/resource ID exists

### Connection Failed
- Verify the hostname is accessible
- Check SSL settings (Secure checkbox)
- Review WHMCS module debug logs

### Resources Not Loading
- Verify server connection in **Setup -> Servers**
- Check that the API key has permission to list resources
- Try the CMP API docs directly: `https://{hostname}/api/docs`

### VM Creation Fails
- Check all required fields: flavor, image, region, project ID
- Verify Project ID is set in Access Hash field
- Review module logs for detailed error messages

## Contributing

1. Fork the repository
2. Create a feature branch (`git checkout -b feature/amazing-feature`)
3. Commit your changes (`git commit -m 'Add amazing feature'`)
4. Push to the branch (`git push origin feature/amazing-feature`)
5. Open a Pull Request

## License

This project is licensed under the MIT License - see the [LICENSE](LICENSE) file for details.

## Support

- **Issues**: [GitHub Issues](https://github.com/Leapswitch-Networks/cloudpe-cmp-whmcs/issues)
- **API Docs**: [CloudPe CMP API](https://app.cloudpe.com/api/docs)

## Changelog

See [CHANGELOG.md](CHANGELOG.md) for version history.

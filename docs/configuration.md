# Configuration Guide

## Server Configuration

### Getting Your API Key

1. Log into CloudPe CMP at https://app.cloudpe.com
2. Navigate to **API Keys** section
3. Click **Create New API Key**
4. Give it a name (e.g., "WHMCS Integration")
5. **Copy the key immediately** - it's only shown once
6. Use this key as the Password in WHMCS server config

### Getting Your Project ID

1. In CloudPe CMP, navigate to your project
2. The Project ID (UUID) is shown in the project settings or URL
3. Use this as the Access Hash in WHMCS server config

### WHMCS Server Settings

| Field | Value | Notes |
|-------|-------|-------|
| Name | CloudPe CMP | Display name in WHMCS |
| Hostname | `app.cloudpe.com` | CMP API hostname (no path, no protocol) |
| Username | (optional) | Your email for reference |
| Password | `your-api-key` | CMP API Key (Bearer token) |
| Access Hash | `project-uuid` | Project ID for VM deployment |
| Type | CloudPe CMP | Select from module dropdown |
| Secure | Checked | Use HTTPS (recommended) |

## Product Configuration

### Module Settings (Config Options)

When editing a product's Module Settings (two-column layout), these options
are available. All four are text inputs in advanced mode (pre-filled with
the stored ID) and Loader-driven dropdowns in simple mode:

| Position | Option | Source |
|----------|--------|--------|
| 1 | Default Region        | Admin addon → **Regions** tab |
| 2 | Default Server Size   | Admin addon → **Flavors** tab |
| 3 | Default Disk Space    | Admin addon → **Disk Sizes** tab |
| 4 | Default Operating System | Admin addon → **Images** tab |

Volume Type is **not** a Module Setting — it is configured per region in
the admin addon's **Volume Types** tab and resolved from the chosen
region at provision time.

### Custom Fields (Required)

Each CloudPe CMP product **must** have these custom fields:

1. Go to **Setup -> Products/Services -> Products/Services**
2. Edit your CloudPe CMP product
3. Go to **Custom Fields** tab
4. Create:

| Field Name | Field Type | Admin Only | Description |
|------------|------------|------------|-------------|
| `VM ID` | Text Box | Yes | Stores the instance UUID |
| `Public IPv4` | Text Box | Yes | Stores the VM's public IPv4 |
| `Public IPv6` | Text Box | Yes | Stores the VM's public IPv6 |

**Field names are case-sensitive** and must match exactly.

## Configurable Options

Configurable options allow customers to choose their VM specs during ordering.

### Auto-Generation via Admin Module

1. Go to **Addons -> CloudPe CMP Manager**
2. On each tab — **Images**, **Flavors**, **Projects**, **Volume Types** —
   click **Load from API** and pick the resources you want to offer per region
3. Open the **Regions** tab and tick the regions to expose on the cart.
   A region is only eligible after Images + Flavors + Projects + Volume Types
   are configured for it
4. (Optional) On **Disk Sizes** set up server-wide disk options + monthly prices
5. Go to **Config Groups** tab and pick the products to wire up
6. Click **Create Configurable Options Group**

This creates a WHMCS configurable options group with:
- **Region**: Dropdown of admin-allowed regions (cart cascade root)
- **Operating System**: Dropdown of (region × image) sub-options
- **Server Size**: Dropdown of (region × flavor) sub-options
- **Disk Space**: Dropdown of configured disk sizes (server-wide)

The cart-side cascade hides OS/Server Size sub-options that don't belong
to the selected Region.

### Linking to Products

1. Go to **Setup -> Products/Services -> Configurable Options**
2. Find your newly created group
3. Click **Assigned Products**
4. Select your CloudPe CMP products

### Option Name Format

The module reads configurable options by name. Supported names:

| Config Option Name | Maps To | Format |
|-------------------|---------|--------|
| `Region` | Region UUID | `{region_id}\|{display_name}` |
| `Operating System`, `Image`, `OS` | Image ID | `{image_id}\|{display_name} — {region}` |
| `Server Size`, `Flavor`, `Plan` | Flavor ID | `{flavor_id}\|{display_name} — {region}` |
| `Disk Space`, `Volume Size` | Volume size | `{size_gb}\|{display_label}` |

The trailing ` — {region}` suffix on OS / Server Size sub-options is what
the cart cascade JS uses to filter options when Region changes.

## Multi-Region Setup

A single WHMCS server entry can now expose multiple regions — configure
each region's Images / Flavors / Projects / Volume Types in the admin
addon, then tick them in the **Regions** tab. The cart's Region dropdown
will only list ticked regions.

To support multiple regions:

1. Create separate WHMCS server entries per region (same API key, different Access Hash/Project ID if needed)
2. Or use a single server and set the Region in product config options
3. Resources (flavors, images) are filtered by region when a region is configured

## Console Sharing

The console sharing feature allows VM owners to create temporary shareable links:

- Links are stored in the `mod_cloudpe_cmp_console_shares` database table (auto-created)
- Each link has a configurable expiry (1h to 30d)
- Links can be revoked at any time
- Share pages use a dark-themed standalone UI
- No WHMCS login required to access shared consoles

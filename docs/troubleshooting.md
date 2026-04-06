# Troubleshooting Guide

## Common Errors

### HTTP 401 - Unauthorized

**Symptoms**: "Invalid credentials", "Authentication failed"

**Causes**:
- Invalid or expired API key
- API key was revoked
- API key doesn't have required permissions

**Solutions**:
1. Verify API key in **Setup -> Servers** (Password field)
2. Generate a new API key from CloudPe CMP
3. Test connection after updating

### HTTP 404 - Not Found

**Symptoms**: "Endpoint not found", "Instance not found"

**Causes**:
- Incorrect hostname
- Wrong API path
- Instance/resource has been deleted

**Solutions**:
1. Check hostname doesn't include protocol or path (just `app.cloudpe.com`)
2. Verify the instance still exists in CloudPe CMP
3. Check that `/api/v1` is accessible: `curl https://{hostname}/api/v1/flavors -H "Authorization: Bearer {key}"`

### HTTP 422 - Validation Error

**Symptoms**: "field required", "value is not valid"

**Causes**:
- Missing required fields in instance creation
- Invalid UUID format
- Invalid flavor/image/region identifier

**Solutions**:
1. Check all config options are set (flavor, image, region)
2. Verify Project ID in Access Hash field is a valid UUID
3. Check module debug logs for the exact validation error

### Connection Failed / cURL Error

**Symptoms**: "Could not connect to server", "SSL certificate problem"

**Causes**:
- Server is unreachable
- SSL certificate issues
- Firewall blocking outbound connections

**Solutions**:
1. Test connectivity: `curl -I https://{hostname}/api/v1/flavors`
2. For SSL issues, try unchecking "Secure" temporarily to diagnose
3. Check WHMCS server can reach the CMP API (outbound HTTPS on port 443)

### VM Creation Fails

**Symptoms**: "Failed to create VM", "Configuration Error"

**Causes**:
- Missing configuration (flavor, image, region, project ID)
- Insufficient quota
- Invalid resource IDs

**Solutions**:
1. Verify all product config options are filled in
2. Check Project ID is set in the server's Access Hash field
3. Test connection first: **Setup -> Servers -> Test Connection**
4. Check module logs: **Utilities -> Logs -> Module Log**
5. Filter for `cloudpe_cmp` in the module log

### Resources Not Loading

**Symptoms**: Empty dropdowns in product config, "Failed to load flavors/images"

**Causes**:
- Server connection not configured
- API key lacks permission
- Network timeout

**Solutions**:
1. Verify server connection works: **Setup -> Servers -> Test Connection**
2. Try loading from admin module: **Addons -> CloudPe CMP Manager -> Images/Flavors**
3. Check API directly: `curl https://{hostname}/api/v1/images -H "Authorization: Bearer {key}"`

### VNC Console Not Working

**Symptoms**: "Console URL not received", blank console window

**Causes**:
- VM is not in ACTIVE state
- Console service temporarily unavailable
- Browser blocking popup/iframe

**Solutions**:
1. Ensure VM is running (ACTIVE status)
2. Try restarting the VM and waiting a moment
3. Check browser popup blocker settings
4. Try opening the console URL directly

### Console Share Links Not Working

**Symptoms**: "Invalid Link", "Link Expired", "VM Not Running"

**Causes**:
- Share link expired or revoked
- VM was stopped after share was created
- Service is no longer active

**Solutions**:
1. Create a new share link from the client area
2. Ensure VM is running when accessing the share
3. Check share management in client area Console dropdown

## Debug Steps

### Enable Module Debug Logging

1. Go to **Setup -> Other -> System Settings -> Logging**
2. Enable **Module Log**
3. Reproduce the issue
4. Check **Utilities -> Logs -> Module Log**
5. Filter for module name `cloudpe_cmp`

### Manual API Test

Test the CMP API directly from your server:

```bash
# Test connection
curl -s https://app.cloudpe.com/api/v1/flavors \
  -H "Authorization: Bearer YOUR_API_KEY" | head -100

# Test instance details
curl -s https://app.cloudpe.com/api/v1/instances/INSTANCE_UUID \
  -H "Authorization: Bearer YOUR_API_KEY"
```

### Check Custom Fields

Ensure custom fields exist and are correctly named:

1. Edit the product in **Setup -> Products/Services**
2. Go to **Custom Fields** tab
3. Verify these exact field names exist:
   - `VM ID` (Admin Only)
   - `Public IPv4` (Admin Only)
   - `Public IPv6` (Admin Only)

### Check Service Data

In WHMCS Admin, view the service:

1. Go to **Clients -> View/Search Clients**
2. Find the client and their service
3. Check the **Module Settings** tab for admin buttons
4. Use **Sync Status** button to refresh data from the API

## Database Tables

The module uses these database tables:

| Table | Purpose |
|-------|---------|
| `mod_cloudpe_cmp_settings` | Admin module settings (images, flavors, prices) |
| `mod_cloudpe_cmp_console_shares` | Console share tokens and tracking |

Both tables are auto-created on first use.

## Getting Help

1. Check the [WHMCS Module Log](https://docs.whmcs.com/Module_Log) for detailed error messages
2. Open an issue on [GitHub](https://github.com/Leapswitch-Networks/cloudpe-cmp-whmcs/issues)
3. Include: WHMCS version, PHP version, error messages from module log

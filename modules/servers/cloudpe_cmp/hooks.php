<?php
/**
 * CloudPe CMP WHMCS Module Hooks
 *
 * Handles validation and UI enhancements for CloudPe CMP services
 *
 * @version 1.0.0
 */

if (!defined("WHMCS")) {
    die("This file cannot be accessed directly");
}

use WHMCS\Database\Capsule;

/**
 * Build the inline CSS+JS blob used to hide nameserver prefix fields
 * on configure pages for CloudPe CMP products. Returning a shared
 * builder means the client-area and admin-area hooks stay in lock-step
 * on exactly which field names / labels to hide.
 *
 * WHMCS ships several field-name variants depending on product type
 * and area:
 *   - client cart (cart.php?a=confproduct): ns1prefix, ns2prefix
 *   - older templates / some modules:       ns1, ns2
 *   - admin product edit (configproducts.php?action=edit): ns1, ns2
 *     plus separate "Nameserver 1 Prefix" / "Nameserver 2 Prefix"
 *     labels.
 * We target all of them and walk up to the nearest field wrapper so
 * both the input and its label row disappear.
 */
function cloudpe_cmp_hide_ns_html(): string
{
    return <<<'HTML'
<style>
  /* CloudPe CMP: nuke nameserver fields in the configure UI */
  input[name="ns1"], input[name="ns2"],
  input[name="ns1prefix"], input[name="ns2prefix"] { display: none !important; }
  label[for="ns1"], label[for="ns2"],
  label[for="ns1prefix"], label[for="ns2prefix"] { display: none !important; }
</style>
<script>
  (function() {
    // Convert ns1/ns2 prefix inputs to hidden with default values.
    // Using type="hidden" is the most reliable approach:
    //   - the value is always submitted (no display:none gotchas)
    //   - WHMCS server-side validation receives the value and passes
    //   - client-side required-field checks skip hidden inputs
    function hideNsFields() {
      var prefixDefaults = { ns1: 'ns1', ns2: 'ns2', ns1prefix: 'ns1', ns2prefix: 'ns2' };
      Object.keys(prefixDefaults).forEach(function(name) {
        document.querySelectorAll('input[name="' + name + '"]').forEach(function(n) {
          if (!n.value) n.value = prefixDefaults[name];
          n.type = 'hidden'; // keeps value in form submission, removes from UI
          // Also hide any visible wrapper in case a label/group lingers
          var wrapper = n.closest('.form-group') || n.closest('tr') || n.closest('.row');
          if (wrapper) wrapper.style.display = 'none';
        });
        document.querySelectorAll('label[for="' + name + '"]').forEach(function(l) {
          var wrapper = l.closest('.form-group') || l.closest('tr') || l.closest('.row') || l.parentNode;
          if (wrapper) wrapper.style.display = 'none';
        });
      });
    }

    if (document.readyState === 'loading') {
      document.addEventListener('DOMContentLoaded', hideNsFields);
    } else {
      hideNsFields();
    }
  })();
</script>
HTML;
}

/**
 * Decide whether the given product id is a CloudPe CMP product.
 */
function cloudpe_cmp_product_is_cmp(int $productId): bool
{
    if ($productId <= 0) return false;
    $product = Capsule::table('tblproducts')->where('id', $productId)->first();
    return $product && $product->servertype === 'cloudpe_cmp';
}

/**
 * Hook: Hide ns1/ns2 (and ns1prefix/ns2prefix) fields on the client
 * order configure page for CloudPe CMP products. Cloud VMs are
 * provisioned through the CMP API, so nameserver inputs are noise.
 */
add_hook('ClientAreaHeadOutput', 1, function ($vars) {
    // Inject on any cart.php page and on configureproduct.php. We key
    // off the product's servertype below, so widening the page check is
    // safe and avoids missing cart flows that route through different
    // filenames/actions depending on WHMCS theme/version.
    $scriptName = basename($_SERVER['SCRIPT_NAME'] ?? '');
    $page = $vars['filename'] ?? '';
    $onCart = ($scriptName === 'cart.php') || ($page === 'cart') || ($scriptName === 'configureproduct.php');
    if (!$onCart) {
        return '';
    }

    // Figure out which product is being configured. Prefer the cart
    // index from the URL; fall back to iterating the session if
    // missing (some cart flows don't pass "i").
    $productId = 0;
    $cartIndex = $_REQUEST['i'] ?? null;
    if ($cartIndex !== null && isset($_SESSION['cart']['products'][$cartIndex]['pid'])) {
        $productId = (int)$_SESSION['cart']['products'][$cartIndex]['pid'];
    } elseif (!empty($_SESSION['cart']['products']) && is_array($_SESSION['cart']['products'])) {
        // If any product in the cart is a CMP product, inject anyway;
        // cheaper than having a stale ns field sneak through.
        foreach ($_SESSION['cart']['products'] as $p) {
            if (!empty($p['pid']) && cloudpe_cmp_product_is_cmp((int)$p['pid'])) {
                $productId = (int)$p['pid'];
                break;
            }
        }
    }

    if (!cloudpe_cmp_product_is_cmp($productId)) {
        return '';
    }

    return cloudpe_cmp_hide_ns_html() . cloudpe_cmp_cart_cascade_html();
});

/**
 * Build the JS that wires the Region → Operating System / Server Size
 * cart cascade. Each OS / Size sub-option's visible label was created
 * with ` — <RegionName>` suffix in `cloudpe_cmp_admin_create_config_group`,
 * so we filter those dropdowns by suffix-match against the selected
 * Region option's label. Disks are server-wide and are not filtered.
 *
 * Rendered alongside the ns-hide block on cart pages for CMP products.
 */
function cloudpe_cmp_cart_cascade_html(): string
{
    return <<<'HTML'
<script>
(function() {
  function findSelectByLabel(name) {
    var match = null;
    document.querySelectorAll('select[name^="configoption["]').forEach(function(sel) {
      if (match) return;
      // Walk up to find a row containing the option name in a label/th/td/strong.
      var node = sel.parentNode;
      for (var i = 0; i < 5 && node; i++, node = node.parentNode) {
        var txt = (node.textContent || '').trim();
        // First chunk before the dropdown — strip the select's own text.
        var labelText = txt.replace(sel.textContent || '', '').trim();
        if (labelText.indexOf(name) === 0 || labelText.toLowerCase().indexOf(name.toLowerCase()) === 0) {
          match = sel; return;
        }
      }
    });
    return match;
  }

  function regionNameOf(opt) {
    if (!opt) return '';
    // The Region <option> label is the bare region name (label set as
    // "<id>|<RegionName>" → the text content is "<RegionName>"). WHMCS
    // sometimes appends " (+price)"; strip the trailing parenthetical.
    return (opt.textContent || '').replace(/\s*\(\+?[^()]*\)\s*$/, '').trim();
  }

  function applyFilter(regionSel, targetSel) {
    if (!regionSel || !targetSel) return;
    var rLabel = regionNameOf(regionSel.selectedOptions[0]);
    var marker = ' — ' + rLabel;
    var firstVisible = null;
    Array.prototype.forEach.call(targetSel.options, function(o) {
      var text = (o.textContent || '').replace(/\s*\(\+?[^()]*\)\s*$/, '').trim();
      var matches = !rLabel || text.indexOf(marker) !== -1;
      o.hidden = !matches;
      o.disabled = !matches;
      if (matches && !firstVisible) firstVisible = o;
    });
    // If the currently-selected option got filtered out, jump to the
    // first visible one so the form stays valid.
    if (targetSel.selectedOptions[0] && targetSel.selectedOptions[0].disabled && firstVisible) {
      targetSel.value = firstVisible.value;
      targetSel.dispatchEvent(new Event('change', { bubbles: true }));
    }
  }

  function wire() {
    var region = findSelectByLabel('Region');
    if (!region) return;
    var os    = findSelectByLabel('Operating System');
    var size  = findSelectByLabel('Server Size');
    if (!os && !size) return;

    function refilter() { applyFilter(region, os); applyFilter(region, size); }
    region.addEventListener('change', refilter);
    refilter();
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', wire);
  } else {
    wire();
  }
})();
</script>
HTML;
}

/**
 * Hook: Hide nameserver prefix fields on the admin product-edit page
 * for CloudPe CMP products. WHMCS shows "Nameserver 1 Prefix" /
 * "Nameserver 2 Prefix" on the Details tab of every server-type
 * product; those are irrelevant for VM provisioning.
 */
add_hook('AdminAreaHeadOutput', 1, function ($vars) {
    // Only inject on the product-edit UI; cheap to check.
    $scriptName = basename($_SERVER['SCRIPT_NAME'] ?? '');
    if ($scriptName !== 'configproducts.php') {
        return '';
    }
    $productId = (int)($_REQUEST['id'] ?? 0);
    if (!cloudpe_cmp_product_is_cmp($productId)) {
        return '';
    }
    return cloudpe_cmp_hide_ns_html();
});

/**
 * Hook: Validate configurable options on upgrade
 * Prevents selecting smaller disk size than current
 */
add_hook('ShoppingCartValidateCheckout', 1, function($vars) {
    if (empty($_SESSION['upgradealiases'])) {
        return;
    }

    foreach ($_SESSION['upgradealiases'] as $upgradeKey => $upgradeData) {
        $serviceId = $upgradeData['serviceid'] ?? 0;
        if (empty($serviceId)) {
            continue;
        }

        $service = Capsule::table('tblhosting')
            ->join('tblproducts', 'tblhosting.packageid', '=', 'tblproducts.id')
            ->where('tblhosting.id', $serviceId)
            ->where('tblproducts.servertype', 'cloudpe_cmp')
            ->select('tblhosting.*', 'tblproducts.id as product_id')
            ->first();

        if (!$service) {
            continue;
        }

        $currentDiskSize = cloudpe_cmp_get_current_disk_size($serviceId, $service->product_id);

        if ($currentDiskSize <= 0) {
            continue;
        }

        $newDiskSize = 0;

        if (!empty($vars['configoptions'])) {
            foreach ($vars['configoptions'] as $optionId => $value) {
                $option = Capsule::table('tblproductconfigoptions')
                    ->where('id', $optionId)
                    ->first();

                if ($option && in_array($option->optionname, ['Disk Space', 'Volume Size'])) {
                    $parts = explode('|', $value);
                    $newDiskSize = (int)$parts[0];
                    break;
                }
            }
        }

        if ($newDiskSize == 0 && !empty($_POST['configoption'])) {
            foreach ($_POST['configoption'] as $optionId => $value) {
                $option = Capsule::table('tblproductconfigoptions')
                    ->where('id', $optionId)
                    ->first();

                if ($option && in_array($option->optionname, ['Disk Space', 'Volume Size'])) {
                    $subOption = Capsule::table('tblproductconfigoptionssub')
                        ->where('id', $value)
                        ->first();

                    if ($subOption) {
                        $parts = explode('|', $subOption->optionname);
                        $newDiskSize = (int)$parts[0];
                    }
                    break;
                }
            }
        }

        if ($newDiskSize > 0 && $newDiskSize < $currentDiskSize) {
            return [
                'error' => "Disk size cannot be reduced. Your current disk is {$currentDiskSize}GB. Please select {$currentDiskSize}GB or larger.",
            ];
        }
    }
});

/**
 * Hook: Add warning message in client area for disk upgrades
 */
add_hook('ClientAreaPageUpgrade', 1, function($vars) {
    $serviceId = $_GET['id'] ?? 0;

    if (empty($serviceId)) {
        return;
    }

    $service = Capsule::table('tblhosting')
        ->join('tblproducts', 'tblhosting.packageid', '=', 'tblproducts.id')
        ->where('tblhosting.id', $serviceId)
        ->where('tblproducts.servertype', 'cloudpe_cmp')
        ->select('tblhosting.*', 'tblproducts.id as product_id')
        ->first();

    if (!$service) {
        return;
    }

    $currentDiskSize = cloudpe_cmp_get_current_disk_size($serviceId, $service->product_id);

    if ($currentDiskSize > 0) {
        return [
            'cloudpe_cmp_current_disk' => $currentDiskSize,
            'cloudpe_cmp_disk_warning' => "Note: Disk size can only be increased. Your current disk is {$currentDiskSize}GB.",
        ];
    }
});

/**
 * Helper: Get current disk size for a CloudPe CMP service
 */
function cloudpe_cmp_get_current_disk_size($serviceId, $productId)
{
    try {
        $serverId = '';

        $field = Capsule::table('tblcustomfields')
            ->where('relid', $productId)
            ->where('type', 'product')
            ->where('fieldname', 'VM ID')
            ->first();

        if ($field) {
            $value = Capsule::table('tblcustomfieldsvalues')
                ->where('fieldid', $field->id)
                ->where('relid', $serviceId)
                ->first();

            $serverId = $value->value ?? '';
        }

        if (empty($serverId)) {
            return 0;
        }

        $service = Capsule::table('tblhosting')->where('id', $serviceId)->first();
        $server = Capsule::table('tblservers')->where('id', $service->server)->first();

        if (!$server) {
            return 0;
        }

        $apiPath = ROOTDIR . '/modules/servers/cloudpe_cmp/lib/CloudPeCmpAPI.php';
        if (!file_exists($apiPath)) {
            return 0;
        }

        require_once $apiPath;

        $api = new CloudPeCmpAPI([
            'serverhostname' => $server->hostname,
            'serverpassword' => decrypt($server->password),
            'serveraccesshash' => $server->accesshash,
            'serversecure' => $server->secure,
        ]);

        // Try to get disk info from instance
        $instanceResult = $api->getInstance($serverId);
        if ($instanceResult['success'] && !empty($instanceResult['instance']['boot_volume_size_gb'])) {
            return (int)$instanceResult['instance']['boot_volume_size_gb'];
        }

        // Fallback: list volumes for the instance
        $projectId = trim($server->accesshash ?? '');
        if (!empty($projectId)) {
            $volumesResult = $api->listVolumes($projectId, $serverId);
            if ($volumesResult['success'] && !empty($volumesResult['volumes'])) {
                return (int)($volumesResult['volumes'][0]['size_gb'] ?? $volumesResult['volumes'][0]['size'] ?? 0);
            }
        }
    } catch (Exception $e) {
        // Silently fail
    }

    return 0;
}

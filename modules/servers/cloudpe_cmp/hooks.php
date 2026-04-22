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
 * True if the given WHMCS product id is a CloudPe CMP product.
 */
function cloudpe_cmp_product_is_cmp(int $productId): bool
{
    if ($productId <= 0) return false;
    $product = Capsule::table('tblproducts')->where('id', $productId)->first();
    return $product && $product->servertype === 'cloudpe_cmp';
}

/**
 * Read the "Hide NS1/NS2 Prefix" module setting (configoption4) for a
 * given product. Returns true when admins want NS fields on the cart,
 * false when the Hide checkbox is ticked.
 *
 * WHMCS Type=yesno stores 'on' when ticked, '' when unticked. Default
 * (blank / never saved) therefore means "show NS prefix", keeping
 * existing installs unchanged.
 */
function cloudpe_cmp_product_shows_ns_prefix(int $productId): bool
{
    if ($productId <= 0) return true;
    $product = Capsule::table('tblproducts')->where('id', $productId)->first();
    if (!$product) return true;
    $hide = strtolower(trim((string)($product->configoption4 ?? '')));
    return $hide !== 'on';
}

/**
 * Find the CloudPe CMP product the visitor is currently configuring.
 * Looks at cart session first (?i=<index>), then falls back to the
 * first CMP product in the cart.
 */
function cloudpe_cmp_current_cart_product_id(): int
{
    $cartIndex = $_REQUEST['i'] ?? null;
    if ($cartIndex !== null && isset($_SESSION['cart']['products'][$cartIndex]['pid'])) {
        return (int)$_SESSION['cart']['products'][$cartIndex]['pid'];
    }
    if (!empty($_SESSION['cart']['products']) && is_array($_SESSION['cart']['products'])) {
        foreach ($_SESSION['cart']['products'] as $p) {
            if (!empty($p['pid']) && cloudpe_cmp_product_is_cmp((int)$p['pid'])) {
                return (int)$p['pid'];
            }
        }
    }
    return 0;
}

/**
 * Hook: hide NS1 / NS2 Prefix inputs on the cart Configure page when
 * the product has "Show NS1/NS2 Prefix" set to No in Module Settings.
 * Driven by the product's configoption4 value so admins can flip it
 * per-product without editing code.
 */
add_hook('ClientAreaHeadOutput', 1, function ($vars) {
    $scriptName = basename($_SERVER['SCRIPT_NAME'] ?? '');
    $page = $vars['filename'] ?? '';
    $onCart = ($scriptName === 'cart.php') || ($page === 'cart') || ($scriptName === 'configureproduct.php');
    if (!$onCart) return '';

    $productId = cloudpe_cmp_current_cart_product_id();
    if (!cloudpe_cmp_product_is_cmp($productId)) return '';
    if (cloudpe_cmp_product_shows_ns_prefix($productId)) return '';

    return <<<'HTML'
<style>
  input[name="ns1"], input[name="ns2"],
  input[name="ns1prefix"], input[name="ns2prefix"] { display: none !important; }
  label[for="ns1"], label[for="ns2"],
  label[for="ns1prefix"], label[for="ns2prefix"] { display: none !important; }
</style>
<script>
(function() {
  function hideNs() {
    var defaults = { ns1: 'ns1', ns2: 'ns2', ns1prefix: 'ns1', ns2prefix: 'ns2' };
    Object.keys(defaults).forEach(function(name) {
      document.querySelectorAll('input[name="' + name + '"]').forEach(function(n) {
        if (!n.value) n.value = defaults[name];
        n.type = 'hidden';
        var wrap = n.closest('.form-group') || n.closest('tr') || n.closest('.row');
        if (wrap) wrap.style.display = 'none';
      });
      document.querySelectorAll('label[for="' + name + '"]').forEach(function(l) {
        var wrap = l.closest('.form-group') || l.closest('tr') || l.closest('.row') || l.parentNode;
        if (wrap) wrap.style.display = 'none';
      });
    });
  }
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', hideNs);
  } else {
    hideNs();
  }
})();
</script>
HTML;
});

/**
 * Hook: on the cart Configure page for a CloudPe CMP product, show the
 * password-policy hint under the Root Password field and block form
 * submission until the password matches the policy. Mirrors the client-
 * area Reset Password modal — same rules, same UX.
 *
 * Policy: 12+ chars, 1 upper, 1 lower, 1 digit, 1 special.
 */
add_hook('ClientAreaHeadOutput', 1, function ($vars) {
    $scriptName = basename($_SERVER['SCRIPT_NAME'] ?? '');
    $page = $vars['filename'] ?? '';
    $onCart = ($scriptName === 'cart.php') || ($page === 'cart') || ($scriptName === 'configureproduct.php');
    if (!$onCart) return '';

    $productId = cloudpe_cmp_current_cart_product_id();
    if (!cloudpe_cmp_product_is_cmp($productId)) return '';

    return <<<'HTML'
<style>
  /* Match the surrounding form inputs and give the password field breathing room. */
  input[name="rootpassword"],
  input[name="rootpw"] {
    width: 100% !important;
    max-width: 100%;
    box-sizing: border-box;
  }
  /* Keep the "Root Password:" label aligned with the input, not vertically
     centered against the tall input+hint column. Covers flex (Bootstrap
     form-group), table rows, and plain block layouts. */
  .cmp-pw-row { align-items: flex-start !important; }
  .cmp-pw-label-top {
    align-self: flex-start !important;
    vertical-align: top !important;
    padding-top: 7px !important;
    margin-top: 0 !important;
  }
  .cmp-pw-wrap { width: 100%; }
  .cmp-pw-hint {
    margin-top: 8px;
    font-size: 12px;
    color: #555;
    background: #f7f7f9;
    border: 1px solid #e1e1e8;
    border-radius: 4px;
    padding: 10px 12px;
    display: none;
  }
  .cmp-pw-hint.cmp-pw-show { display: block; }
  .cmp-pw-hint-title { font-weight: 600; margin-bottom: 6px; color: #333; }
  .cmp-pw-hint-list { list-style: none; margin: 0; padding: 0; }
  .cmp-pw-hint-list li {
    display: flex;
    align-items: center;
    padding: 2px 0;
    line-height: 1.5;
    color: #a94442;
  }
  .cmp-pw-hint-list li .cmp-pw-icon {
    display: inline-block;
    width: 16px;
    text-align: center;
    margin-right: 8px;
    font-weight: 700;
  }
  .cmp-pw-hint-list li.ok { color: #3c763d; }
  .cmp-pw-hint-list li.ok .cmp-pw-icon::before  { content: "\2713"; }
  .cmp-pw-hint-list li.bad .cmp-pw-icon::before { content: "\2715"; }
  .cmp-pw-error {
    color: #a94442;
    font-size: 12px;
    margin-top: 6px;
    display: none;
  }
</style>
<script>
(function() {
  var POLICY = [
    { label: 'At least 12 characters',              test: function(p) { return p.length >= 12; } },
    { label: 'One uppercase letter (A-Z)',          test: function(p) { return /[A-Z]/.test(p); } },
    { label: 'One lowercase letter (a-z)',          test: function(p) { return /[a-z]/.test(p); } },
    { label: 'One number (0-9)',                    test: function(p) { return /[0-9]/.test(p); } },
    { label: 'One special character (!@#$%^&*...)', test: function(p) { return /[^A-Za-z0-9]/.test(p); } }
  ];

  function findRootPasswordInput() {
    return document.querySelector('input[name="rootpassword"], input[name="rootpw"], input[name="password"][type="password"]');
  }

  function findRowContainer($input) {
    return $input.closest('.form-group')
        || $input.closest('tr')
        || $input.closest('.row')
        || $input.parentNode;
  }

  function injectHint($input) {
    if ($input.parentNode.querySelector('.cmp-pw-hint')) return;

    // Force the label to top-align with the input. WHMCS themes
    // variously center-align the label against its row; without this
    // the label floats to the vertical middle of input + hint.
    var row = findRowContainer($input);
    if (row) {
      var labels = row.querySelectorAll('label, .control-label, td:first-child');
      Array.prototype.forEach.call(labels, function(el) { el.classList.add('cmp-pw-label-top'); });
      row.classList.add('cmp-pw-row');
    }

    var box = document.createElement('div');
    box.className = 'cmp-pw-hint';
    var html = '<div class="cmp-pw-hint-title">Password requirements:</div>';
    html += '<ul class="cmp-pw-hint-list">';
    POLICY.forEach(function(r, i) {
      html += '<li class="bad" data-i="' + i + '"><span class="cmp-pw-icon"></span><span>' + r.label + '</span></li>';
    });
    html += '</ul>';
    box.innerHTML = html;

    var errLine = document.createElement('div');
    errLine.className = 'cmp-pw-error';
    errLine.textContent = 'Password does not meet requirements.';

    $input.parentNode.appendChild(box);
    $input.parentNode.appendChild(errLine);
  }

  function evaluate($input) {
    var pw = $input.value || '';
    var box = $input.parentNode.querySelector('.cmp-pw-hint');
    if (!box) return true;
    var allOk = true;
    POLICY.forEach(function(rule, i) {
      var li = box.querySelector('li[data-i="' + i + '"]');
      if (!li) return;
      if (rule.test(pw)) { li.className = 'ok'; } else { li.className = 'bad'; allOk = false; }
    });
    var err = $input.parentNode.querySelector('.cmp-pw-error');
    if (err) err.style.display = 'none';
    updateHintVisibility($input, allOk);
    return allOk;
  }

  // Show the hint when the field is focused, or when it's blurred but
  // contains a password that fails the policy. Stays hidden when empty
  // and unfocused, and hides on blur once all rules pass.
  function updateHintVisibility($input, allOk) {
    var box = $input.parentNode.querySelector('.cmp-pw-hint');
    if (!box) return;
    var focused = document.activeElement === $input;
    var hasContent = ($input.value || '').length > 0;
    if (focused || (hasContent && !allOk)) box.classList.add('cmp-pw-show');
    else box.classList.remove('cmp-pw-show');
  }

  function blockIfBad($input, e) {
    if (($input.value || '').length === 0) return false;
    if (!evaluate($input)) {
      if (e) { e.preventDefault(); e.stopImmediatePropagation(); }
      var err = $input.parentNode.querySelector('.cmp-pw-error');
      if (err) err.style.display = 'block';
      $input.focus();
      return true;
    }
    return false;
  }

  function wire() {
    var $input = findRootPasswordInput();
    if (!$input) return;
    injectHint($input);
    $input.addEventListener('input', function() { evaluate($input); });
    $input.addEventListener('focus', function() { evaluate($input); });
    $input.addEventListener('blur',  function() { evaluate($input); });

    // Block submission unless policy passes. Intercept both form submit
    // (covers standard WHMCS cart forms) and clicks on any submit button
    // anywhere on the page (covers themes that wire their own click handler
    // on the Continue / Checkout button instead of native submit).
    var form = $input.form;
    if (form && !form.__cmpPwBound) {
      form.__cmpPwBound = true;
      form.addEventListener('submit', function(e) { blockIfBad($input, e); }, true);
    }
    if (!document.__cmpPwClickBound) {
      document.__cmpPwClickBound = true;
      document.addEventListener('click', function(e) {
        var btn = e.target.closest('button, input[type="submit"], a.btn');
        if (!btn) return;
        var type = (btn.getAttribute('type') || '').toLowerCase();
        var text = (btn.textContent || btn.value || '').toLowerCase();
        var looksLikeSubmit = type === 'submit'
          || /continue|checkout|complete|place\s*order/.test(text)
          || btn.id === 'btnCompleteOrder'
          || btn.classList.contains('btn-checkout');
        if (!looksLikeSubmit) return;
        var live = findRootPasswordInput();
        if (!live || !document.body.contains(live)) return;
        blockIfBad(live, e);
      }, true);
    }
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', wire);
  } else {
    wire();
  }
})();
</script>
HTML;
});

/**
 * Hook: server-side safety net for the "Show NS1/NS2 Prefix = No" case.
 *
 * When the admin hides NS fields, the client-side JS in our
 * ClientAreaHeadOutput hook converts them to hidden inputs and supplies
 * default values ('ns1' / 'ns2') before submit. If JS is disabled or
 * blocked, the inputs stay empty and WHMCS's cart validator rejects the
 * order. This hook backfills defaults in $_POST so submission always
 * completes cleanly, regardless of Yes/No and regardless of JS state.
 */
add_hook('ShoppingCartValidateCheckout', 1, function ($vars) {
    if (empty($_SESSION['cart']['products']) || !is_array($_SESSION['cart']['products'])) return;
    $anyHiddenNs = false;
    foreach ($_SESSION['cart']['products'] as $p) {
        $pid = (int)($p['pid'] ?? 0);
        if (!cloudpe_cmp_product_is_cmp($pid)) continue;
        if (!cloudpe_cmp_product_shows_ns_prefix($pid)) {
            $anyHiddenNs = true;
            break;
        }
    }
    if (!$anyHiddenNs) return;
    foreach (['ns1prefix' => 'ns1', 'ns2prefix' => 'ns2', 'ns1' => 'ns1', 'ns2' => 'ns2'] as $field => $default) {
        if (empty($_POST[$field]) && empty($_REQUEST[$field])) {
            $_POST[$field]    = $default;
            $_REQUEST[$field] = $default;
        }
    }
});

/**
 * Hook: validate the hostname entered on the cart before checkout.
 *
 * CloudPe CMP requires hostnames to follow RFC 1123 (letters, digits,
 * hyphens, and dots, with no leading/trailing hyphen per label). We
 * reject anything outside this shape up-front so the user gets a clear
 * error on the order form instead of the module silently mutating their
 * input (e.g. "vm-20260423.testing.com" → "vm-20260423testingcom") or
 * CMP appending a collision suffix ("atul-test1" → "atul-test2").
 */
add_hook('ShoppingCartValidateCheckout', 1, function ($vars) {
    if (empty($_SESSION['cart']['products']) || !is_array($_SESSION['cart']['products'])) return;
    $hasCmp = false;
    foreach ($_SESSION['cart']['products'] as $p) {
        if (cloudpe_cmp_product_is_cmp((int)($p['pid'] ?? 0))) { $hasCmp = true; break; }
    }
    if (!$hasCmp) return;

    $hostname = trim((string)($_POST['hostname'] ?? $_REQUEST['hostname'] ?? ''));
    if ($hostname === '') {
        return ['error' => 'Hostname is required.'];
    }
    if (strlen($hostname) > 253) {
        return ['error' => 'Hostname must be 253 characters or fewer.'];
    }
    // Each dot-separated label: alphanumeric start+end, may include
    // hyphens in the middle, max 63 chars. Applies to plain hostnames
    // ("vm-20260423") and FQDNs ("vm-20260423.testing.com") alike.
    $labelRe = '/^[a-zA-Z0-9]([a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?$/';
    foreach (explode('.', $hostname) as $label) {
        if ($label === '' || !preg_match($labelRe, $label)) {
            return ['error' => 'Hostname "' . htmlspecialchars($hostname) . '" is invalid. Use only letters, digits, hyphens and dots; each label must start and end with a letter or digit (e.g. vm-20260423 or vm-20260423.testing.com).'];
        }
    }
});

/**
 * Hook: server-side enforcement of the root password policy on checkout.
 *
 * Mirrors the client-side hint rules (12+ chars, 1 upper, 1 lower, 1 digit,
 * 1 special). Returns a cart error — just like the NS prefix path — so the
 * user is bounced back even if JS is disabled or a theme bypasses the
 * client-side submit blocker.
 */
add_hook('ShoppingCartValidateCheckout', 1, function ($vars) {
    if (empty($_SESSION['cart']['products']) || !is_array($_SESSION['cart']['products'])) return;
    $hasCmp = false;
    foreach ($_SESSION['cart']['products'] as $p) {
        if (cloudpe_cmp_product_is_cmp((int)($p['pid'] ?? 0))) { $hasCmp = true; break; }
    }
    if (!$hasCmp) return;

    $pw = (string)($_POST['rootpassword'] ?? $_POST['rootpw'] ?? $_REQUEST['rootpassword'] ?? $_REQUEST['rootpw'] ?? '');
    if ($pw === '') return;

    $errors = [];
    if (strlen($pw) < 12)             $errors[] = 'at least 12 characters';
    if (!preg_match('/[A-Z]/', $pw))  $errors[] = 'an uppercase letter';
    if (!preg_match('/[a-z]/', $pw))  $errors[] = 'a lowercase letter';
    if (!preg_match('/[0-9]/', $pw))  $errors[] = 'a number';
    if (!preg_match('/[^A-Za-z0-9]/', $pw)) $errors[] = 'a special character';

    if ($errors) {
        return ['error' => 'Root Password must contain ' . implode(', ', $errors) . '.'];
    }
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

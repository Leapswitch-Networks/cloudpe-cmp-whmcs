<div class="panel panel-default">
    <div class="panel-heading">
        <h3 class="panel-title">Virtual Machine Overview</h3>
    </div>
    <div class="panel-body">
        <div id="cloudpe-cmp-alert-container"></div>

        <div class="row">
            <div class="col-md-8">
                <div class="row">
                    <div class="col-sm-6">
                        <h4><i class="fas fa-server"></i> Server Details</h4>
                        <table class="table table-bordered">
                            <tr>
                                <td><strong>Hostname</strong></td>
                                <td>{$hostname}</td>
                            </tr>
                            <tr>
                                <td><strong>Status</strong></td>
                                <td id="vm-status-cell">{$status_label}</td>
                            </tr>
                            <tr>
                                <td><strong>IPv4 Address</strong></td>
                                <td>{if $ipv4}{$ipv4}{else}<span class="text-muted">Not assigned</span>{/if}</td>
                            </tr>
                            <tr>
                                <td><strong>IPv6 Address</strong></td>
                                <td>{if $ipv6}{$ipv6}{else}<span class="text-muted">Not assigned</span>{/if}</td>
                            </tr>
                            <tr>
                                <td><strong>Created</strong></td>
                                <td>{$created|date_format:"%Y-%m-%d %H:%M"}</td>
                            </tr>
                        </table>
                    </div>
                    <div class="col-sm-6">
                        <h4><i class="fas fa-microchip"></i> Configuration</h4>
                        <table class="table table-bordered">
                            <tr>
                                <td><strong>Operating System</strong></td>
                                <td>{$os|default:'Unknown'}</td>
                            </tr>
                            <tr>
                                <td><strong>CPU</strong></td>
                                <td>{$vcpus|default:'-'} vCPU{if $vcpus > 1}s{/if}</td>
                            </tr>
                            <tr>
                                <td><strong>Memory</strong></td>
                                <td>{$ram|default:'-'} GB</td>
                            </tr>
                            <tr>
                                <td><strong>Disk</strong></td>
                                <td>{$disk|default:'-'} GB</td>
                            </tr>
                            <tr>
                                <td><strong>Plan</strong></td>
                                <td><small>{$flavor_name|default:'Unknown'}</small></td>
                            </tr>
                        </table>
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <h4><i class="fas fa-cogs"></i> Actions</h4>
                <div id="vm-actions-container">
                    {if $status == 'ACTIVE'}
                        <button type="button" class="btn btn-warning btn-block" data-action="stop">
                            <i class="fas fa-stop"></i> Stop VM
                        </button>
                        <button type="button" class="btn btn-info btn-block" data-action="restart">
                            <i class="fas fa-sync"></i> Restart VM
                        </button>
                        <div class="btn-group btn-block" style="margin-bottom: 5px;">
                            <button type="button" class="btn btn-primary btn-block dropdown-toggle" data-toggle="dropdown" aria-haspopup="true" aria-expanded="false">
                                <i class="fas fa-terminal"></i> Console <span class="caret"></span>
                            </button>
                            <ul class="dropdown-menu" style="width: 100%;">
                                <li><a href="#" data-action="console"><i class="fas fa-desktop"></i> Open VNC Console</a></li>
                                <li><a href="#" onclick="showBootLogModal(); return false;"><i class="fas fa-file-alt"></i> View Boot Log</a></li>
                                <li class="divider"></li>
                                <li><a href="#" onclick="showShareModal(); return false;"><i class="fas fa-share-alt"></i> Share Console Access</a></li>
                                <li><a href="#" onclick="showShareListModal(); return false;"><i class="fas fa-list"></i> Manage Shares</a></li>
                            </ul>
                        </div>
                        <button type="button" class="btn btn-danger btn-block" data-toggle="modal" data-target="#resetPasswordModal">
                            <i class="fas fa-key"></i> Reset Password
                        </button>
                    {elseif $status == 'SHUTOFF' || $status == 'SHELVED' || $status == 'STOPPED' || $status == 'SHELVED_OFFLOADED'}
                        <button type="button" class="btn btn-success btn-block" data-action="start">
                            <i class="fas fa-play"></i> Start VM
                        </button>
                        <div class="alert alert-warning" style="margin-top: 15px;">
                            <small><i class="fas fa-info-circle"></i> Console and other actions are available when VM is running.</small>
                        </div>
                    {else}
                        <div class="alert alert-info">
                            <i class="fas fa-spinner fa-spin"></i> VM is currently <strong>{$status}</strong>. Please wait...
                        </div>
                    {/if}
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Boot Log Modal -->
<div class="modal fade" id="bootLogModal" tabindex="-1" role="dialog" aria-labelledby="bootLogModalLabel">
    <div class="modal-dialog modal-lg" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <button type="button" class="close" data-dismiss="modal" aria-label="Close"><span aria-hidden="true">&times;</span></button>
                <h4 class="modal-title" id="bootLogModalLabel"><i class="fas fa-file-alt"></i> VM Boot Log</h4>
            </div>
            <div class="modal-body">
                <div class="form-inline" style="margin-bottom: 15px;">
                    <div class="form-group">
                        <label for="bootLogLength">Lines to display:</label>
                        <select id="bootLogLength" class="form-control" style="width: 120px; margin-left: 10px;">
                            <option value="50">50 lines</option>
                            <option value="100" selected>100 lines</option>
                            <option value="500">500 lines</option>
                            <option value="1000">1000 lines</option>
                        </select>
                    </div>
                    <button type="button" class="btn btn-default" onclick="loadBootLog()" style="margin-left: 10px;">
                        <i class="fas fa-sync"></i> Refresh
                    </button>
                </div>
                <pre id="bootLogContent" style="max-height: 400px; overflow-y: auto; background: #1e1e1e; color: #d4d4d4; padding: 15px; font-size: 12px; border-radius: 4px;">Loading...</pre>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-default" data-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<!-- Share Console Modal -->
<div class="modal fade" id="shareConsoleModal" tabindex="-1" role="dialog" aria-labelledby="shareConsoleModalLabel">
    <div class="modal-dialog" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <button type="button" class="close" data-dismiss="modal" aria-label="Close"><span aria-hidden="true">&times;</span></button>
                <h4 class="modal-title" id="shareConsoleModalLabel"><i class="fas fa-share-alt"></i> Share Console Access</h4>
            </div>
            <div class="modal-body">
                <div id="shareFormContainer">
                    <div class="form-group">
                        <label for="shareName">Name (optional)</label>
                        <input type="text" id="shareName" class="form-control" placeholder="e.g., Support Access">
                        <p class="help-block">A friendly name to identify this share link.</p>
                    </div>
                    <div class="form-group">
                        <label for="shareExpiry">Expires In</label>
                        <select id="shareExpiry" class="form-control">
                            <option value="1h">1 Hour</option>
                            <option value="6h">6 Hours</option>
                            <option value="24h" selected>24 Hours</option>
                            <option value="7d">7 Days</option>
                            <option value="30d">30 Days</option>
                        </select>
                    </div>
                    <div class="alert alert-info">
                        <i class="fas fa-info-circle"></i> The share link will only be shown once. Copy it immediately after creation.
                    </div>
                </div>
                <div id="shareResultContainer" style="display: none;">
                    <div class="alert alert-success">
                        <i class="fas fa-check-circle"></i> Share link created successfully!
                    </div>
                    <div class="form-group">
                        <label>Share URL</label>
                        <div class="input-group">
                            <input type="text" id="shareUrl" class="form-control" readonly>
                            <span class="input-group-btn">
                                <button class="btn btn-default" type="button" onclick="copyShareUrl()">
                                    <i class="fas fa-copy"></i> Copy
                                </button>
                            </span>
                        </div>
                    </div>
                    <p class="text-muted"><i class="fas fa-clock"></i> Expires: <span id="shareExpiresAt"></span></p>
                    <div class="alert alert-warning">
                        <i class="fas fa-exclamation-triangle"></i> This URL contains a secret token. Anyone with this link can access the VM console.
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-default" data-dismiss="modal">Close</button>
                <button type="button" class="btn btn-primary" id="btnCreateShare" onclick="createShare()">
                    <i class="fas fa-plus"></i> Create Share
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Manage Shares Modal -->
<div class="modal fade" id="manageSharesModal" tabindex="-1" role="dialog" aria-labelledby="manageSharesModalLabel">
    <div class="modal-dialog modal-lg" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <button type="button" class="close" data-dismiss="modal" aria-label="Close"><span aria-hidden="true">&times;</span></button>
                <h4 class="modal-title" id="manageSharesModalLabel"><i class="fas fa-list"></i> Console Share Links</h4>
            </div>
            <div class="modal-body">
                <div id="sharesListContainer">
                    <p><i class="fas fa-spinner fa-spin"></i> Loading...</p>
                </div>
            </div>
            <div class="modal-footer">
                <label style="float: left; font-weight: normal; margin-top: 7px;">
                    <input type="checkbox" id="showRevokedShares" onchange="loadSharesList()"> Show revoked shares
                </label>
                <button type="button" class="btn btn-default" data-dismiss="modal">Close</button>
                <button type="button" class="btn btn-primary" onclick="showShareModal()">
                    <i class="fas fa-plus"></i> Create New Share
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Reset Password Modal -->
<div class="modal fade" id="resetPasswordModal" tabindex="-1" role="dialog" aria-labelledby="resetPasswordModalLabel">
    <div class="modal-dialog" role="document">
        <div class="modal-content cmp-reset-modal">
            <div class="modal-header cmp-pw-header">
                <h4 class="modal-title" id="resetPasswordModalLabel"><i class="fas fa-key"></i> Reset VM Password</h4>
                <button type="button" class="close" data-dismiss="modal" aria-label="Close"><span aria-hidden="true">&times;</span></button>
            </div>
            <div class="modal-body">
                <div id="reset-pw-alert" style="display:none;"></div>
                <div class="form-group">
                    <label for="new-vm-password" class="cmp-pw-label">New Password</label>
                    <div class="cmp-pw-row">
                        <div class="cmp-pw-input-wrap">
                            <input type="password" id="new-vm-password" class="form-control cmp-pw-input"
                                   autocomplete="new-password" placeholder="Enter a strong password">
                            <i class="fas fa-key cmp-pw-icon"></i>
                        </div>
                        <button type="button" class="btn btn-default cmp-pw-toggle" id="btn-toggle-vm-password" title="Show password" aria-label="Show password"><i class="fas fa-eye"></i></button>
                        <button type="button" class="btn btn-default cmp-pw-generate" id="btn-generate-vm-password" title="Generate a strong password"><i class="fas fa-random"></i> Generate</button>
                    </div>
                </div>
                <div class="cmp-pw-reqs">
                    <div class="cmp-pw-reqs-title"><strong>Password requirements:</strong></div>
                    <ul class="cmp-pw-hint-list">
                        <li class="bad" data-i="0"><span class="cmp-pw-hint-icon"></span><span>At least 12 characters</span></li>
                        <li class="bad" data-i="1"><span class="cmp-pw-hint-icon"></span><span>One uppercase letter (A&ndash;Z)</span></li>
                        <li class="bad" data-i="2"><span class="cmp-pw-hint-icon"></span><span>One lowercase letter (a&ndash;z)</span></li>
                        <li class="bad" data-i="3"><span class="cmp-pw-hint-icon"></span><span>One number (0&ndash;9)</span></li>
                        <li class="bad" data-i="4"><span class="cmp-pw-hint-icon"></span><span>One special character (!@#$%^&amp;*...)</span></li>
                    </ul>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-default" data-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-danger cmp-pw-submit" id="btn-submit-password"><i class="fas fa-key"></i> Reset Password</button>
            </div>
        </div>
    </div>
</div>

<style>
    .cmp-reset-modal .modal-header { border-bottom: 1px solid #eee; display: flex; align-items: center; justify-content: space-between; }
    .cmp-reset-modal .modal-header .modal-title { margin: 0; flex: 1; text-align: left; }
    .cmp-reset-modal .modal-header .close { float: none; margin-left: 12px; opacity: 0.6; }
    .cmp-reset-modal .modal-title i { color: #d9534f; margin-right: 4px; }
    .cmp-reset-modal .cmp-pw-label { display: block; margin-bottom: 8px; font-weight: 600; color: #333; }
    .cmp-reset-modal .cmp-pw-row { display: flex; gap: 8px; align-items: stretch; }
    .cmp-reset-modal .cmp-pw-input-wrap { position: relative; flex: 1; }
    .cmp-reset-modal .cmp-pw-input { padding-right: 34px; height: 38px; }
    .cmp-reset-modal .cmp-pw-icon { position: absolute; right: 10px; top: 50%; transform: translateY(-50%); color: #d9534f; pointer-events: none; }
    .cmp-reset-modal .cmp-pw-generate { height: 38px; white-space: nowrap; }
    .cmp-reset-modal .cmp-pw-toggle  { height: 38px; width: 40px; padding: 0; }
    .cmp-reset-modal .cmp-pw-reqs { margin-top: 14px; font-size: 12px; color:#555; background:#f7f7f9; border:1px solid #e1e1e8; border-radius:4px; padding:10px 12px; }
    .cmp-reset-modal .cmp-pw-reqs-title { margin-bottom: 6px; color:#333; }
    .cmp-reset-modal .cmp-pw-hint-list { list-style:none; padding:0; margin:0; }
    .cmp-reset-modal .cmp-pw-hint-list li { display:flex; align-items:center; padding:2px 0; line-height:1.5; color:#a94442; }
    .cmp-reset-modal .cmp-pw-hint-list li .cmp-pw-hint-icon { display:inline-block; width:16px; text-align:center; margin-right:8px; font-weight:700; }
    .cmp-reset-modal .cmp-pw-hint-list li.ok { color:#3c763d; }
    .cmp-reset-modal .cmp-pw-hint-list li.ok .cmp-pw-hint-icon::before  { content:"\2713"; }
    .cmp-reset-modal .cmp-pw-hint-list li.bad .cmp-pw-hint-icon::before { content:"\2715"; }
</style>

<script>
(function() {
    var serviceId = {$serviceid};
    var currentStatus = '{$status}';
    var ajaxUrl = '{$WEB_ROOT}/modules/servers/cloudpe_cmp/ajax.php';

    var actionLabels = {
        'start': 'Starting VM...',
        'stop': 'Stopping VM...',
        'restart': 'Restarting VM...',
        'console': 'Opening VNC console...',
        'password': 'Resetting password...'
    };

    var confirmMessages = {
        'stop': 'Are you sure you want to stop the VM?',
        'restart': 'Are you sure you want to restart the VM?'
    };

    function showAlert(type, message) {
        var alertClass = 'alert-' + (type === 'error' ? 'danger' : type);
        var iconClass = type === 'success' ? 'check-circle' : (type === 'error' ? 'exclamation-circle' : 'info-circle');
        var html = '<div class="alert ' + alertClass + ' alert-dismissible" role="alert">' +
                   '<button type="button" class="close" data-dismiss="alert" aria-label="Close">' +
                   '<span aria-hidden="true">&times;</span></button>' +
                   '<i class="fas fa-' + iconClass + '"></i> ' + message + '</div>';

        $('#cloudpe-cmp-alert-container').html(html);

        if (type === 'success') {
            setTimeout(function() {
                $('#cloudpe-cmp-alert-container .alert').fadeOut();
            }, 5000);
        }
    }

    function disableButtons() {
        $('#vm-actions-container button').prop('disabled', true);
    }

    function enableButtons() {
        $('#vm-actions-container button').prop('disabled', false);
    }

    function updateStatusLabel(status) {
        var label = '';
        switch(status) {
            case 'ACTIVE':
                label = '<span class="label label-success">Running</span>';
                break;
            case 'SHUTOFF':
            case 'STOPPED':
                label = '<span class="label label-default">Stopped</span>';
                break;
            case 'BUILD':
            case 'REBUILD':
                label = '<span class="label label-info">Building</span>';
                break;
            case 'REBOOT':
            case 'HARD_REBOOT':
                label = '<span class="label label-warning">Rebooting</span>';
                break;
            case 'ERROR':
                label = '<span class="label label-danger">Error</span>';
                break;
            default:
                label = '<span class="label label-warning">' + status + '</span>';
        }
        $('#vm-status-cell').html(label);
        currentStatus = status;
    }

    function executeAction(action) {
        if (confirmMessages[action] && !confirm(confirmMessages[action])) {
            return;
        }

        disableButtons();
        showAlert('info', '<i class="fas fa-spinner fa-spin"></i> ' + (actionLabels[action] || 'Processing...'));

        $.ajax({
            url: ajaxUrl + '?action=' + action + '&service_id=' + serviceId,
            type: 'GET',
            dataType: 'json',
            timeout: 60000,
            success: function(response) {
                if (response.success) {
                    if (action === 'console' && response.url) {
                        showAlert('success', 'Console opened in new window');
                        window.open(response.url, '_blank', 'width=1024,height=768,menubar=no,toolbar=no,location=no,status=no');
                        enableButtons();
                        return;
                    }

                    showAlert('success', response.message);

                    if (response.status) {
                        updateStatusLabel(response.status);
                    }

                    setTimeout(function() {
                        location.reload();
                    }, 2000);
                } else {
                    showAlert('error', response.message || 'Action failed');
                    enableButtons();
                }
            },
            error: function(xhr, status, error) {
                var errorMsg = 'Request failed: ';
                if (status === 'timeout') {
                    errorMsg += 'Operation timed out. Please check VM status and try again.';
                } else if (xhr.status === 0) {
                    errorMsg += 'Network error. Please check your connection.';
                } else if (xhr.status === 403) {
                    errorMsg += 'Access denied. Please log in again.';
                } else {
                    errorMsg += error || 'Unknown error';
                }

                showAlert('error', errorMsg);
                enableButtons();
            }
        });
    }

    function generateStrongPassword(len) {
        len = len || 16;
        var u='ABCDEFGHIJKLMNOPQRSTUVWXYZ', l='abcdefghijklmnopqrstuvwxyz', d='0123456789', s='!@#$%^&*';
        var all = u+l+d+s;
        function pk(x){ return x.charAt(Math.floor(Math.random()*x.length)); }
        var pw = pk(u)+pk(l)+pk(d)+pk(s);
        for (var i=pw.length; i<len; i++) pw += pk(all);
        return pw.split('').sort(function(){ return Math.random()-0.5; }).join('');
    }
    var PW_POLICY = [
        function(p){ return p.length >= 12; },
        function(p){ return /[A-Z]/.test(p); },
        function(p){ return /[a-z]/.test(p); },
        function(p){ return /[0-9]/.test(p); },
        function(p){ return /[^A-Za-z0-9]/.test(p); }
    ];
    var PW_LABELS = ['at least 12 characters','an uppercase letter','a lowercase letter','a number','a special character'];
    function evaluatePasswordHint(pw) {
        var allOk = true; var errors = [];
        PW_POLICY.forEach(function(rule, i) {
            var li = document.querySelector('.cmp-reset-modal .cmp-pw-hint-list li[data-i="'+i+'"]');
            if (rule(pw)) { if (li) li.className = 'ok'; }
            else { if (li) li.className = 'bad'; allOk = false; errors.push(PW_LABELS[i]); }
        });
        return { ok: allOk, errors: errors };
    }

    $(document).ready(function() {
        $('#vm-actions-container').on('click', 'button[data-action]', function(e) {
            e.preventDefault();
            var action = $(this).data('action');
            if (action) {
                executeAction(action);
            }
        });

        $('#vm-actions-container').on('click', '.dropdown-menu a[data-action]', function(e) {
            e.preventDefault();
            var action = $(this).data('action');
            if (action) {
                executeAction(action);
            }
        });

        $('#btn-generate-vm-password').on('click', function() {
            var pw = generateStrongPassword(16);
            $('#new-vm-password').val(pw);
            evaluatePasswordHint(pw);
            $('#reset-pw-alert').hide();
        });
        $('#new-vm-password').on('input', function() {
            evaluatePasswordHint($(this).val() || '');
        });
        $('#btn-toggle-vm-password').on('click', function() {
            var $i = $('#new-vm-password'), $ic = $(this).find('i');
            if ($i.attr('type') === 'password') {
                $i.attr('type','text'); $ic.removeClass('fa-eye').addClass('fa-eye-slash');
                $(this).attr({ title:'Hide password', 'aria-label':'Hide password' });
            } else {
                $i.attr('type','password'); $ic.removeClass('fa-eye-slash').addClass('fa-eye');
                $(this).attr({ title:'Show password', 'aria-label':'Show password' });
            }
        });
        $('#btn-submit-password').on('click', function() {
            var pw = $('#new-vm-password').val();
            var res = evaluatePasswordHint(pw);
            var $alert = $('#reset-pw-alert');
            if (!res.ok) {
                $alert.attr('class','alert alert-danger').text('Password must contain ' + res.errors.join(', ') + '.').show();
                return;
            }
            var $btn = $(this).prop('disabled', true).html('<i class="fas fa-spinner fa-spin"></i> Resetting...');
            $.ajax({
                url: ajaxUrl + '?action=password&service_id=' + serviceId,
                type: 'POST', data: { new_password: pw }, dataType: 'json', timeout: 60000
            }).done(function(r) {
                if (r.success) {
                    $('#resetPasswordModal').modal('hide');
                    showAlert('success', 'Password reset successfully.');
                } else {
                    $alert.attr('class','alert alert-danger').text(r.message || 'Reset failed.').show();
                }
            }).fail(function(xhr, status, error) {
                $alert.attr('class','alert alert-danger').text('Request failed: ' + (error || 'Network error')).show();
            }).always(function() {
                $btn.prop('disabled', false).html('<i class="fas fa-key"></i> Reset Password');
            });
        });
        $('#resetPasswordModal').on('show.bs.modal', function() {
            $('#new-vm-password').val('').attr('type','password');
            $('#btn-toggle-vm-password').attr({ title:'Show password','aria-label':'Show password' })
                .find('i').removeClass('fa-eye-slash').addClass('fa-eye');
            $('#reset-pw-alert').hide();
            evaluatePasswordHint('');
        });
    });

    // Boot Log
    window.showBootLogModal = function() {
        $('#bootLogModal').modal('show');
        loadBootLog();
    };

    window.loadBootLog = function() {
        var length = $('#bootLogLength').val();
        $('#bootLogContent').text('Loading...');

        $.ajax({
            url: ajaxUrl + '?action=console_output&service_id=' + serviceId + '&length=' + length,
            type: 'GET',
            dataType: 'json',
            timeout: 30000,
            success: function(response) {
                if (response.success) {
                    $('#bootLogContent').text(response.output || '(No output available)');
                } else {
                    $('#bootLogContent').text('Error: ' + (response.message || 'Failed to load boot log'));
                }
            },
            error: function(xhr, status, error) {
                $('#bootLogContent').text('Failed to load boot log: ' + (error || 'Network error'));
            }
        });
    };

    // Share Console
    window.showShareModal = function() {
        $('#shareFormContainer').show();
        $('#shareResultContainer').hide();
        $('#btnCreateShare').show();
        $('#shareName').val('');
        $('#shareExpiry').val('24h');
        $('#manageSharesModal').modal('hide');
        $('#shareConsoleModal').modal('show');
    };

    window.createShare = function() {
        var btn = $('#btnCreateShare');
        btn.prop('disabled', true).html('<i class="fas fa-spinner fa-spin"></i> Creating...');

        $.ajax({
            url: ajaxUrl + '?action=console_share_create&service_id=' + serviceId,
            type: 'POST',
            data: {
                name: $('#shareName').val(),
                expiry: $('#shareExpiry').val(),
                console_type: 'novnc'
            },
            dataType: 'json',
            timeout: 30000,
            success: function(response) {
                if (response.success) {
                    $('#shareUrl').val(response.share_url);
                    $('#shareExpiresAt').text(response.expires_at);
                    $('#shareFormContainer').hide();
                    $('#shareResultContainer').show();
                    $('#btnCreateShare').hide();
                } else {
                    showAlert('error', response.message || 'Failed to create share');
                    btn.prop('disabled', false).html('<i class="fas fa-plus"></i> Create Share');
                }
            },
            error: function(xhr, status, error) {
                showAlert('error', 'Failed to create share: ' + (error || 'Network error'));
                btn.prop('disabled', false).html('<i class="fas fa-plus"></i> Create Share');
            }
        });
    };

    window.copyShareUrl = function() {
        var input = document.getElementById('shareUrl');
        input.select();
        input.setSelectionRange(0, 99999);
        try {
            document.execCommand('copy');
            showAlert('success', 'Share URL copied to clipboard!');
        } catch (err) {
            showAlert('error', 'Failed to copy. Please copy manually.');
        }
    };

    // Manage Shares
    window.showShareListModal = function() {
        $('#manageSharesModal').modal('show');
        loadSharesList();
    };

    window.loadSharesList = function() {
        var includeRevoked = $('#showRevokedShares').is(':checked') ? 1 : 0;
        $('#sharesListContainer').html('<p><i class="fas fa-spinner fa-spin"></i> Loading...</p>');

        $.ajax({
            url: ajaxUrl + '?action=console_share_list&service_id=' + serviceId + '&include_revoked=' + includeRevoked,
            type: 'GET',
            dataType: 'json',
            timeout: 30000,
            success: function(response) {
                if (response.success) {
                    renderSharesList(response.shares);
                } else {
                    $('#sharesListContainer').html('<div class="alert alert-danger">' + (response.message || 'Failed to load shares') + '</div>');
                }
            },
            error: function(xhr, status, error) {
                $('#sharesListContainer').html('<div class="alert alert-danger">Failed to load shares: ' + (error || 'Network error') + '</div>');
            }
        });
    };

    function renderSharesList(shares) {
        if (!shares || shares.length === 0) {
            $('#sharesListContainer').html('<div class="alert alert-info"><i class="fas fa-info-circle"></i> No console shares created yet.</div>');
            return;
        }

        var html = '<div class="table-responsive"><table class="table table-striped table-hover">';
        html += '<thead><tr><th>Name</th><th>Created</th><th>Expires</th><th>Uses</th><th>Status</th><th>Action</th></tr></thead>';
        html += '<tbody>';

        for (var i = 0; i < shares.length; i++) {
            var share = shares[i];
            var statusLabel = '';

            if (share.revoked) {
                statusLabel = '<span class="label label-danger">Revoked</span>';
            } else if (share.is_expired) {
                statusLabel = '<span class="label label-warning">Expired</span>';
            } else {
                statusLabel = '<span class="label label-success">Active</span>';
            }

            var name = share.name || '<em class="text-muted">Unnamed</em>';

            html += '<tr>';
            html += '<td>' + name + '</td>';
            html += '<td><small>' + share.created_at + '</small></td>';
            html += '<td><small>' + share.expires_at + '</small></td>';
            html += '<td>' + share.use_count + '</td>';
            html += '<td>' + statusLabel + '</td>';
            html += '<td>';
            if (!share.revoked && !share.is_expired) {
                html += '<button class="btn btn-danger btn-xs" onclick="revokeShare(' + share.id + ')"><i class="fas fa-ban"></i> Revoke</button>';
            } else {
                html += '<span class="text-muted">-</span>';
            }
            html += '</td>';
            html += '</tr>';
        }

        html += '</tbody></table></div>';
        $('#sharesListContainer').html(html);
    }

    window.revokeShare = function(shareId) {
        if (!confirm('Are you sure you want to revoke this share link?')) {
            return;
        }

        $.ajax({
            url: ajaxUrl + '?action=console_share_revoke&service_id=' + serviceId + '&share_id=' + shareId,
            type: 'POST',
            dataType: 'json',
            timeout: 30000,
            success: function(response) {
                if (response.success) {
                    showAlert('success', 'Share link revoked successfully');
                    loadSharesList();
                } else {
                    showAlert('error', response.message || 'Failed to revoke share');
                }
            },
            error: function(xhr, status, error) {
                showAlert('error', 'Failed to revoke share: ' + (error || 'Network error'));
            }
        });
    };
})();
</script>

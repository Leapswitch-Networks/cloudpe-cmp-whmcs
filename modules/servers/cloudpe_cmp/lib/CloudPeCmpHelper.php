<?php
/**
 * CloudPe CMP Helper Functions
 *
 * @version 1.0.0
 */

class CloudPeCmpHelper
{
    public function generateHostname(array $params): string
    {
        $domain = trim((string)($params['domain'] ?? ''));
        if ($domain !== '' && $domain !== 'cloudpe.local') {
            // Pass the hostname through verbatim. Cart-side validation
            // (cloudpe_cmp_ShoppingCartValidateCheckout) rejects invalid
            // characters before we get here, so no silent mutation.
            return $domain;
        }

        $clientId = $params['clientsdetails']['userid'] ?? $params['userid'] ?? rand(1000, 9999);
        $serviceId = $params['serviceid'] ?? rand(1000, 9999);

        return 'vm-' . $clientId . '-' . $serviceId;
    }

    public function generatePassword(int $length = 16): string
    {
        $upper = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ';
        $lower = 'abcdefghijklmnopqrstuvwxyz';
        $numbers = '0123456789';
        $special = '!@#$%^&*';

        $password = $upper[random_int(0, strlen($upper) - 1)];
        $password .= $lower[random_int(0, strlen($lower) - 1)];
        $password .= $numbers[random_int(0, strlen($numbers) - 1)];
        $password .= $special[random_int(0, strlen($special) - 1)];

        $allChars = $upper . $lower . $numbers . $special;
        for ($i = 4; $i < $length; $i++) {
            $password .= $allChars[random_int(0, strlen($allChars) - 1)];
        }

        return str_shuffle($password);
    }

    /**
     * Extract IPs from CMP API instance response
     *
     * CMP API returns ip_addresses as a flat array or object with ipv4/ipv6 fields,
     * unlike OpenStack's nested addresses format.
     */
    public function extractIPs($ipData): array
    {
        $ipv4 = '';
        $ipv6 = '';

        if (is_array($ipData)) {
            // Format: { "ipv4": "1.2.3.4", "ipv6": "::1" }
            if (isset($ipData['ipv4'])) {
                $ipv4 = $ipData['ipv4'];
            }
            if (isset($ipData['ipv6'])) {
                $ipv6 = $ipData['ipv6'];
            }

            // Format: [ { "addr": "1.2.3.4", "version": 4 }, ... ]
            if (empty($ipv4) && empty($ipv6)) {
                foreach ($ipData as $ip) {
                    if (is_array($ip)) {
                        $addr = $ip['addr'] ?? $ip['ip_address'] ?? $ip['address'] ?? '';
                        $version = $ip['version'] ?? (filter_var($addr, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) ? 4 : 6);

                        if ($version == 4 && empty($ipv4)) {
                            $ipv4 = $addr;
                        } elseif ($version == 6 && empty($ipv6)) {
                            $ipv6 = $addr;
                        }
                    } elseif (is_string($ip)) {
                        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) && empty($ipv4)) {
                            $ipv4 = $ip;
                        } elseif (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) && empty($ipv6)) {
                            $ipv6 = $ip;
                        }
                    }
                }
            }

            // Format: OpenStack-style nested { "network_name": [{"addr": "...", "version": 4}] }
            if (empty($ipv4) && empty($ipv6)) {
                foreach ($ipData as $key => $ips) {
                    if (is_array($ips) && !isset($ips['addr'])) {
                        foreach ($ips as $ip) {
                            if (!is_array($ip)) continue;
                            $addr = $ip['addr'] ?? '';
                            $version = $ip['version'] ?? 4;
                            if ($version == 4 && empty($ipv4)) {
                                $ipv4 = $addr;
                            } elseif ($version == 6 && empty($ipv6)) {
                                $ipv6 = $addr;
                            }
                        }
                    }
                }
            }
        }

        return ['ipv4' => $ipv4, 'ipv6' => $ipv6];
    }

    public function getStatusLabel(string $status): string
    {
        $labels = [
            'ACTIVE' => '<span class="label label-success">Active</span>',
            'BUILD' => '<span class="label label-info">Building</span>',
            'BUILDING' => '<span class="label label-info">Building</span>',
            'SHUTOFF' => '<span class="label label-warning">Stopped</span>',
            'STOPPED' => '<span class="label label-warning">Stopped</span>',
            'SUSPENDED' => '<span class="label label-danger">Suspended</span>',
            'PAUSED' => '<span class="label label-warning">Paused</span>',
            'ERROR' => '<span class="label label-danger">Error</span>',
            'DELETED' => '<span class="label label-danger">Deleted</span>',
            'SHELVED' => '<span class="label label-warning">Shelved</span>',
            'SHELVED_OFFLOADED' => '<span class="label label-warning">Shelved</span>',
            'RESIZED' => '<span class="label label-info">Resized</span>',
            'REBOOT' => '<span class="label label-info">Rebooting</span>',
            'HARD_REBOOT' => '<span class="label label-info">Rebooting</span>',
            'MIGRATING' => '<span class="label label-info">Migrating</span>',
            'VERIFY_RESIZE' => '<span class="label label-info">Resize Pending</span>',
        ];

        return $labels[strtoupper($status)] ?? '<span class="label label-default">' . htmlspecialchars($status) . '</span>';
    }

    /**
     * Ensure console share tokens table exists
     */
    public static function ensureConsoleSharesTable(): void
    {
        if (!\WHMCS\Database\Capsule::schema()->hasTable('mod_cloudpe_cmp_console_shares')) {
            \WHMCS\Database\Capsule::schema()->create('mod_cloudpe_cmp_console_shares', function ($table) {
                $table->increments('id');
                $table->string('token_hash', 64)->unique();
                $table->unsignedInteger('service_id');
                $table->string('vm_id', 255);
                $table->unsignedInteger('created_by_user_id')->nullable();
                $table->string('name', 100)->nullable();
                $table->dateTime('expires_at');
                $table->boolean('revoked')->default(false);
                $table->dateTime('revoked_at')->nullable();
                $table->string('revoked_reason', 255)->nullable();
                $table->string('console_type', 20)->default('novnc');
                $table->unsignedInteger('use_count')->default(0);
                $table->dateTime('last_used_at')->nullable();
                $table->string('last_used_ip', 45)->nullable();
                $table->timestamps();

                $table->index('service_id');
                $table->index('vm_id');
                $table->index('expires_at');
                $table->index('created_by_user_id');
            });
        }
    }

    public static function generateShareToken(): array
    {
        $token = bin2hex(random_bytes(32));
        $hash = hash('sha256', $token);
        return ['token' => $token, 'hash' => $hash];
    }

    public static function verifyShareToken(string $token, string $storedHash): bool
    {
        $providedHash = hash('sha256', $token);
        return hash_equals($storedHash, $providedHash);
    }

    public static function findShareByToken(string $token): ?object
    {
        self::ensureConsoleSharesTable();
        $tokenHash = hash('sha256', $token);
        return \WHMCS\Database\Capsule::table('mod_cloudpe_cmp_console_shares')
            ->where('token_hash', $tokenHash)
            ->first();
    }

    public static function recordShareUsage(int $shareId, ?string $ipAddress = null): void
    {
        \WHMCS\Database\Capsule::table('mod_cloudpe_cmp_console_shares')
            ->where('id', $shareId)
            ->update([
                'use_count' => \WHMCS\Database\Capsule::raw('use_count + 1'),
                'last_used_at' => date('Y-m-d H:i:s'),
                'last_used_ip' => $ipAddress ? substr($ipAddress, 0, 45) : null,
                'updated_at' => date('Y-m-d H:i:s'),
            ]);
    }

    public static function getExpiryDurations(): array
    {
        return [
            '1h' => 3600,
            '6h' => 21600,
            '24h' => 86400,
            '7d' => 604800,
            '30d' => 2592000,
        ];
    }
}

<?php
/**
 * CloudPe CMP API Client
 *
 * Communicates with the CloudPe Cloud Management Platform (FastAPI backend).
 * Uses API Key (Bearer token) authentication.
 *
 * @author CloudPe
 * @version 1.0.1
 */

class CloudPeCmpAPI
{
    private $serverUrl;
    private $apiKey;
    private $timeout = 60;
    private $sslVerify = true;

    /**
     * Constructor - Initialize API client with WHMCS server params
     *
     * Expected params from WHMCS Server configuration:
     * - serverhostname: CMP hostname (e.g., app.cloudpe.com)
     * - serverpassword: API Key from CMP
     * - serversecure: SSL verification (on/off)
     * - serveraccesshash: Project ID (UUID)
     */
    public function __construct(array $params)
    {
        $hostname = trim($params['serverhostname'] ?? '');
        $secure = !isset($params['serversecure']) || $params['serversecure'] === 'on' || $params['serversecure'] === true;

        $this->apiKey = $params['serverpassword'] ?? '';
        $this->sslVerify = $secure;

        // Build the server URL
        if (strpos($hostname, 'http://') === 0 || strpos($hostname, 'https://') === 0) {
            $this->serverUrl = rtrim($hostname, '/');
        } else {
            $protocol = $secure ? 'https://' : 'http://';
            $this->serverUrl = $protocol . rtrim($hostname, '/');
        }

        // Ensure /api/v1 suffix
        if (strpos($this->serverUrl, '/api/v1') === false) {
            $this->serverUrl .= '/api/v1';
        }
    }

    /**
     * Get the project ID from WHMCS server access hash
     */
    public function getProjectId(array $params = []): ?string
    {
        return trim($params['serveraccesshash'] ?? '') ?: null;
    }

    // =========================================================================
    // Connection Test
    // =========================================================================

    /**
     * Test connection to CloudPe CMP API
     */
    public function testConnection(): array
    {
        try {
            $response = $this->apiRequest('/flavors', 'GET');

            if ($response['success']) {
                return [
                    'success' => true,
                    'message' => 'Connected successfully to CloudPe CMP API.',
                ];
            }

            return ['success' => false, 'error' => $response['error'] ?? 'Failed to connect'];
        } catch (Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    // =========================================================================
    // Instances
    // =========================================================================

    /**
     * Create a new instance
     *
     * @param array $params Instance parameters:
     *   - flavor: Flavor ID or name
     *   - image: Image ID or name
     *   - name: Instance name
     *   - region: Region slug or UUID
     *   - project_id: Project UUID
     *   - ssh_key_ids: Array of SSH key IDs (optional)
     *   - boot_volume_size_gb: Boot volume size in GB (optional)
     *   - volume_type: Volume type (optional)
     *   - billing_period: Billing period - hourly/monthly (optional)
     */
    public function createInstance(array $params): array
    {
        try {
            $data = [
                'flavor' => $params['flavor'],
                'image' => $params['image'],
                'name' => $params['name'],
                'region' => $params['region'],
                'project_id' => $params['project_id'],
            ];

            if (!empty($params['ssh_key_ids'])) {
                $data['ssh_key_ids'] = (array)$params['ssh_key_ids'];
            }

            if (!empty($params['boot_volume_size_gb'])) {
                $data['boot_volume_size_gb'] = (int)$params['boot_volume_size_gb'];
            }

            if (!empty($params['volume_type'])) {
                $data['volume_type'] = $params['volume_type'];
            }

            if (!empty($params['billing_period'])) {
                $data['billing_period'] = $params['billing_period'];
            }

            if (!empty($params['security_group_ids'])) {
                $data['security_group_ids'] = (array)$params['security_group_ids'];
            }

            $response = $this->apiRequest('/instances', 'POST', $data);

            if (!$response['success']) {
                return $response;
            }

            $result = json_decode($response['body'], true);
            return ['success' => true, 'instance' => $result];
        } catch (Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Get instance details
     *
     * @param string $instanceId Instance UUID
     * @param bool $sync Force sync with hypervisor
     */
    public function getInstance(string $instanceId, bool $sync = false): array
    {
        try {
            $url = '/instances/' . $instanceId;
            if ($sync) {
                $url .= '?sync=true';
            }

            $response = $this->apiRequest($url, 'GET');

            if (!$response['success']) {
                return $response;
            }

            $result = json_decode($response['body'], true);
            return ['success' => true, 'instance' => $result];
        } catch (Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * List instances with optional filters
     */
    public function listInstances(array $filters = []): array
    {
        try {
            $query = http_build_query($filters);
            $url = '/instances' . ($query ? '?' . $query : '');

            $response = $this->apiRequest($url, 'GET');

            if (!$response['success']) {
                return $response;
            }

            $result = json_decode($response['body'], true);
            return ['success' => true, 'instances' => $result['items'] ?? $result];
        } catch (Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Update instance (name, tags, expiry)
     */
    public function updateInstance(string $instanceId, array $data): array
    {
        try {
            $response = $this->apiRequest('/instances/' . $instanceId, 'PATCH', $data);

            if (!$response['success']) {
                return $response;
            }

            $result = json_decode($response['body'], true);
            return ['success' => true, 'instance' => $result];
        } catch (Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Delete an instance
     */
    public function deleteInstance(string $instanceId): array
    {
        try {
            $response = $this->apiRequest('/instances/' . $instanceId, 'DELETE');

            if (in_array($response['httpCode'], [200, 202, 204])) {
                return ['success' => true];
            }

            return $response;
        } catch (Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    // =========================================================================
    // Instance Actions
    // =========================================================================

    /**
     * Perform an action on an instance
     *
     * @param string $instanceId Instance UUID
     * @param string $action Action: start, stop, reboot, hard_reboot, rebuild, suspend, resume, shelve, unshelve
     */
    public function instanceAction(string $instanceId, string $action): array
    {
        try {
            $response = $this->apiRequest(
                '/instances/' . $instanceId . '/actions',
                'POST',
                ['action' => $action]
            );

            if (in_array($response['httpCode'], [200, 202, 204])) {
                return ['success' => true];
            }

            return $response;
        } catch (Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    public function startInstance(string $instanceId): array
    {
        return $this->instanceAction($instanceId, 'start');
    }

    public function stopInstance(string $instanceId): array
    {
        return $this->instanceAction($instanceId, 'stop');
    }

    public function rebootInstance(string $instanceId, bool $hard = false): array
    {
        return $this->instanceAction($instanceId, $hard ? 'hard_reboot' : 'reboot');
    }

    public function suspendInstance(string $instanceId): array
    {
        return $this->instanceAction($instanceId, 'suspend');
    }

    public function resumeInstance(string $instanceId): array
    {
        return $this->instanceAction($instanceId, 'resume');
    }

    public function shelveInstance(string $instanceId): array
    {
        return $this->instanceAction($instanceId, 'shelve');
    }

    public function unshelveInstance(string $instanceId): array
    {
        return $this->instanceAction($instanceId, 'unshelve');
    }

    public function rebuildInstance(string $instanceId): array
    {
        return $this->instanceAction($instanceId, 'rebuild');
    }

    /**
     * Change instance admin password
     */
    public function changePassword(string $instanceId, string $newPassword): array
    {
        try {
            $response = $this->apiRequest(
                '/instances/' . $instanceId . '/password',
                'POST',
                ['password' => $newPassword]
            );

            if (in_array($response['httpCode'], [200, 202, 204])) {
                return ['success' => true];
            }

            return $response;
        } catch (Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    // =========================================================================
    // Console
    // =========================================================================

    /**
     * Get VNC console URL for an instance
     */
    public function getConsoleUrl(string $instanceId): array
    {
        try {
            $response = $this->apiRequest('/instances/' . $instanceId . '/console', 'GET');

            if (!$response['success']) {
                return $response;
            }

            $result = json_decode($response['body'], true);
            $url = $result['url'] ?? $result['console_url'] ?? '';

            if (empty($url)) {
                return ['success' => false, 'error' => 'No console URL returned'];
            }

            return ['success' => true, 'url' => $url];
        } catch (Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Get console output (boot log)
     */
    public function getConsoleOutput(string $instanceId, int $length = 100): array
    {
        try {
            $url = '/instances/' . $instanceId . '/console/output';
            if ($length > 0) {
                $url .= '?length=' . $length;
            }

            $response = $this->apiRequest($url, 'GET');

            if (!$response['success']) {
                return $response;
            }

            $result = json_decode($response['body'], true);
            $output = $result['output'] ?? '';

            return [
                'success' => true,
                'output' => $output,
                'length' => substr_count($output, "\n") + 1,
            ];
        } catch (Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    // =========================================================================
    // Console Sharing (CMP API native)
    // =========================================================================

    /**
     * Create a shareable console link
     */
    public function createConsoleShare(string $instanceId, array $params = []): array
    {
        try {
            $response = $this->apiRequest(
                '/instances/' . $instanceId . '/console/share',
                'POST',
                $params
            );

            if (!$response['success']) {
                return $response;
            }

            $result = json_decode($response['body'], true);
            return ['success' => true, 'share' => $result];
        } catch (Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * List active console share links
     */
    public function listConsoleShares(string $instanceId): array
    {
        try {
            $response = $this->apiRequest(
                '/instances/' . $instanceId . '/console/shares',
                'GET'
            );

            if (!$response['success']) {
                return $response;
            }

            $result = json_decode($response['body'], true);
            return ['success' => true, 'shares' => $result];
        } catch (Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Revoke a console share link
     */
    public function revokeConsoleShare(string $instanceId, string $shareId): array
    {
        try {
            $response = $this->apiRequest(
                '/instances/' . $instanceId . '/console/shares/' . $shareId,
                'DELETE'
            );

            if (in_array($response['httpCode'], [200, 202, 204])) {
                return ['success' => true];
            }

            return $response;
        } catch (Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    // =========================================================================
    // Instance History
    // =========================================================================

    /**
     * Get instance action history
     */
    public function getInstanceHistory(string $instanceId): array
    {
        try {
            $response = $this->apiRequest('/instances/' . $instanceId . '/history', 'GET');

            if (!$response['success']) {
                return $response;
            }

            $result = json_decode($response['body'], true);
            return ['success' => true, 'history' => $result];
        } catch (Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    // =========================================================================
    // Resources
    // =========================================================================

    /**
     * List projects available to the authenticated user/org.
     *
     * The CMP API may expose this under /projects. We handle missing
     * endpoints gracefully so callers can fall back to manual config.
     */
    public function listProjects(): array
    {
        try {
            $response = $this->apiRequest('/projects', 'GET');

            if (!$response['success']) {
                // Endpoint not available - return empty set so admins can
                // still manually enter project IDs without blocking.
                if (($response['httpCode'] ?? 0) === 404) {
                    return ['success' => true, 'projects' => []];
                }
                return $response;
            }

            $result = json_decode($response['body'], true);
            // Accept either { items: [...] } or a bare array
            $projects = $result['items'] ?? $result['projects'] ?? $result ?? [];
            return ['success' => true, 'projects' => $projects];
        } catch (Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * List available regions
     */
    public function listRegions(string $service = ''): array
    {
        try {
            $url = '/regions';
            if (!empty($service)) {
                $url .= '?service=' . urlencode($service);
            }

            $response = $this->apiRequest($url, 'GET');

            if (!$response['success']) {
                return $response;
            }

            $result = json_decode($response['body'], true);
            return ['success' => true, 'regions' => $result];
        } catch (Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * List available flavors
     */
    public function listFlavors(string $region = '', bool $includeGpu = false): array
    {
        try {
            $params = [];
            if (!empty($region)) {
                $params['region'] = $region;
            }
            if ($includeGpu) {
                $params['include_gpu'] = 'true';
            }

            $query = http_build_query($params);
            $url = '/flavors' . ($query ? '?' . $query : '');

            $response = $this->apiRequest($url, 'GET');

            if (!$response['success']) {
                return $response;
            }

            $result = json_decode($response['body'], true);
            return ['success' => true, 'flavors' => $result];
        } catch (Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * List available images
     */
    public function listImages(string $region = '', string $osDistro = ''): array
    {
        try {
            $params = [];
            if (!empty($region)) {
                $params['region'] = $region;
            }
            if (!empty($osDistro)) {
                $params['os_distro'] = $osDistro;
            }

            $query = http_build_query($params);
            $url = '/images' . ($query ? '?' . $query : '');

            $response = $this->apiRequest($url, 'GET');

            if (!$response['success']) {
                return $response;
            }

            $result = json_decode($response['body'], true);
            return ['success' => true, 'images' => $result];
        } catch (Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * List security groups
     */
    public function listSecurityGroups(string $projectId, string $region = ''): array
    {
        try {
            $params = ['project_id' => $projectId];
            if (!empty($region)) {
                $params['region'] = $region;
            }

            $query = http_build_query($params);
            $response = $this->apiRequest('/security-groups?' . $query, 'GET');

            if (!$response['success']) {
                return $response;
            }

            $result = json_decode($response['body'], true);
            return ['success' => true, 'security_groups' => $result];
        } catch (Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    // =========================================================================
    // Volumes
    // =========================================================================

    /**
     * List volume types with pricing
     */
    public function listVolumeTypes(string $region = ''): array
    {
        try {
            $url = '/volumes/types';
            if (!empty($region)) {
                $url .= '?region=' . urlencode($region);
            }

            $response = $this->apiRequest($url, 'GET');

            if (!$response['success']) {
                if ($response['httpCode'] == 404) {
                    return ['success' => true, 'volume_types' => []];
                }
                return $response;
            }

            $result = json_decode($response['body'], true);
            return ['success' => true, 'volume_types' => $result];
        } catch (Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Get volume details
     */
    public function getVolume(string $volumeId): array
    {
        try {
            $response = $this->apiRequest('/volumes/' . $volumeId, 'GET');

            if (!$response['success']) {
                return $response;
            }

            $result = json_decode($response['body'], true);
            return ['success' => true, 'volume' => $result];
        } catch (Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * List volumes for a project
     */
    public function listVolumes(string $projectId, string $region = '', string $vmId = ''): array
    {
        try {
            $params = ['project_id' => $projectId];
            if (!empty($region)) {
                $params['region'] = $region;
            }
            if (!empty($vmId)) {
                $params['vm_id'] = $vmId;
            }

            $query = http_build_query($params);
            $response = $this->apiRequest('/volumes?' . $query, 'GET');

            if (!$response['success']) {
                return $response;
            }

            $result = json_decode($response['body'], true);
            return ['success' => true, 'volumes' => $result];
        } catch (Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Extend volume size
     */
    public function extendVolume(string $volumeId, int $newSizeGB): array
    {
        try {
            $response = $this->apiRequest(
                '/volumes/' . $volumeId . '/extend',
                'POST',
                ['new_size_gb' => $newSizeGB]
            );

            if (in_array($response['httpCode'], [200, 202, 204])) {
                return ['success' => true];
            }

            return $response;
        } catch (Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    // =========================================================================
    // Billing
    // =========================================================================

    /**
     * Estimate cost for a VM configuration
     */
    public function estimateCost(array $params): array
    {
        try {
            $response = $this->apiRequest('/billing/estimate', 'POST', $params);

            if (!$response['success']) {
                return $response;
            }

            $result = json_decode($response['body'], true);
            return ['success' => true, 'estimate' => $result];
        } catch (Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    // =========================================================================
    // SSH Keys
    // =========================================================================

    /**
     * List SSH keys
     */
    public function listSSHKeys(): array
    {
        try {
            $response = $this->apiRequest('/ssh-keys', 'GET');

            if (!$response['success']) {
                return $response;
            }

            $result = json_decode($response['body'], true);
            return ['success' => true, 'ssh_keys' => $result];
        } catch (Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    // =========================================================================
    // HTTP Layer
    // =========================================================================

    /**
     * Make an authenticated API request
     */
    private function apiRequest(string $endpoint, string $method, array $data = null, array $extraHeaders = []): array
    {
        $url = $this->serverUrl . $endpoint;
        $headers = array_merge(
            ['Authorization: Bearer ' . $this->apiKey],
            $extraHeaders
        );

        return $this->curlRequest($url, $method, $data, $headers);
    }

    /**
     * Make a cURL request
     */
    private function curlRequest(string $url, string $method, array $data = null, array $headers = []): array
    {
        $ch = curl_init();

        $headers[] = 'Content-Type: application/json';
        $headers[] = 'Accept: application/json';

        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_SSL_VERIFYPEER => $this->sslVerify,
            CURLOPT_SSL_VERIFYHOST => $this->sslVerify ? 2 : 0,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 5,
        ]);

        if ($data !== null && in_array($method, ['POST', 'PUT', 'PATCH'])) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        }

        $body = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        $errno = curl_errno($ch);
        curl_close($ch);

        if ($error) {
            $errorMsg = "cURL error ($errno): $error";
            if ($errno === 60 || $errno === 77) {
                $errorMsg .= ' (SSL certificate problem - try disabling Secure Connection)';
            }
            return ['success' => false, 'error' => $errorMsg, 'httpCode' => $httpCode];
        }

        $result = [
            'success' => $httpCode >= 200 && $httpCode < 300,
            'httpCode' => $httpCode,
            'body' => $body,
        ];

        if (!$result['success']) {
            $errorData = json_decode($body, true);
            $result['error'] = $errorData['detail'] ?? $errorData['message'] ?? $errorData['error'] ?? "HTTP Error: $httpCode";

            // Handle FastAPI validation errors
            if (is_array($errorData['detail'] ?? null)) {
                $messages = [];
                foreach ($errorData['detail'] as $err) {
                    $field = implode('.', $err['loc'] ?? []);
                    $messages[] = $field . ': ' . ($err['msg'] ?? 'invalid');
                }
                $result['error'] = implode('; ', $messages);
            }
        }

        return $result;
    }

    /**
     * Wait for instance to reach a specific status
     */
    public function waitForInstanceStatus(string $instanceId, string $targetStatus, int $timeout = 300): array
    {
        $startTime = time();

        while (time() - $startTime < $timeout) {
            $result = $this->getInstance($instanceId, true);

            if (!$result['success']) {
                if ($targetStatus === 'DELETED' && ($result['httpCode'] ?? 0) === 404) {
                    return ['success' => true, 'status' => 'DELETED'];
                }
                return $result;
            }

            $status = strtoupper($result['instance']['status'] ?? '');

            if ($status === $targetStatus) {
                return ['success' => true, 'instance' => $result['instance']];
            }

            if ($status === 'ERROR') {
                return ['success' => false, 'error' => 'Instance entered ERROR state'];
            }

            sleep(5);
        }

        return ['success' => false, 'error' => "Timeout waiting for status: $targetStatus"];
    }
}

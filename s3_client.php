<?php
/**
 * Storage Client - Pure PHP AWS Signature Version 4 Client
 * 
 * Zero dependencies. Operates on PHP 7.4 through PHP 8.3+.
 * Fully compatible with standard cPanel shared hosting environments.
 */

class StorageClient {
    private string $accountId;
    private string $accessKey;
    private string $secretKey;
    private string $bucket;
    private string $endpoint;
    private string $region;

    public function __construct(
        string $accountId,
        string $accessKey,
        string $secretKey,
        string $bucket,
        string $endpoint = '',
        string $region = 'auto'
    ) {
        $this->accountId = trim($accountId);
        $this->accessKey = trim($accessKey);
        $this->secretKey = trim($secretKey);
        $this->bucket    = trim($bucket);
        $this->region    = $region ?: 'auto';
        $this->endpoint  = $endpoint ?: ($this->accountId . '.r2.cloudflarestorage.com');
    }

    /**
     * Check if credentials have been configured
     */
    public function isConfigured(): bool {
        return !empty($this->accountId) &&
               $this->accountId !== 'your_account_id_here' &&
               !empty($this->accessKey) &&
               $this->accessKey !== 'your_access_key_id_here' &&
               !empty($this->secretKey) &&
               $this->secretKey !== 'your_secret_access_key_here' &&
               !empty($this->bucket) &&
               $this->bucket !== 'your_bucket_name_here';
    }

    /**
     * Send signed AWS v4 HTTP request
     */
    public function request(
        string $method,
        string $key = '',
        array $queryParams = [],
        array $headers = [],
        $body = ''
    ): array {
        $method = strtoupper($method);
        $host   = $this->endpoint;

        // Construct Path: /{bucket}/{key}
        $path = '/' . $this->bucket;
        if ($key !== '') {
            $path .= '/' . ltrim($key, '/');
        }

        // Canonical URI
        $canonicalUri = '/' . implode('/', array_map('rawurlencode', explode('/', ltrim($path, '/'))));

        // Time calculations
        $amzDate  = gmdate('Ymd\THis\Z');
        $dateStamp = gmdate('Ymd');

        // Payload hash
        if (is_resource($body)) {
            $payloadHash = hash('sha256', stream_get_contents($body));
            rewind($body);
        } else {
            $payloadHash = hash('sha256', (string)$body);
        }

        // Prepare Base Headers
        $headers['Host']                 = $host;
        $headers['x-amz-date']            = $amzDate;
        $headers['x-amz-content-sha256']  = $payloadHash;

        // Canonical Query String
        ksort($queryParams);
        $canonicalQueryParts = [];
        foreach ($queryParams as $k => $v) {
            if ($v === null || $v === '') {
                $canonicalQueryParts[] = rawurlencode($k) . '=';
            } else {
                $canonicalQueryParts[] = rawurlencode($k) . '=' . rawurlencode((string)$v);
            }
        }
        $canonicalQueryString = implode('&', $canonicalQueryParts);

        // Canonical Headers & Signed Headers
        $canonicalHeaders = '';
        $signedHeaderKeys = [];
        $sortedHeaders = [];
        foreach ($headers as $k => $v) {
            $lowerKey = strtolower(trim($k));
            $sortedHeaders[$lowerKey] = trim((string)$v);
        }
        ksort($sortedHeaders);

        foreach ($sortedHeaders as $k => $v) {
            $canonicalHeaders .= $k . ':' . preg_replace('/\s+/', ' ', $v) . "\n";
            $signedHeaderKeys[] = $k;
        }
        $signedHeaders = implode(';', $signedHeaderKeys);

        // Canonical Request
        $canonicalRequest = $method . "\n"
                          . $canonicalUri . "\n"
                          . $canonicalQueryString . "\n"
                          . $canonicalHeaders . "\n"
                          . $signedHeaders . "\n"
                          . $payloadHash;

        // String to sign
        $algorithm = 'AWS4-HMAC-SHA256';
        $credentialScope = $dateStamp . '/' . $this->region . '/s3/aws4_request';
        $stringToSign = $algorithm . "\n"
                      . $amzDate . "\n"
                      . $credentialScope . "\n"
                      . hash('sha256', $canonicalRequest);

        // Signing key calculation
        $kDate    = hash_hmac('sha256', $dateStamp, 'AWS4' . $this->secretKey, true);
        $kRegion  = hash_hmac('sha256', $this->region, $kDate, true);
        $kService = hash_hmac('sha256', 's3', $kRegion, true);
        $kSigning = hash_hmac('sha256', 'aws4_request', $kService, true);
        $signature = hash_hmac('sha256', $stringToSign, $kSigning);

        // Authorization Header
        $authHeader = $algorithm . ' '
                    . 'Credential=' . $this->accessKey . '/' . $credentialScope . ', '
                    . 'SignedHeaders=' . $signedHeaders . ', '
                    . 'Signature=' . $signature;

        $headers['Authorization'] = $authHeader;

        // Execute cURL
        $url = 'https://' . $host . $canonicalUri;
        if (!empty($canonicalQueryString)) {
            $url .= '?' . $canonicalQueryString;
        }

        $curlHeaders = [];
        foreach ($headers as $k => $v) {
            $curlHeaders[] = $k . ': ' . $v;
        }

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $curlHeaders);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HEADER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 15);
        curl_setopt($ch, CURLOPT_TIMEOUT, 300);

        if ($method === 'PUT' || $method === 'POST') {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        } elseif ($method === 'HEAD') {
            curl_setopt($ch, CURLOPT_NOBODY, true);
        }

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($response === false) {
            return [
                'success' => false,
                'status'  => 0,
                'error'   => 'Network request error: ' . $curlError,
                'headers' => [],
                'body'    => ''
            ];
        }

        $rawHeaders = substr($response, 0, $headerSize);
        $responseBody = substr($response, $headerSize);
        $parsedHeaders = $this->parseHeaders($rawHeaders);

        return [
            'success' => ($httpCode >= 200 && $httpCode < 300),
            'status'  => $httpCode,
            'headers' => $parsedHeaders,
            'body'    => $responseBody,
            'error'   => ($httpCode >= 400) ? $this->extractErrorMessage($responseBody, $httpCode) : null
        ];
    }

    /**
     * Parse raw headers string into key-value array
     */
    private function parseHeaders(string $rawHeaders): array {
        $headers = [];
        $lines = explode("\r\n", $rawHeaders);
        foreach ($lines as $line) {
            if (strpos($line, ':') !== false) {
                list($key, $val) = explode(':', $line, 2);
                $headers[strtolower(trim($key))] = trim($val);
            }
        }
        return $headers;
    }

    /**
     * Extract human-readable error from XML error responses
     */
    private function extractErrorMessage(string $body, int $httpCode): string {
        if (!empty($body)) {
            $prev = libxml_use_internal_errors(true);
            $xml = simplexml_load_string($body);
            libxml_use_internal_errors($prev);
            if ($xml !== false && isset($xml->Message)) {
                return (string)$xml->Message;
            }
        }
        return 'Request returned HTTP status ' . $httpCode;
    }

    /**
     * List Objects inside a bucket or folder prefix
     */
    public function listObjects(
        string $prefix = '',
        string $delimiter = '/',
        string $continuationToken = '',
        int $maxKeys = 1000
    ): array {
        $queryParams = [
            'list-type' => '2',
            'max-keys'  => (string)$maxKeys
        ];
        if ($prefix !== '') {
            $queryParams['prefix'] = $prefix;
        }
        if ($delimiter !== '') {
            $queryParams['delimiter'] = $delimiter;
        }
        if ($continuationToken !== '') {
            $queryParams['continuation-token'] = $continuationToken;
        }

        $res = $this->request('GET', '', $queryParams);
        if (!$res['success']) {
            return [
                'success' => false,
                'error'   => $res['error'] ?: 'Failed to list directory contents'
            ];
        }

        $xml = simplexml_load_string($res['body']);
        if ($xml === false) {
            return [
                'success' => false,
                'error'   => 'Failed to parse directory response'
            ];
        }

        $folders = [];
        if (isset($xml->CommonPrefixes)) {
            foreach ($xml->CommonPrefixes as $cp) {
                $dirPrefix = (string)$cp->Prefix;
                // Exclude current prefix
                if ($dirPrefix === $prefix) continue;

                $folderName = basename(rtrim($dirPrefix, '/'));
                $folders[] = [
                    'name'   => $folderName,
                    'prefix' => $dirPrefix,
                    'type'   => 'folder'
                ];
            }
        }

        $files = [];
        if (isset($xml->Contents)) {
            foreach ($xml->Contents as $item) {
                $key = (string)$item->Key;

                // Skip the folder marker itself
                if ($key === $prefix || substr($key, -1) === '/') {
                    continue;
                }

                $fileName = basename($key);
                $size = (int)$item->Size;
                $lastModified = (string)$item->LastModified;
                $etag = trim((string)$item->ETag, '"');

                $files[] = [
                    'key'          => $key,
                    'name'         => $fileName,
                    'size'         => $size,
                    'lastModified' => $lastModified,
                    'etag'         => $etag,
                    'type'         => 'file',
                    'extension'    => strtolower(pathinfo($fileName, PATHINFO_EXTENSION))
                ];
            }
        }

        $isTruncated = isset($xml->IsTruncated) && ((string)$xml->IsTruncated === 'true');
        $nextToken   = isset($xml->NextContinuationToken) ? (string)$xml->NextContinuationToken : null;

        return [
            'success'     => true,
            'prefix'      => $prefix,
            'folders'     => $folders,
            'files'       => $files,
            'isTruncated' => $isTruncated,
            'nextToken'   => $nextToken
        ];
    }

    /**
     * Put / Upload Object
     */
    public function putObject(
        string $key,
        $body,
        string $contentType = 'application/octet-stream',
        array $customHeaders = []
    ): array {
        $headers = array_merge([
            'Content-Type'  => $contentType,
            'Cache-Control' => DEFAULT_CACHE_CONTROL
        ], $customHeaders);

        return $this->request('PUT', $key, [], $headers, $body);
    }

    /**
     * Create Virtual Folder (stores a directory placeholder object)
     */
    public function createFolder(string $folderPath): array {
        $folderPath = rtrim($folderPath, '/') . '/';
        return $this->putObject($folderPath, '', 'application/x-directory', [
            'Cache-Control' => 'no-cache'
        ]);
    }

    /**
     * Get Object Metadata (HEAD request)
     */
    public function headObject(string $key): array {
        return $this->request('HEAD', $key);
    }

    /**
     * Get Object Content
     */
    public function getObject(string $key): array {
        return $this->request('GET', $key);
    }

    /**
     * Delete Single Object
     */
    public function deleteObject(string $key): array {
        return $this->request('DELETE', $key);
    }

    /**
     * Batch Delete Objects (up to 1000 objects per call)
     */
    public function deleteObjects(array $keys): array {
        if (empty($keys)) {
            return ['success' => true, 'deleted' => []];
        }

        $xml = '<?xml version="1.0" encoding="UTF-8"?><Delete><Quiet>false</Quiet>';
        foreach ($keys as $k) {
            $xml .= '<Object><Key>' . htmlspecialchars($k, ENT_XML1, 'UTF-8') . '</Key></Object>';
        }
        $xml .= '</Delete>';

        $headers = [
            'Content-Type' => 'application/xml',
            'Content-MD5'  => base64_encode(md5($xml, true))
        ];

        return $this->request('POST', '', ['delete' => null], $headers, $xml);
    }

    /**
     * Recursively Delete an entire Folder and all nested keys
     */
    public function deleteFolder(string $prefix): array {
        $prefix = rtrim($prefix, '/') . '/';
        $continuationToken = '';
        $totalDeleted = 0;

        do {
            $listParams = [
                'list-type' => '2',
                'prefix'    => $prefix,
                'max-keys'  => '1000'
            ];
            if ($continuationToken !== '') {
                $listParams['continuation-token'] = $continuationToken;
            }

            $listRes = $this->request('GET', '', $listParams);
            if (!$listRes['success']) {
                return ['success' => false, 'error' => 'Failed to scan folder contents for deletion'];
            }

            $xml = simplexml_load_string($listRes['body']);
            if ($xml === false) {
                break;
            }

            $keysToDelete = [];
            if (isset($xml->Contents)) {
                foreach ($xml->Contents as $item) {
                    $keysToDelete[] = (string)$item->Key;
                }
            }

            // Also ensure the folder marker itself is deleted
            $keysToDelete[] = $prefix;
            $keysToDelete[] = rtrim($prefix, '/');
            $keysToDelete = array_unique(array_filter($keysToDelete));

            if (!empty($keysToDelete)) {
                $delRes = $this->deleteObjects($keysToDelete);
                if (!$delRes['success']) {
                    return $delRes;
                }
                $totalDeleted += count($keysToDelete);
            }

            $isTruncated = isset($xml->IsTruncated) && ((string)$xml->IsTruncated === 'true');
            $continuationToken = isset($xml->NextContinuationToken) ? (string)$xml->NextContinuationToken : '';

        } while ($isTruncated && !empty($continuationToken));

        return [
            'success' => true,
            'deletedCount' => $totalDeleted
        ];
    }

    /**
     * Copy an Object
     */
    public function copyObject(
        string $sourceKey,
        string $destKey,
        array $customHeaders = [],
        bool $replaceMetadata = false
    ): array {
        $headers = array_merge([
            'x-amz-copy-source' => '/' . $this->bucket . '/' . implode('/', array_map('rawurlencode', explode('/', ltrim($sourceKey, '/'))))
        ], $customHeaders);

        if ($replaceMetadata) {
            $headers['x-amz-metadata-directive'] = 'REPLACE';
        }

        return $this->request('PUT', $destKey, [], $headers);
    }

    /**
     * Rename or Move an Object
     */
    public function renameObject(string $sourceKey, string $destKey): array {
        $copyRes = $this->copyObject($sourceKey, $destKey);
        if (!$copyRes['success']) {
            return $copyRes;
        }
        return $this->deleteObject($sourceKey);
    }

    /**
     * Update Object Metadata / Parameters (Content-Type, Cache-Control, etc.)
     */
    public function updateParameters(
        string $key,
        string $contentType,
        string $cacheControl,
        string $contentDisposition = ''
    ): array {
        $headers = [
            'Content-Type'  => $contentType,
            'Cache-Control' => $cacheControl
        ];
        if (!empty($contentDisposition)) {
            $headers['Content-Disposition'] = $contentDisposition;
        }

        return $this->copyObject($key, $key, $headers, true);
    }

    /**
     * Generate an AWS v4 Presigned URL
     */
    public function getPresignedUrl(string $key, int $expiresInSeconds = 3600, string $method = 'GET'): string {
        $method = strtoupper($method);
        $host   = $this->endpoint;
        $path   = '/' . $this->bucket . '/' . ltrim($key, '/');
        $canonicalUri = '/' . implode('/', array_map('rawurlencode', explode('/', ltrim($path, '/'))));

        $amzDate   = gmdate('Ymd\THis\Z');
        $dateStamp = gmdate('Ymd');
        $scope     = $dateStamp . '/' . $this->region . '/s3/aws4_request';

        $queryParams = [
            'X-Amz-Algorithm'     => 'AWS4-HMAC-SHA256',
            'X-Amz-Credential'    => $this->accessKey . '/' . $scope,
            'X-Amz-Date'          => $amzDate,
            'X-Amz-Expires'       => (string)$expiresInSeconds,
            'X-Amz-SignedHeaders' => 'host'
        ];

        ksort($queryParams);
        $canonicalQueryParts = [];
        foreach ($queryParams as $k => $v) {
            $canonicalQueryParts[] = rawurlencode($k) . '=' . rawurlencode($v);
        }
        $canonicalQueryString = implode('&', $canonicalQueryParts);

        $canonicalHeaders = 'host:' . $host . "\n";
        $signedHeaders    = 'host';
        $payloadHash      = 'UNSIGNED-PAYLOAD';

        $canonicalRequest = $method . "\n"
                          . $canonicalUri . "\n"
                          . $canonicalQueryString . "\n"
                          . $canonicalHeaders . "\n"
                          . $signedHeaders . "\n"
                          . $payloadHash;

        $algorithm = 'AWS4-HMAC-SHA256';
        $stringToSign = $algorithm . "\n"
                      . $amzDate . "\n"
                      . $scope . "\n"
                      . hash('sha256', $canonicalRequest);

        $kDate    = hash_hmac('sha256', $dateStamp, 'AWS4' . $this->secretKey, true);
        $kRegion  = hash_hmac('sha256', $this->region, $kDate, true);
        $kService = hash_hmac('sha256', 's3', $kRegion, true);
        $kSigning = hash_hmac('sha256', 'aws4_request', $kService, true);
        $signature = hash_hmac('sha256', $stringToSign, $kSigning);

        return 'https://' . $host . $canonicalUri . '?' . $canonicalQueryString . '&X-Amz-Signature=' . $signature;
    }
}

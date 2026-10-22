<?php
/**
 * RESTful & Real-Time API Controller
 * 
 * Completely white-labeled. Zero database required.
 * Guarantees zero-cache delivery and real-time event streaming.
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/s3_client.php';

// Guarantee Zero-Cache on all API responses
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0, post-check=0, pre-check=0');
header('Pragma: no-cache');
header('Expires: Thu, 01 Jan 1970 00:00:00 GMT');

// CORS Headers for flexible hosting
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// Check authentication
if (!isLoggedIn()) {
    $action = $_GET['action'] ?? '';
    if (in_array($action, ['preview', 'download'])) {
        header('Location: login.php');
        exit;
    }
    http_response_code(401);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => 'Unauthorized. Please sign in.', 'needsAuth' => true]);
    exit;
}

// Release session lock immediately so parallel AJAX requests (list, upload, SSE stream) never block
if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}

// Event notification directory for real-time synchronization
$eventsFile = sys_get_temp_dir() . '/storage_vault_events_' . md5(STORAGE_ACCOUNT_ID . STORAGE_BUCKET) . '.json';

function broadcastEvent(string $eventType, array $payload = []): void {
    global $eventsFile;
    $event = [
        'id'        => uniqid('evt_', true),
        'type'      => $eventType,
        'payload'   => $payload,
        'timestamp' => microtime(true)
    ];
    file_put_contents($eventsFile, json_encode($event), LOCK_EX);
}

function getLatestEvent(): ?array {
    global $eventsFile;
    if (file_exists($eventsFile)) {
        $data = @file_get_contents($eventsFile);
        if ($data) {
            return json_decode($data, true);
        }
    }
    return null;
}

// Initialize Client
$client = new StorageClient(
    STORAGE_ACCOUNT_ID,
    STORAGE_ACCESS_KEY,
    STORAGE_SECRET_KEY,
    STORAGE_BUCKET,
    STORAGE_ENDPOINT,
    STORAGE_REGION
);

$action = $_GET['action'] ?? 'list';

// Check Configuration
if (!$client->isConfigured() && !in_array($action, ['preview', 'stream'])) {
    header('Content-Type: application/json');
    echo json_encode([
        'success'    => false,
        'configured' => false,
        'error'      => 'Storage credentials have not been configured yet. Please configure your .env file with your credentials.'
    ]);
    exit;
}

// Helper to determine Content-Type
function detectMimeType(string $filename): string {
    $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
    $map = [
        'html' => 'text/html; charset=utf-8',
        'htm'  => 'text/html; charset=utf-8',
        'css'  => 'text/css; charset=utf-8',
        'js'   => 'application/javascript; charset=utf-8',
        'mjs'  => 'application/javascript; charset=utf-8',
        'json' => 'application/json; charset=utf-8',
        'xml'  => 'application/xml; charset=utf-8',
        'svg'  => 'image/svg+xml',
        'txt'  => 'text/plain; charset=utf-8',
        'md'   => 'text/markdown; charset=utf-8',
        'csv'  => 'text/csv; charset=utf-8',
        'pdf'  => 'application/pdf',
        'png'  => 'image/png',
        'jpg'  => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'gif'  => 'image/gif',
        'webp' => 'image/webp',
        'ico'  => 'image/x-icon',
        'avif' => 'image/avif',
        'mp4'  => 'video/mp4',
        'webm' => 'video/webm',
        'ogg'  => 'video/ogg',
        'mp3'  => 'audio/mpeg',
        'wav'  => 'audio/wav',
        'flac' => 'audio/flac',
        'zip'  => 'application/zip',
        'tar'  => 'application/x-tar',
        'gz'   => 'application/gzip',
        'woff' => 'font/woff',
        'woff2'=> 'font/woff2',
        'ttf'  => 'font/ttf',
        'otf'  => 'font/otf'
    ];
    return $map[$ext] ?? 'application/octet-stream';
}

// Build Public or Presigned URL for an object
function buildObjectUrl(StorageClient $client, string $key): string {
    if (!empty(STORAGE_PUBLIC_DOMAIN)) {
        return rtrim(STORAGE_PUBLIC_DOMAIN, '/') . '/' . implode('/', array_map('rawurlencode', explode('/', ltrim($key, '/'))));
    }
    return $client->getPresignedUrl($key, 86400); // 24-hour presigned URL
}

// Router
try {
    switch ($action) {
        // ----------------------------------------------------
        // 1. LIST DIRECTORY CONTENTS
        // ----------------------------------------------------
        case 'list':
            header('Content-Type: application/json');
            $prefix = trim($_GET['prefix'] ?? '');
            if (!empty($prefix)) {
                $prefix = rtrim($prefix, '/') . '/';
            }
            $continuationToken = trim($_GET['token'] ?? '');

            $res = $client->listObjects($prefix, '/', $continuationToken);

            if (!$res['success']) {
                echo json_encode($res);
                exit;
            }

            // Build Breadcrumbs
            $breadcrumbs = [['name' => 'Root', 'prefix' => '']];
            if (!empty($prefix)) {
                $parts = explode('/', trim($prefix, '/'));
                $acc = '';
                foreach ($parts as $p) {
                    $acc .= $p . '/';
                    $breadcrumbs[] = ['name' => $p, 'prefix' => $acc];
                }
            }

            // Append URLs & Details to files
            foreach ($res['files'] as &$f) {
                $f['url'] = buildObjectUrl($client, $f['key']);
                $f['isEditable'] = in_array($f['extension'], EDITABLE_EXTENSIONS);
                $f['isImage']    = in_array($f['extension'], IMAGE_EXTENSIONS);
                $f['isVideo']    = in_array($f['extension'], VIDEO_EXTENSIONS);
                $f['isAudio']    = in_array($f['extension'], AUDIO_EXTENSIONS);
            }
            unset($f);

            echo json_encode([
                'success'      => true,
                'configured'   => true,
                'prefix'       => $prefix,
                'breadcrumbs'  => $breadcrumbs,
                'folders'      => $res['folders'],
                'files'        => $res['files'],
                'totalFolders' => count($res['folders']),
                'totalFiles'   => count($res['files']),
                'isTruncated'  => $res['isTruncated'],
                'nextToken'    => $res['nextToken']
            ]);
            break;

        // ----------------------------------------------------
        // 2. UPLOAD FILE(S) & FOLDER STRUCTURES
        // ----------------------------------------------------
        case 'upload':
            header('Content-Type: application/json');

            if (empty($_FILES['file'])) {
                $contentLength = (int)($_SERVER['CONTENT_LENGTH'] ?? 0);
                $postMax = ini_get('post_max_size');
                $uploadMax = ini_get('upload_max_filesize');
                if ($contentLength > 0) {
                    http_response_code(413);
                    echo json_encode([
                        'success' => false,
                        'error'   => "Uploaded payload ({$contentLength} bytes) exceeds PHP limits (upload_max_filesize={$uploadMax}, post_max_size={$postMax})."
                    ]);
                    exit;
                }
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'No file received']);
                exit;
            }

            $currentPrefix = trim($_POST['prefix'] ?? '');
            if (!empty($currentPrefix)) {
                $currentPrefix = rtrim($currentPrefix, '/') . '/';
            }

            // Relative path (used for recursive folder uploads)
            $relativePath = trim($_POST['relativePath'] ?? '');
            $customCacheControl = trim($_POST['cacheControl'] ?? DEFAULT_CACHE_CONTROL);

            $file = $_FILES['file'];
            if ($file['error'] !== UPLOAD_ERR_OK) {
                $uploadErrors = [
                    UPLOAD_ERR_INI_SIZE   => 'The uploaded file exceeds upload_max_filesize in php.ini',
                    UPLOAD_ERR_FORM_SIZE  => 'The uploaded file exceeds MAX_FILE_SIZE directive',
                    UPLOAD_ERR_PARTIAL    => 'The file was only partially uploaded',
                    UPLOAD_ERR_NO_FILE    => 'No file was uploaded',
                    UPLOAD_ERR_NO_TMP_DIR => 'Missing a temporary folder on the server',
                    UPLOAD_ERR_CANT_WRITE => 'Failed to write file to disk on server',
                    UPLOAD_ERR_EXTENSION  => 'A PHP extension stopped the file upload'
                ];
                $msg = $uploadErrors[$file['error']] ?? ('Upload error code: ' . $file['error']);
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => $msg]);
                exit;
            }

            // Construct Key
            if (!empty($relativePath)) {
                $destinationKey = $currentPrefix . ltrim($relativePath, '/');
            } else {
                $destinationKey = $currentPrefix . ltrim($file['name'], '/');
            }

            $fileData = file_get_contents($file['tmp_name']);
            $mimeType = detectMimeType($destinationKey);

            $uploadRes = $client->putObject($destinationKey, $fileData, $mimeType, [
                'Cache-Control' => $customCacheControl
            ]);

            if ($uploadRes['success']) {
                broadcastEvent('file_uploaded', [
                    'key'    => $destinationKey,
                    'prefix' => $currentPrefix
                ]);

                $ext = strtolower(pathinfo($destinationKey, PATHINFO_EXTENSION));
                $fileItem = [
                    'key'          => $destinationKey,
                    'name'         => basename($destinationKey),
                    'size'         => strlen($fileData),
                    'lastModified' => gmdate('Y-m-d\TH:i:s\Z'),
                    'etag'         => md5($fileData),
                    'type'         => 'file',
                    'extension'    => $ext,
                    'url'          => buildObjectUrl($client, $destinationKey),
                    'isEditable'   => in_array($ext, EDITABLE_EXTENSIONS),
                    'isImage'      => in_array($ext, IMAGE_EXTENSIONS),
                    'isVideo'      => in_array($ext, VIDEO_EXTENSIONS),
                    'isAudio'      => in_array($ext, AUDIO_EXTENSIONS)
                ];

                echo json_encode([
                    'success'  => true,
                    'key'      => $destinationKey,
                    'size'     => strlen($fileData),
                    'mimeType' => $mimeType,
                    'url'      => buildObjectUrl($client, $destinationKey),
                    'file'     => $fileItem
                ]);
            } else {
                http_response_code(500);
                echo json_encode([
                    'success' => false,
                    'error'   => $uploadRes['error'] ?: 'Failed to upload object'
                ]);
            }
            break;

        // ----------------------------------------------------
        // 3. CREATE VIRTUAL FOLDER
        // ----------------------------------------------------
        case 'create_folder':
            header('Content-Type: application/json');
            $input = json_decode(file_get_contents('php://input'), true);
            $folderName = trim($input['name'] ?? '');
            $prefix = trim($input['prefix'] ?? '');

            if (empty($folderName)) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'Folder name is required']);
                exit;
            }

            // Clean folder name
            $folderName = preg_replace('/[\\\\:*?"<>|]/', '', $folderName);
            $fullPath = (!empty($prefix) ? rtrim($prefix, '/') . '/' : '') . trim($folderName, '/') . '/';

            $res = $client->createFolder($fullPath);

            if ($res['success']) {
                broadcastEvent('folder_created', ['path' => $fullPath]);
                echo json_encode(['success' => true, 'path' => $fullPath]);
            } else {
                http_response_code(500);
                echo json_encode(['success' => false, 'error' => $res['error'] ?: 'Failed to create folder']);
            }
            break;

        // ----------------------------------------------------
        // 4. DELETE SINGLE OBJECT
        // ----------------------------------------------------
        case 'delete':
            header('Content-Type: application/json');
            $input = json_decode(file_get_contents('php://input'), true);
            $key = trim($input['key'] ?? '');

            if (empty($key)) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'Object key is required']);
                exit;
            }

            $res = $client->deleteObject($key);
            if ($res['success']) {
                broadcastEvent('file_deleted', ['key' => $key]);
                echo json_encode(['success' => true, 'key' => $key]);
            } else {
                http_response_code(500);
                echo json_encode(['success' => false, 'error' => $res['error'] ?: 'Failed to delete object']);
            }
            break;

        // ----------------------------------------------------
        // 5. DELETE FOLDER RECURSIVELY
        // ----------------------------------------------------
        case 'delete_folder':
            header('Content-Type: application/json');
            $input = json_decode(file_get_contents('php://input'), true);
            $folderPrefix = trim($input['prefix'] ?? '');

            if (empty($folderPrefix)) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'Folder prefix is required']);
                exit;
            }

            $res = $client->deleteFolder($folderPrefix);
            if ($res['success']) {
                broadcastEvent('folder_deleted', ['prefix' => $folderPrefix]);
                echo json_encode(['success' => true, 'deletedCount' => $res['deletedCount']]);
            } else {
                http_response_code(500);
                echo json_encode(['success' => false, 'error' => $res['error'] ?: 'Failed to delete folder']);
            }
            break;

        // ----------------------------------------------------
        // 6. BATCH DELETE (FILES & FOLDERS)
        // ----------------------------------------------------
        case 'delete_batch':
            header('Content-Type: application/json');
            $input = json_decode(file_get_contents('php://input'), true);
            $keys    = (array)($input['keys'] ?? []);
            $folders = (array)($input['folders'] ?? []);

            $deletedFiles = 0;
            if (!empty($keys)) {
                $delRes = $client->deleteObjects($keys);
                if ($delRes['success']) {
                    $deletedFiles = count($keys);
                }
            }

            $deletedFolderCount = 0;
            foreach ($folders as $folderPrefix) {
                $folderRes = $client->deleteFolder($folderPrefix);
                if ($folderRes['success']) {
                    $deletedFolderCount += ($folderRes['deletedCount'] ?? 1);
                }
            }

            broadcastEvent('batch_deleted', ['keys' => $keys, 'folders' => $folders]);

            echo json_encode([
                'success'      => true,
                'deletedFiles' => $deletedFiles,
                'deletedTotal' => ($deletedFiles + $deletedFolderCount)
            ]);
            break;

        // ----------------------------------------------------
        // 7. RENAME / MOVE OBJECT
        // ----------------------------------------------------
        case 'rename':
            header('Content-Type: application/json');
            $input = json_decode(file_get_contents('php://input'), true);
            $sourceKey = trim($input['sourceKey'] ?? '');
            $destKey   = trim($input['destKey'] ?? '');

            if (empty($sourceKey) || empty($destKey)) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'Source and destination keys are required']);
                exit;
            }

            $res = $client->renameObject($sourceKey, $destKey);
            if ($res['success']) {
                broadcastEvent('file_renamed', ['source' => $sourceKey, 'dest' => $destKey]);
                echo json_encode(['success' => true, 'newKey' => $destKey]);
            } else {
                http_response_code(500);
                echo json_encode(['success' => false, 'error' => $res['error'] ?: 'Failed to rename object']);
            }
            break;

        // ----------------------------------------------------
        // 8. GET FILE CONTENT (FOR CODE/TEXT EDITOR)
        // ----------------------------------------------------
        case 'get_content':
            header('Content-Type: application/json');
            $key = trim($_GET['key'] ?? '');

            if (empty($key)) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'Key is required']);
                exit;
            }

            $res = $client->getObject($key);
            if ($res['success']) {
                $rawBody = (string)$res['body'];
                $safeContent = mb_convert_encoding($rawBody, 'UTF-8', 'UTF-8, ISO-8859-1, Windows-1252');
                echo json_encode([
                    'success'     => true,
                    'key'         => $key,
                    'content'     => $safeContent,
                    'contentType' => $res['headers']['content-type'] ?? detectMimeType($key),
                    'size'        => strlen($rawBody)
                ], JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_UNICODE);
            } else {
                http_response_code(404);
                echo json_encode(['success' => false, 'error' => $res['error'] ?: 'Object not found']);
            }
            break;

        // ----------------------------------------------------
        // 9. SAVE FILE CONTENT (AFTER EDITING)
        // ----------------------------------------------------
        case 'save_content':
            header('Content-Type: application/json');
            $input = json_decode(file_get_contents('php://input'), true);
            $key     = trim($input['key'] ?? '');
            $content = $input['content'] ?? '';
            $contentType = trim($input['contentType'] ?? detectMimeType($key));
            $cacheControl = trim($input['cacheControl'] ?? DEFAULT_CACHE_CONTROL);

            if (empty($key)) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'Key is required']);
                exit;
            }

            $res = $client->putObject($key, $content, $contentType, [
                'Cache-Control' => $cacheControl
            ]);

            if ($res['success']) {
                broadcastEvent('file_saved', ['key' => $key]);
                echo json_encode([
                    'success'      => true,
                    'key'          => $key,
                    'size'         => strlen($content),
                    'lastModified' => gmdate('Y-m-d\TH:i:s\Z'),
                    'url'          => buildObjectUrl($client, $key)
                ]);
            } else {
                http_response_code(500);
                echo json_encode(['success' => false, 'error' => $res['error'] ?: 'Failed to save content']);
            }
            break;

        // ----------------------------------------------------
        // 10. GET FILE PARAMETERS & METADATA
        // ----------------------------------------------------
        case 'get_parameters':
            header('Content-Type: application/json');
            $key = trim($_GET['key'] ?? '');

            if (empty($key)) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'Key is required']);
                exit;
            }

            $res = $client->headObject($key);
            if ($res['status'] === 200) {
                $headers = $res['headers'];
                echo json_encode([
                    'success'            => true,
                    'key'                => $key,
                    'name'               => basename($key),
                    'size'               => (int)($headers['content-length'] ?? 0),
                    'contentType'        => $headers['content-type'] ?? detectMimeType($key),
                    'lastModified'       => $headers['last-modified'] ?? '',
                    'etag'               => trim($headers['etag'] ?? '', '"'),
                    'cacheControl'       => $headers['cache-control'] ?? '',
                    'contentDisposition' => $headers['content-disposition'] ?? '',
                    'publicUrl'          => buildObjectUrl($client, $key),
                    'presignedUrl'       => $client->getPresignedUrl($key, 3600),
                    'allHeaders'         => $headers
                ]);
            } else {
                http_response_code(404);
                echo json_encode(['success' => false, 'error' => 'Object parameters not found']);
            }
            break;

        // ----------------------------------------------------
        // 11. UPDATE FILE PARAMETERS (CONTENT-TYPE, CACHE-CONTROL, ETC.)
        // ----------------------------------------------------
        case 'update_parameters':
            header('Content-Type: application/json');
            $input = json_decode(file_get_contents('php://input'), true);
            $key                = trim($input['key'] ?? '');
            $contentType        = trim($input['contentType'] ?? detectMimeType($key));
            $cacheControl       = trim($input['cacheControl'] ?? DEFAULT_CACHE_CONTROL);
            $contentDisposition = trim($input['contentDisposition'] ?? '');

            if (empty($key)) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'Key is required']);
                exit;
            }

            $res = $client->updateParameters($key, $contentType, $cacheControl, $contentDisposition);

            if ($res['success']) {
                broadcastEvent('metadata_updated', ['key' => $key]);
                echo json_encode(['success' => true, 'key' => $key]);
            } else {
                http_response_code(500);
                echo json_encode(['success' => false, 'error' => $res['error'] ?: 'Failed to update parameters']);
            }
            break;

        // ----------------------------------------------------
        // 12. GENERATE CUSTOM PRESIGNED URL
        // ----------------------------------------------------
        case 'presign':
            header('Content-Type: application/json');
            $key = trim($_GET['key'] ?? '');
            $expires = (int)($_GET['expires'] ?? 3600);
            if ($expires <= 0) $expires = 3600;

            if (empty($key)) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'Key is required']);
                exit;
            }

            $url = $client->getPresignedUrl($key, $expires);
            echo json_encode([
                'success' => true,
                'key'     => $key,
                'url'     => $url,
                'expires' => $expires
            ]);
            break;

        // ----------------------------------------------------
        // 13. NATIVE STREAMING PREVIEW PROXY (ZERO CACHE)
        // ----------------------------------------------------
        case 'preview':
            $key = trim($_GET['key'] ?? '');
            if (empty($key)) {
                http_response_code(404);
                exit('Key missing');
            }

            $res = $client->getObject($key);
            if ($res['success']) {
                $contentType = $res['headers']['content-type'] ?? detectMimeType($key);
                header('Content-Type: ' . $contentType);
                header('Content-Length: ' . strlen($res['body']));
                header('Content-Disposition: inline; filename="' . basename($key) . '"');
                header('Cache-Control: no-cache, no-store, must-revalidate');
                echo $res['body'];
            } else {
                http_response_code(404);
                echo 'Asset not found';
            }
            exit;

        // ----------------------------------------------------
        // 14. NATIVE DOWNLOAD PROXY
        // ----------------------------------------------------
        case 'download':
            $key = trim($_GET['key'] ?? '');
            if (empty($key)) {
                http_response_code(404);
                exit('Key missing');
            }

            $res = $client->getObject($key);
            if ($res['success']) {
                $contentType = $res['headers']['content-type'] ?? detectMimeType($key);
                header('Content-Type: ' . $contentType);
                header('Content-Length: ' . strlen($res['body']));
                header('Content-Disposition: attachment; filename="' . basename($key) . '"');
                header('Cache-Control: no-cache, no-store, must-revalidate');
                echo $res['body'];
            } else {
                // If direct read fails, redirect to public CDN URL
                $fallbackUrl = buildObjectUrl($client, $key);
                header('Location: ' . $fallbackUrl);
            }
            exit;

        // ----------------------------------------------------
        // 15. SERVER-SENT EVENTS (SSE) REAL-TIME STREAM
        // ----------------------------------------------------
        case 'stream':
            // Disable output buffering
            while (ob_get_level()) {
                ob_end_clean();
            }

            header('Content-Type: text/event-stream');
            header('Cache-Control: no-cache, no-store, must-revalidate');
            header('Connection: keep-alive');
            header('X-Accel-Buffering: no'); // Disable Nginx / LiteSpeed buffer

            $lastKnownTimestamp = (float)($_GET['last_time'] ?? microtime(true));

            // Initial connection greeting
            echo "data: " . json_encode(['type' => 'connected', 'timestamp' => microtime(true)]) . "\n\n";
            flush();

            // Run real-time monitor loop for up to 25 seconds
            $startTime = time();
            while (time() - $startTime < 25) {
                if (connection_aborted()) {
                    break;
                }

                $event = getLatestEvent();
                if ($event && $event['timestamp'] > $lastKnownTimestamp) {
                    echo "data: " . json_encode($event) . "\n\n";
                    flush();
                    $lastKnownTimestamp = $event['timestamp'];
                }

                // Heartbeat ping every 5 seconds to keep connection alive
                if ((time() - $startTime) % 5 === 0) {
                    echo ": heartbeat\n\n";
                    flush();
                }

                usleep(500000); // 0.5 sec sleep
            }
            exit;

        default:
            http_response_code(400);
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'error' => 'Unknown action: ' . $action]);
            break;
    }
} catch (Throwable $e) {
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode([
        'success' => false,
        'error'   => 'Server exception: ' . $e->getMessage()
    ]);
}

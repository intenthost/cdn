<?php
/**
 * Intent CDN - Configuration & 30-Day Authentication System
 * Loads environment variables from .env with zero external dependencies.
 */

// 1. Parse .env file
$envFilePath = __DIR__ . '/.env';
if (file_exists($envFilePath)) {
    $lines = file($envFilePath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || strpos($line, '#') === 0) continue;
        if (strpos($line, '=') !== false) {
            list($name, $value) = explode('=', $line, 2);
            $name = trim($name);
            $value = trim($value);
            $value = trim($value, '"\''); // strip quotes
            putenv("$name=$value");
            $_ENV[$name] = $value;
            $_SERVER[$name] = $value;
        }
    }
}

// Helper to get environment variable
function getEnvVal(string $key, string $default = ''): string {
    $val = getenv($key);
    if ($val !== false && $val !== '') return $val;
    if (isset($_ENV[$key]) && $_ENV[$key] !== '') return (string)$_ENV[$key];
    if (isset($_SERVER[$key]) && $_SERVER[$key] !== '') return (string)$_SERVER[$key];
    return $default;
}

// Dynamic host detection for admin domain if not specified in .env
$protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https://' : 'http://';
$detectedHost = isset($_SERVER['HTTP_HOST']) ? $protocol . $_SERVER['HTTP_HOST'] : '';

// 2. Application Constants
define('APP_NAME', getEnvVal('APP_NAME', 'Intent CDN'));
define('APP_ADMIN_DOMAIN', getEnvVal('APP_ADMIN_DOMAIN', $detectedHost));
define('STORAGE_PUBLIC_DOMAIN', getEnvVal('STORAGE_PUBLIC_DOMAIN', ''));

// Storage Credentials (strictly configured in .env)
define('STORAGE_ACCOUNT_ID', getEnvVal('STORAGE_ACCOUNT_ID', ''));
define('STORAGE_ACCESS_KEY', getEnvVal('STORAGE_ACCESS_KEY', ''));
define('STORAGE_SECRET_KEY', getEnvVal('STORAGE_SECRET_KEY', ''));
define('STORAGE_BUCKET', getEnvVal('STORAGE_BUCKET', ''));
define('STORAGE_API_TOKEN', getEnvVal('STORAGE_API_TOKEN', ''));
$customEndpoint = getEnvVal('STORAGE_ENDPOINT', '');
if (empty($customEndpoint) && !empty(STORAGE_ACCOUNT_ID)) {
    $customEndpoint = STORAGE_ACCOUNT_ID . '.r2.cloudflarestorage.com';
}
define('STORAGE_ENDPOINT', $customEndpoint);
define('STORAGE_REGION', getEnvVal('STORAGE_REGION', 'auto'));

// Authentication Credentials from .env
define('AUTH_USERNAME', getEnvVal('AUTH_USERNAME', 'admin'));
define('AUTH_PASSWORD', getEnvVal('AUTH_PASSWORD', ''));

// 30 Days Session Lifetime (2,592,000 seconds)
define('SESSION_LIFETIME', (int)getEnvVal('SESSION_LIFETIME', '2592000'));

// Realtime & Cache Defaults
define('REALTIME_MODE', getEnvVal('REALTIME_MODE', 'sse'));
define('WEBSOCKET_URL', getEnvVal('WEBSOCKET_URL', ''));
define('DEFAULT_CACHE_CONTROL', 'public, max-age=86400');
define('MAX_UPLOAD_SIZE', 524288000); // 500MB

// File Extensions
define('EDITABLE_EXTENSIONS', [
    'txt', 'md', 'markdown', 'html', 'htm', 'css', 'scss', 'js', 'mjs', 'ts', 'jsx', 'tsx',
    'json', 'xml', 'svg', 'csv', 'yml', 'yaml', 'ini', 'env', 'php', 'sh', 'bash', 'zsh',
    'py', 'sql', 'log', 'htaccess', 'conf'
]);
define('IMAGE_EXTENSIONS', ['png', 'jpg', 'jpeg', 'webp', 'gif', 'svg', 'bmp', 'ico', 'avif']);
define('VIDEO_EXTENSIONS', ['mp4', 'webm', 'ogg', 'mov']);
define('AUDIO_EXTENSIONS', ['mp3', 'wav', 'ogg', 'aac', 'flac', 'm4a']);

// 3. Configure 30-Day Session
if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.gc_maxlifetime', (string)SESSION_LIFETIME);
    ini_set('session.cookie_lifetime', (string)SESSION_LIFETIME);
    
    $isSecure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (isset($_SERVER['SERVER_PORT']) && $_SERVER['SERVER_PORT'] == 443);
    
    session_set_cookie_params([
        'lifetime' => SESSION_LIFETIME,
        'path'     => '/',
        'domain'   => '',
        'secure'   => $isSecure,
        'httponly' => true,
        'samesite' => 'Lax'
    ]);
    
    session_start();
}

// 4. Authentication Functions
function generateAuthToken(): string {
    return hash_hmac('sha256', AUTH_USERNAME . '|' . AUTH_PASSWORD, STORAGE_SECRET_KEY);
}

function isLoggedIn(): bool {
    // Check active session
    if (!empty($_SESSION['auth_user']) && $_SESSION['auth_user'] === AUTH_USERNAME) {
        return true;
    }
    
    // Check persistent 30-day cookie
    if (!empty($_COOKIE['intent_cdn_auth'])) {
        $cookieToken = $_COOKIE['intent_cdn_auth'];
        if (hash_equals(generateAuthToken(), $cookieToken)) {
            $_SESSION['auth_user'] = AUTH_USERNAME;
            return true;
        }
    }
    
    return false;
}

function loginUser(string $username, string $password): bool {
    if ($username === AUTH_USERNAME && $password === AUTH_PASSWORD) {
        $_SESSION['auth_user'] = AUTH_USERNAME;
        
        // Set persistent 30-day cookie
        $isSecure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (isset($_SERVER['SERVER_PORT']) && $_SERVER['SERVER_PORT'] == 443);
        setcookie('intent_cdn_auth', generateAuthToken(), [
            'expires'  => time() + SESSION_LIFETIME,
            'path'     => '/',
            'domain'   => '',
            'secure'   => $isSecure,
            'httponly' => true,
            'samesite' => 'Lax'
        ]);
        
        return true;
    }
    return false;
}

function logoutUser(): void {
    $_SESSION = [];
    if (ini_get("session.use_cookies")) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000,
            $params["path"], $params["domain"],
            $params["secure"], $params["httponly"]
        );
    }
    setcookie('intent_cdn_auth', '', time() - 42000, '/');
    session_destroy();
}

<?php
require_once __DIR__ . '/config.php';

// Auto-forward if already logged in - never allow opening login page when authenticated
if (isLoggedIn()) {
    $redirectUrl = !empty(APP_ADMIN_DOMAIN) ? APP_ADMIN_DOMAIN : 'index.php';
    header('Location: ' . $redirectUrl);
    exit;
}

$errorMessage = '';

// Handle Login Submission (AJAX or Standard POST)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $isJson = (isset($_SERVER['CONTENT_TYPE']) && strpos($_SERVER['CONTENT_TYPE'], 'application/json') !== false);
    
    if ($isJson) {
        $input = json_decode(file_get_contents('php://input'), true);
        $username = trim($input['username'] ?? '');
        $password = trim($input['password'] ?? '');
    } else {
        $username = trim($_POST['username'] ?? '');
        $password = trim($_POST['password'] ?? '');
    }

    if (loginUser($username, $password)) {
        $redirectUrl = !empty(APP_ADMIN_DOMAIN) ? APP_ADMIN_DOMAIN : 'index.php';
        if ($isJson) {
            header('Content-Type: application/json');
            echo json_encode(['success' => true, 'redirect' => $redirectUrl]);
            exit;
        } else {
            header('Location: ' . $redirectUrl);
            exit;
        }
    } else {
        $error = 'Invalid username or password';
        if ($isJson) {
            http_response_code(401);
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'error' => $error]);
            exit;
        } else {
            $errorMessage = $error;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Login - <?= htmlspecialchars(APP_NAME) ?></title>
  
  <meta http-equiv="Cache-Control" content="no-cache, no-store, must-revalidate">
  <meta http-equiv="Pragma" content="no-cache">
  <meta http-equiv="Expires" content="0">

  <!-- Google Fonts: Inter -->
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">

  <!-- Application Stylesheet with Cache-Busting -->
  <link rel="stylesheet" href="assets/css/style.css?v=<?= filemtime(__DIR__ . '/assets/css/style.css') ?>">
  <style>
    .login-wrapper {
      min-height: 100vh;
      display: flex;
      align-items: center;
      justify-content: center;
      padding: 24px 16px;
      background: radial-gradient(circle at 50% 15%, rgba(79, 70, 229, 0.04) 0%, transparent 60%),
                  #f8fafc;
    }
    .login-card {
      width: 100%;
      max-width: 390px;
      background: #ffffff;
      border: 1px solid #e2e8f0;
      border-radius: var(--radius-xl);
      box-shadow: 0 10px 25px -5px rgba(15, 23, 42, 0.06), 0 4px 10px -2px rgba(15, 23, 42, 0.03);
      padding: 34px 28px;
      display: flex;
      flex-direction: column;
    }
    .login-brand-text {
      font-size: 1.35rem;
      font-weight: 600; /* Refined weight, not too bold */
      letter-spacing: -0.02em;
      color: #0f172a !important; /* Explicit deep slate */
      margin-bottom: 6px;
      text-align: center;
      user-select: none;
    }
    .login-subtitle {
      font-size: 0.85rem;
      font-weight: 400;
      color: #64748b !important;
      text-align: center;
      margin-bottom: 24px;
    }
    .login-form {
      display: flex;
      flex-direction: column;
      gap: 16px;
    }
    .form-group {
      display: flex;
      flex-direction: column;
      gap: 6px;
    }
    .form-label {
      font-size: 0.8rem;
      font-weight: 500;
      color: #334155 !important;
    }
    .form-control {
      width: 100%;
      height: 42px;
      background: #f8fafc !important;
      border: 1px solid #cbd5e1 !important;
      border-radius: var(--radius-md);
      padding: 0 14px;
      font-size: 0.9rem;
      font-weight: 400;
      color: #0f172a !important;
      font-family: inherit;
      outline: none;
      transition: all var(--transition-fast);
    }
    .form-control::placeholder {
      color: #94a3b8 !important;
      opacity: 1;
    }
    .form-control:focus {
      background: #ffffff !important;
      border-color: #4f46e5 !important;
      box-shadow: 0 0 0 3px rgba(79, 70, 229, 0.12) !important;
      color: #0f172a !important;
    }
    .session-badge {
      display: flex;
      align-items: center;
      justify-content: space-between;
      font-size: 0.775rem;
      font-weight: 400;
      color: #64748b !important;
      padding: 2px 0;
    }
    .session-badge span:last-child {
      color: #059669 !important;
      font-weight: 500;
    }
    .login-btn {
      width: 100%;
      height: 42px;
      background: #4f46e5;
      color: #ffffff !important;
      border: none;
      border-radius: var(--radius-md);
      font-size: 0.875rem;
      font-weight: 500;
      cursor: pointer;
      transition: all var(--transition-fast);
      margin-top: 6px;
      box-shadow: 0 1px 3px rgba(79, 70, 229, 0.2);
    }
    .login-btn:hover {
      background: #4338ca;
    }
    .login-btn:active {
      transform: scale(0.98);
    }
    .login-error {
      background: #fef2f2;
      border: 1px solid #fecaca;
      border-radius: var(--radius-md);
      padding: 10px 14px;
      color: #dc2626 !important;
      font-size: 0.85rem;
      font-weight: 500;
      margin-bottom: 16px;
      display: flex;
      align-items: center;
      gap: 8px;
    }
  </style>
</head>
<body>

  <div class="login-wrapper">
    <div class="login-card">
      <!-- Text-Only Brand Logo (No Icon, No Image, No Emoji) -->
      <div class="login-brand-text"><?= htmlspecialchars(APP_NAME) ?></div>
      <div class="login-subtitle">Enter your administrator credentials to continue</div>

      <div id="ajaxErrorBox" class="login-error" style="display: <?= !empty($errorMessage) ? 'flex' : 'none' ?>;">
        <span id="errorMessageText"><?= htmlspecialchars($errorMessage) ?></span>
      </div>

      <form class="login-form" id="loginForm" method="POST" action="login.php">
        <div class="form-group">
          <label class="form-label" for="usernameInput">Username</label>
          <input type="text" class="form-control" id="usernameInput" name="username" placeholder="admin" required autofocus autocomplete="username">
        </div>

        <div class="form-group">
          <label class="form-label" for="passwordInput">Password</label>
          <input type="password" class="form-control" id="passwordInput" name="password" placeholder="••••••••••••" required autocomplete="current-password">
        </div>

        <div class="session-badge">
          <span>Active Session</span>
          <span>30 Days Persistent</span>
        </div>

        <button type="submit" class="login-btn" id="loginSubmitBtn">Sign In</button>
      </form>
    </div>
  </div>

  <script>
    const form = document.getElementById('loginForm');
    const submitBtn = document.getElementById('loginSubmitBtn');
    const errorBox = document.getElementById('ajaxErrorBox');
    const errorText = document.getElementById('errorMessageText');

    form.addEventListener('submit', async (e) => {
      e.preventDefault();
      
      const username = document.getElementById('usernameInput').value.trim();
      const password = document.getElementById('passwordInput').value;

      if (!username || !password) return;

      submitBtn.disabled = true;
      submitBtn.textContent = 'Authenticating...';
      errorBox.style.display = 'none';

      try {
        const response = await fetch('login.php', {
          method: 'POST',
          headers: {
            'Content-Type': 'application/json',
            'Cache-Control': 'no-cache'
          },
          body: JSON.stringify({ username, password })
        });

        const data = await response.json();

        if (response.ok && data.success) {
          submitBtn.textContent = 'Success! Redirecting...';
          window.location.href = data.redirect || 'index.php';
        } else {
          submitBtn.disabled = false;
          submitBtn.textContent = 'Sign In';
          errorText.textContent = data.error || 'Invalid credentials';
          errorBox.style.display = 'flex';
        }
      } catch (err) {
        // Fallback to standard form submission if AJAX fetch fails
        form.submit();
      }
    });
  </script>
</body>
</html>

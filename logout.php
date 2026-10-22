<?php
/**
 * Intent CDN - Logout Handler
 * Clears active session and 30-day persistent cookie.
 */

require_once __DIR__ . '/config.php';

logoutUser();

// Redirect to login page
header('Location: login.php');
exit;

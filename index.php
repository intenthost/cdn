<?php
require_once __DIR__ . '/config.php';

// Check authentication
if (!isLoggedIn()) {
    header('Location: login.php');
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?= htmlspecialchars(APP_NAME) ?> - Asset Manager</title>
  
  <!-- Zero-Cache Meta Tags -->
  <meta http-equiv="Cache-Control" content="no-cache, no-store, must-revalidate">
  <meta http-equiv="Pragma" content="no-cache">
  <meta http-equiv="Expires" content="0">

  <!-- Google Fonts: Inter & JetBrains Mono -->
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">

  <!-- Hugeicons 2px Stroke Rounded Webfont -->
  <link rel="stylesheet" href="https://cdn.hugeicons.com/font/hgi-stroke-rounded.css">

  <!-- Application Stylesheet with Cache-Busting -->
  <link rel="stylesheet" href="assets/css/style.css?v=<?= filemtime(__DIR__ . '/assets/css/style.css') ?>">
</head>
<body>

  <!-- Fullscreen Drag & Drop Visual Overlay -->
  <div class="dropzone-overlay" id="dropzoneOverlay">
    <div class="dropzone-icon-circle">
      <i class="hgi-stroke hgi-upload-01" style="font-size: 2.2rem;"></i>
    </div>
    <div class="dropzone-title">Drop files or folders to upload</div>
    <div class="dropzone-subtitle">Assets will be uploaded with zero-cache headers immediately.</div>
  </div>

  <div class="app-container">
    <!-- Single Top Navigation Bar (No additional bar under header) -->
    <header class="top-nav">
      <div class="center-container top-nav-inner">
        <div style="display: flex; align-items: center; gap: 20px;">
          <!-- Text-Only Brand Logo (No Icon, No Image, No Emoji, Not Too Bold) -->
          <div class="brand-section" onclick="window.App.navigateTo('')">
            <span class="brand-title"><?= htmlspecialchars(APP_NAME) ?></span>
          </div>

          <!-- Breadcrumbs Integrated Inline (No separate bar under header) -->
          <div class="breadcrumbs-container" id="breadcrumbs">
            <!-- Dynamically rendered breadcrumbs -->
          </div>
        </div>

        <!-- Header Actions -->
        <div class="nav-actions">
          <div class="realtime-badge" id="realtimeBadge" title="Real-time synchronization status">
            <span class="realtime-dot"></span>
            <span class="realtime-text">Live Sync</span>
          </div>

          <div class="view-mode-toggle">
            <button class="view-mode-btn active" id="gridViewBtn" title="Grid View">
              <i class="hgi-stroke hgi-view" style="font-size: 1.1rem;"></i>
            </button>
            <button class="view-mode-btn" id="listViewBtn" title="List View">
              <i class="hgi-stroke hgi-search-list-01" style="font-size: 1.1rem;"></i>
            </button>
          </div>

          <button class="btn btn-secondary btn-icon" id="refreshBtn" title="Force Refresh">
            <i class="hgi-stroke hgi-arrow-reload-horizontal"></i>
          </button>

          <button class="btn btn-secondary" id="newFolderBtn">
            <i class="hgi-stroke hgi-folder-add"></i>
            <span>New Folder</span>
          </button>

          <button class="btn btn-secondary" onclick="document.getElementById('folderUploadInput').click()">
            <i class="hgi-stroke hgi-folder-upload"></i>
            <span>Upload Folder</span>
          </button>

          <button class="btn btn-primary" onclick="document.getElementById('fileUploadInput').click()">
            <i class="hgi-stroke hgi-upload-01"></i>
            <span>Upload Files</span>
          </button>

          <a href="logout.php" class="btn btn-ghost btn-sm" title="Log Out" style="text-decoration: none;">
            <span>Log Out</span>
          </a>
        </div>
      </div>
    </header>

    <!-- Hidden Native File Inputs -->
    <input type="file" id="fileUploadInput" multiple style="display: none;">
    <input type="file" id="folderUploadInput" webkitdirectory directory multiple style="display: none;">

    <!-- Main Content Area -->
    <main class="main-content">
      <div class="center-container">
        <!-- Grid View Container -->
        <div id="gridViewContainer">
          <!-- Folders Section -->
          <div id="folderSection" style="display: none;">
            <div class="section-heading">Folders</div>
            <div class="grid-container" id="folderGrid"></div>
          </div>

          <!-- Files Section -->
          <div id="fileSection" style="display: none;">
            <div class="section-heading">Files</div>
            <div class="grid-container" id="fileGrid"></div>
          </div>
        </div>

        <!-- Table / List View Container -->
        <div id="tableViewContainer" style="display: none;">
          <div class="table-container">
            <table class="drive-table">
              <thead>
                <tr>
                  <th style="width: 40px;"></th>
                  <th>Name</th>
                  <th>Size</th>
                  <th>Type</th>
                  <th>Last Modified</th>
                  <th style="text-align: right;">Actions</th>
                </tr>
              </thead>
              <tbody id="tableBody"></tbody>
            </table>
          </div>
        </div>

        <!-- Empty State -->
        <div class="empty-state" id="emptyState" style="display: none;">
          <i class="hgi-stroke hgi-folder-file-storage empty-state-icon"></i>
          <h3 class="empty-state-title">This folder is empty</h3>
          <p class="empty-state-subtitle">Drag and drop files or folders anywhere on the screen to upload instantly.</p>
          <button class="btn btn-primary" onclick="document.getElementById('fileUploadInput').click()">
            <i class="hgi-stroke hgi-upload-01"></i>
            <span>Upload File</span>
          </button>
        </div>
      </div>
    </main>

    <!-- Floating Batch Selection Action Bar -->
    <div class="selection-action-bar" id="selectionActionBar">
      <span class="selection-count-text" id="selectionCount">0 selected</span>
      <button class="btn btn-danger btn-sm" id="deleteSelectedBtn">
        <i class="hgi-stroke hgi-delete-02"></i>
        <span>Delete Selected</span>
      </button>
      <button class="btn btn-ghost btn-sm" id="clearSelectionBtn">
        <i class="hgi-stroke hgi-cancel-01"></i>
        <span>Clear</span>
      </button>
    </div>
  </div>

  <!-- Slide-over Asset Parameters Inspector Drawer -->
  <aside class="inspector-drawer" id="inspectorDrawer">
    <div class="inspector-header">
      <div class="inspector-title">Asset Parameters</div>
      <button class="btn btn-ghost btn-icon" id="closeInspectorBtn">
        <i class="hgi-stroke hgi-cancel-01"></i>
      </button>
    </div>

    <div class="inspector-body">
      <!-- Thumbnail Box -->
      <div class="inspector-preview-box" id="inspPreviewBox"></div>

      <!-- Parameter Details -->
      <div class="param-group">
        <div class="param-row">
          <span class="param-row-key">File Name</span>
          <span class="param-row-val" id="inspFileName">—</span>
        </div>
        <div class="param-row">
          <span class="param-row-key">Full Path / Key</span>
          <span class="param-row-val" id="inspFilePath">—</span>
        </div>
        <div class="param-row">
          <span class="param-row-key">File Size</span>
          <span class="param-row-val" id="inspFileSize">—</span>
        </div>
        <div class="param-row">
          <span class="param-row-key">Last Modified</span>
          <span class="param-row-val" id="inspLastModified">—</span>
        </div>
        <div class="param-row">
          <span class="param-row-key">ETag (MD5)</span>
          <span class="param-row-val" id="inspETag">—</span>
        </div>
      </div>

      <!-- Content-Type & Cache-Control Parameters Editor -->
      <div class="param-group">
        <label class="param-label">MIME Content-Type</label>
        <input type="text" class="param-input" id="inspContentType" placeholder="e.g. image/webp">
      </div>

      <div class="param-group">
        <label class="param-label">Cache-Control Header</label>
        <input type="text" class="param-input" id="inspCacheControl" placeholder="e.g. public, max-age=86400">
      </div>

      <button class="btn btn-secondary" id="inspSaveParamsBtn">
        <i class="hgi-stroke hgi-checkmark-circle-01"></i>
        <span>Save Parameters</span>
      </button>

      <!-- Public CDN URL Box -->
      <div class="param-group">
        <label class="param-label">Public Access URL</label>
        <div class="param-url-box">
          <span class="param-url-text" id="inspPublicUrl">—</span>
          <button class="btn btn-ghost btn-icon" id="inspCopyUrlBtn" title="Copy URL">
            <i class="hgi-stroke hgi-copy-01"></i>
          </button>
        </div>
      </div>

      <!-- Presigned Link Generator -->
      <div class="param-group">
        <label class="param-label">Generate Temporary Presigned Link</label>
        <div style="display: flex; gap: 8px;">
          <select class="param-select" id="inspPresignExpiry">
            <option value="900">15 Minutes</option>
            <option value="3600" selected>1 Hour</option>
            <option value="21600">6 Hours</option>
            <option value="86400">24 Hours</option>
            <option value="604800">7 Days</option>
          </select>
          <button class="btn btn-secondary" id="inspGenPresignBtn">
            <i class="hgi-stroke hgi-link"></i>
            <span>Generate</span>
          </button>
        </div>
        <div class="param-url-box" id="inspPresignResultBox" style="display: none; margin-top: 8px;">
          <span class="param-url-text" id="inspPresignUrlText">—</span>
          <button class="btn btn-ghost btn-icon" id="inspCopyPresignBtn" title="Copy Presigned Link">
            <i class="hgi-stroke hgi-copy-01"></i>
          </button>
        </div>
      </div>
    </div>

    <div class="inspector-footer">
      <button class="btn btn-secondary" id="inspEditBtn" style="display: none;">
        <i class="hgi-stroke hgi-edit-02"></i>
        <span>Edit</span>
      </button>
      <button class="btn btn-secondary" id="inspDownloadBtn">
        <i class="hgi-stroke hgi-download-01"></i>
        <span>Download</span>
      </button>
      <button class="btn btn-danger" id="inspDeleteBtn" style="margin-left: auto;">
        <i class="hgi-stroke hgi-delete-02"></i>
        <span>Delete</span>
      </button>
    </div>
  </aside>

  <!-- Fullscreen In-Browser Code & Text Editor Modal -->
  <div class="modal-backdrop editor-modal" id="editorModal">
    <div class="modal-card">
      <div class="modal-header">
        <div style="display: flex; align-items: center; gap: 10px;">
          <i class="hgi-stroke hgi-file-script" style="color: var(--primary); font-size: 1.3rem;"></i>
          <span class="modal-title" id="editorTitle">Editor</span>
          <span style="font-size: 0.75rem; color: var(--text-muted); font-family: var(--font-mono);" id="editorSize">—</span>
        </div>
        <div style="display: flex; align-items: center; gap: 8px;">
          <button class="btn btn-secondary btn-sm" id="editorWrapToggle" title="Toggle Word Wrap">
            <i class="hgi-stroke hgi-arrow-turn-forward"></i>
            <span>Wrap</span>
          </button>
          <button class="btn btn-primary btn-sm" id="editorSaveBtn">
            <i class="hgi-stroke hgi-checkmark-circle-01"></i>
            <span>Save (Ctrl+S)</span>
          </button>
          <button class="btn btn-ghost btn-icon" id="editorCloseBtn">
            <i class="hgi-stroke hgi-cancel-01"></i>
          </button>
        </div>
      </div>
      <div class="modal-body" style="padding: 0; display: flex; flex: 1;">
        <div class="editor-wrapper">
          <div class="editor-container">
            <div class="editor-line-numbers" id="editorLineNumbers">1</div>
            <textarea class="editor-textarea" id="editorTextarea" spellcheck="false"></textarea>
          </div>
        </div>
      </div>
    </div>
  </div>

  <!-- Media Preview Modal -->
  <div class="modal-backdrop preview-modal" id="previewModal">
    <div class="modal-card">
      <div class="modal-header">
        <span class="modal-title" id="previewModalTitle">Preview</span>
        <button class="btn btn-ghost btn-icon" data-close-modal>
          <i class="hgi-stroke hgi-cancel-01"></i>
        </button>
      </div>
      <div class="modal-body">
        <div class="preview-media-container" id="previewMediaContainer"></div>
      </div>
    </div>
  </div>

  <!-- New Folder Modal -->
  <div class="modal-backdrop" id="newFolderModal">
    <div class="modal-card" style="width: 420px;">
      <div class="modal-header">
        <span class="modal-title">Create New Folder</span>
        <button class="btn btn-ghost btn-icon" data-close-modal>
          <i class="hgi-stroke hgi-cancel-01"></i>
        </button>
      </div>
      <div class="modal-body">
        <div class="param-group">
          <label class="param-label">Folder Name</label>
          <input type="text" class="param-input" id="newFolderNameInput" placeholder="e.g. banners" autofocus>
        </div>
      </div>
      <div class="modal-footer">
        <button class="btn btn-ghost" data-close-modal>Cancel</button>
        <button class="btn btn-primary" id="newFolderConfirm">Create Folder</button>
      </div>
    </div>
  </div>

  <!-- Floating Real-Time Upload Progress Drawer -->
  <div class="upload-drawer" id="uploadDrawer">
    <div class="upload-drawer-header">
      <div class="upload-drawer-title">
        <i class="hgi-stroke hgi-upload-01" style="color: var(--primary);"></i>
        <span>Uploading Assets</span>
      </div>
      <div style="display: flex; align-items: center; gap: 8px;">
        <span style="font-size: 0.75rem; color: var(--text-muted);" id="uploadSpeedText">0 KB/s</span>
        <span style="font-size: 0.8rem; font-weight: 600; color: var(--text-main);" id="uploadTotalPercent">0%</span>
        <button class="btn btn-ghost btn-icon-sm" id="closeUploadDrawerBtn" title="Close" style="padding: 2px; width: 20px; height: 20px;">
          <i class="hgi-stroke hgi-cancel-01" style="font-size: 0.85rem;"></i>
        </button>
      </div>
    </div>
    <div class="upload-progress-bar-bg" style="border-radius: 0;">
      <div class="upload-progress-bar-fill" id="uploadTotalProgress"></div>
    </div>
    <div class="upload-drawer-body" id="uploadList"></div>
  </div>

  <!-- Toast Notifications Container -->
  <div class="toast-container" id="toastContainer"></div>

  <!-- Client Injected Config -->
  <script>
    window.APP_CONFIG = {
      appName: <?= json_encode(APP_NAME) ?>,
      realtimeMode: <?= json_encode(REALTIME_MODE) ?>,
      websocketUrl: <?= json_encode(WEBSOCKET_URL) ?>,
      publicDomain: <?= json_encode(STORAGE_PUBLIC_DOMAIN) ?>,
      adminDomain: <?= json_encode(APP_ADMIN_DOMAIN) ?>
    };
  </script>

  <!-- JavaScript Modules with Cache-Busting -->
  <script src="assets/js/api.js?v=<?= filemtime(__DIR__ . '/assets/js/api.js') ?>"></script>
  <script src="assets/js/uploader.js?v=<?= filemtime(__DIR__ . '/assets/js/uploader.js') ?>"></script>
  <script src="assets/js/editor.js?v=<?= filemtime(__DIR__ . '/assets/js/editor.js') ?>"></script>
  <script src="assets/js/realtime.js?v=<?= filemtime(__DIR__ . '/assets/js/realtime.js') ?>"></script>
  <script src="assets/js/app.js?v=<?= filemtime(__DIR__ . '/assets/js/app.js') ?>"></script>
</body>
</html>

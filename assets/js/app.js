/**
 * Drive Vault - Core Application Controller
 * Pure Vanilla JavaScript with real-time zero-cache syncing.
 */

class DriveApp {
  constructor() {
    this.currentPrefix = '';
    this.folders = [];
    this.files = [];
    this.selectedKeys = new Set();
    this.viewMode = localStorage.getItem('drive_view_mode') || 'grid';
    this.searchQuery = '';
    this.sortBy = 'name';
    this.sortOrder = 'asc';
    this.activeInspectorKey = null;

    // Elements
    this.folderGrid = null;
    this.fileGrid = null;
    this.tableBody = null;
    this.folderSection = null;
    this.fileSection = null;
    this.emptyState = null;
    this.breadcrumbsEl = null;
    this.searchInput = null;
    this.selectionBar = null;
    this.selectionCount = null;
    this.inspectorEl = null;
  }

  async init() {
    this.cacheDomElements();
    this.bindEvents();
    this.setViewMode(this.viewMode);

    // Initialize submodules
    window.Editor.init();
    window.Uploader.init({
      onBatchComplete: () => {
        this.showToast('Upload completed', 'success');
        this.refresh();
        window.Realtime.broadcastLocalChange('batch_upload_complete', { prefix: this.currentPrefix });
      }
    });

    window.Realtime.init({
      mode: window.APP_CONFIG.realtimeMode,
      websocketUrl: window.APP_CONFIG.websocketUrl
    });

    // Handle URL hash navigation
    window.addEventListener('hashchange', () => {
      const hash = decodeURIComponent(window.location.hash.replace(/^#/, ''));
      this.navigateTo(hash, false);
    });

    const initialPrefix = decodeURIComponent(window.location.hash.replace(/^#/, ''));
    await this.navigateTo(initialPrefix, false);
  }

  cacheDomElements() {
    this.folderGrid = document.getElementById('folderGrid');
    this.fileGrid = document.getElementById('fileGrid');
    this.tableBody = document.getElementById('tableBody');
    this.folderSection = document.getElementById('folderSection');
    this.fileSection = document.getElementById('fileSection');
    this.emptyState = document.getElementById('emptyState');
    this.breadcrumbsEl = document.getElementById('breadcrumbs');
    this.searchInput = document.getElementById('searchInput');
    this.selectionBar = document.getElementById('selectionActionBar');
    this.selectionCount = document.getElementById('selectionCount');
    this.inspectorEl = document.getElementById('inspectorDrawer');
  }

  bindEvents() {
    // Refresh button
    const refreshBtn = document.getElementById('refreshBtn');
    if (refreshBtn) {
      refreshBtn.addEventListener('click', () => this.refresh());
    }

    // View toggle buttons
    const gridBtn = document.getElementById('gridViewBtn');
    const listBtn = document.getElementById('listViewBtn');
    if (gridBtn) gridBtn.addEventListener('click', () => this.setViewMode('grid'));
    if (listBtn) listBtn.addEventListener('click', () => this.setViewMode('table'));

    // New Folder modal
    const newFolderBtn = document.getElementById('newFolderBtn');
    const newFolderModal = document.getElementById('newFolderModal');
    const newFolderConfirm = document.getElementById('newFolderConfirm');
    const newFolderNameInput = document.getElementById('newFolderNameInput');

    if (newFolderBtn && newFolderModal) {
      newFolderBtn.addEventListener('click', () => {
        if (newFolderNameInput) newFolderNameInput.value = '';
        newFolderModal.classList.add('open');
        setTimeout(() => newFolderNameInput && newFolderNameInput.focus(), 100);
      });
    }

    if (newFolderConfirm) {
      newFolderConfirm.addEventListener('click', async () => {
        const name = newFolderNameInput.value.trim();
        if (!name) return;
        try {
          await Api.createFolder(name, this.currentPrefix);
          newFolderModal.classList.remove('open');
          this.showToast('Folder created', 'success');
          this.refresh();
          window.Realtime.broadcastLocalChange('folder_created', { name, prefix: this.currentPrefix });
        } catch (err) {
          this.showToast(err.message, 'error');
        }
      });
    }

    // Selection Bar actions
    const clearSelectionBtn = document.getElementById('clearSelectionBtn');
    if (clearSelectionBtn) {
      clearSelectionBtn.addEventListener('click', () => this.clearSelection());
    }

    const deleteSelectedBtn = document.getElementById('deleteSelectedBtn');
    if (deleteSelectedBtn) {
      deleteSelectedBtn.addEventListener('click', () => this.promptBatchDelete());
    }

    // Modal Close buttons
    document.querySelectorAll('[data-close-modal]').forEach(el => {
      el.addEventListener('click', () => {
        const modal = el.closest('.modal-backdrop');
        if (modal) modal.classList.remove('open');
      });
    });

    // Inspector close button
    const closeInspectorBtn = document.getElementById('closeInspectorBtn');
    if (closeInspectorBtn) {
      closeInspectorBtn.addEventListener('click', () => this.closeInspector());
    }

    // Close on Escape
    window.addEventListener('keydown', (e) => {
      if (e.key === 'Escape') {
        this.closeInspector();
        document.querySelectorAll('.modal-backdrop.open').forEach(m => m.classList.remove('open'));
      }
    });
  }

  setViewMode(mode) {
    this.viewMode = mode;
    localStorage.setItem('drive_view_mode', mode);

    const gridBtn = document.getElementById('gridViewBtn');
    const listBtn = document.getElementById('listViewBtn');
    const gridViewEl = document.getElementById('gridViewContainer');
    const tableViewEl = document.getElementById('tableViewContainer');

    if (gridBtn && listBtn) {
      gridBtn.classList.toggle('active', mode === 'grid');
      listBtn.classList.toggle('active', mode === 'table');
    }

    if (gridViewEl && tableViewEl) {
      gridViewEl.style.display = mode === 'grid' ? 'block' : 'none';
      tableViewEl.style.display = mode === 'table' ? 'block' : 'none';
    }

    this.render();
  }

  async navigateTo(prefix, updateHash = true) {
    this.currentPrefix = prefix;
    if (updateHash) {
      window.location.hash = encodeURIComponent(prefix);
    }
    this.clearSelection();
    await this.refresh();
  }

  async refresh(silent = false) {
    try {
      if (!silent) {
        document.body.style.cursor = 'wait';
      }

      const res = await Api.list(this.currentPrefix);

      if (res.configured === false) {
        this.showConfigAlert(res.error);
        return;
      }

      this.folders = res.folders || [];
      this.files = res.files || [];
      this.renderBreadcrumbs(res.breadcrumbs || []);
      this.render();
    } catch (err) {
      this.showToast('Failed to fetch assets: ' + err.message, 'error');
    } finally {
      document.body.style.cursor = 'default';
    }
  }

  showConfigAlert(msg) {
    if (this.emptyState) {
      this.emptyState.style.display = 'flex';
      this.emptyState.innerHTML = `
        <i class="hgi-stroke hgi-folder-security empty-state-icon" style="color:var(--warning);opacity:0.9;"></i>
        <h3 class="empty-state-title">Configuration Needed</h3>
        <p class="empty-state-subtitle" style="max-width:480px;line-height:1.6;">
          ${msg || 'Please enter your storage credentials in <code>config.php</code> to activate your asset vault.'}
        </p>
      `;
    }
    if (this.folderSection) this.folderSection.style.display = 'none';
    if (this.fileSection) this.fileSection.style.display = 'none';
  }

  renderBreadcrumbs(crumbs) {
    if (!this.breadcrumbsEl) return;
    this.breadcrumbsEl.innerHTML = '';

    crumbs.forEach((crumb, idx) => {
      const isLast = idx === crumbs.length - 1;
      const crumbEl = document.createElement('div');
      crumbEl.className = `breadcrumb-crumb ${isLast ? 'active' : ''}`;
      crumbEl.innerHTML = `
        <i class="hgi-stroke ${crumb.prefix === '' ? 'hgi-folder-cloud' : 'hgi-folder-01'}"></i>
        <span>${crumb.name}</span>
      `;
      crumbEl.addEventListener('click', () => {
        if (!isLast) this.navigateTo(crumb.prefix);
      });

      this.breadcrumbsEl.appendChild(crumbEl);

      if (!isLast) {
        const sep = document.createElement('span');
        sep.className = 'breadcrumb-separator';
        sep.innerHTML = '<i class="hgi-stroke hgi-arrow-right-01" style="font-size:0.8rem;"></i>';
        this.breadcrumbsEl.appendChild(sep);
      }
    });
  }

  getFilteredData() {
    return { folders: this.folders, files: this.files };
  }

  render() {
    const { folders, files } = this.getFilteredData();
    const hasItems = folders.length > 0 || files.length > 0;

    if (this.emptyState) {
      this.emptyState.style.display = hasItems ? 'none' : 'flex';
    }

    if (this.folderSection) {
      this.folderSection.style.display = folders.length > 0 ? 'block' : 'none';
    }
    if (this.fileSection) {
      this.fileSection.style.display = files.length > 0 ? 'block' : 'none';
    }

    if (this.viewMode === 'grid') {
      this.renderGridView(folders, files);
    } else {
      this.renderTableView(folders, files);
    }

    this.updateSelectionBar();
  }

  insertUploadedFile(fileItem) {
    const idx = this.files.findIndex(f => f.key === fileItem.key);
    if (idx >= 0) {
      this.files[idx] = fileItem;
    } else {
      this.files.unshift(fileItem);
    }
    this.render();
  }

  updateFileInState(key, updates) {
    const file = this.files.find(f => f.key === key);
    if (file) {
      Object.assign(file, updates);
      this.render();
    }
  }

  downloadFile(key, fileName) {
    const name = fileName || key.split('/').pop();
    const downloadUrl = `api.php?action=download&key=${encodeURIComponent(key)}&_t=${Date.now()}`;
    const a = document.createElement('a');
    a.style.display = 'none';
    a.href = downloadUrl;
    a.setAttribute('download', name);
    document.body.appendChild(a);
    a.click();
    setTimeout(() => a.remove(), 1000);
    this.showToast(`Downloading ${name}`, 'success');
  }

  copyLink(url) {
    if (!url) return;
    navigator.clipboard.writeText(url);
    this.showToast('Public link copied to clipboard', 'success');
  }

  renderGridView(folders, files) {
    if (this.folderGrid) {
      this.folderGrid.innerHTML = '';
      folders.forEach(f => {
        const card = document.createElement('div');
        const isSelected = this.selectedKeys.has(f.prefix);
        card.className = `drive-card folder-card ${isSelected ? 'selected' : ''}`;
        card.innerHTML = `
          <div class="card-checkbox ${isSelected ? 'checked' : ''}" data-prefix="${f.prefix}"></div>
          <div class="folder-icon-wrapper">
            <i class="hgi-stroke hgi-folder-02" style="font-size:1.5rem;"></i>
          </div>
          <div class="folder-info">
            <div class="folder-title" title="${f.name}">${f.name}</div>
            <div class="folder-meta">Folder</div>
          </div>
        `;

        const checkbox = card.querySelector('.card-checkbox');
        checkbox.addEventListener('click', (e) => {
          e.stopPropagation();
          this.toggleSelection(f.prefix, true);
        });

        card.addEventListener('click', () => {
          this.navigateTo(f.prefix);
        });

        this.folderGrid.appendChild(card);
      });
    }

    if (this.fileGrid) {
      this.fileGrid.innerHTML = '';
      files.forEach(f => {
        const card = document.createElement('div');
        const isSelected = this.selectedKeys.has(f.key);
        card.className = `drive-card file-card ${isSelected ? 'selected' : ''}`;

        let previewHtml = '';
        if (f.isImage) {
          previewHtml = `<img src="api.php?action=preview&key=${encodeURIComponent(f.key)}" class="file-thumbnail" loading="lazy" alt="${f.name}">`;
        } else {
          previewHtml = `<i class="hgi-stroke ${this.getFileIconClass(f.extension)}" style="font-size:2.8rem;color:var(--text-secondary);"></i>`;
        }

        card.innerHTML = `
          <div class="card-checkbox ${isSelected ? 'checked' : ''}" data-key="${f.key}"></div>
          <div class="file-card-preview" title="Click to preview/edit">
            ${previewHtml}
          </div>
          <div class="file-card-details">
            <div class="file-title" title="${f.name}">${f.name}</div>
            <div class="file-meta-row">
              <span>${this.formatBytes(f.size)}</span>
              <span>${this.formatRelativeTime(f.lastModified)}</span>
            </div>
            <div class="card-quick-actions">
              <button class="btn btn-ghost btn-icon-sm" title="Copy Public URL" data-action="copy">
                <i class="hgi-stroke hgi-copy-01"></i>
              </button>
              <button class="btn btn-ghost btn-icon-sm" title="Download File" data-action="download">
                <i class="hgi-stroke hgi-download-01"></i>
              </button>
              ${f.isEditable ? `
              <button class="btn btn-ghost btn-icon-sm" title="Edit File" data-action="edit">
                <i class="hgi-stroke hgi-edit-02"></i>
              </button>` : ''}
              <button class="btn btn-ghost btn-icon-sm" title="View Details" data-action="inspect">
                <i class="hgi-stroke hgi-view"></i>
              </button>
              <button class="btn btn-ghost btn-icon-sm" title="Delete" data-action="delete" style="color:var(--danger);">
                <i class="hgi-stroke hgi-delete-02"></i>
              </button>
            </div>
          </div>
        `;

        // Checkbox click
        const checkbox = card.querySelector('.card-checkbox');
        checkbox.addEventListener('click', (e) => {
          e.stopPropagation();
          this.toggleSelection(f.key, false);
        });

        // Quick action: Copy link
        card.querySelector('[data-action="copy"]').addEventListener('click', (e) => {
          e.stopPropagation();
          this.copyLink(f.url);
        });

        // Quick action: Download
        card.querySelector('[data-action="download"]').addEventListener('click', (e) => {
          e.stopPropagation();
          this.downloadFile(f.key, f.name);
        });

        // Quick action: Edit (if present)
        const editBtn = card.querySelector('[data-action="edit"]');
        if (editBtn) {
          editBtn.addEventListener('click', (e) => {
            e.stopPropagation();
            window.Editor.open(f.key, f.extension);
          });
        }

        // Quick action: Inspect
        card.querySelector('[data-action="inspect"]').addEventListener('click', (e) => {
          e.stopPropagation();
          this.openInspector(f.key);
        });

        // Quick action: Delete
        card.querySelector('[data-action="delete"]').addEventListener('click', async (e) => {
          e.stopPropagation();
          if (confirm(`Delete file "${f.name}"?`)) {
            await Api.deleteObject(f.key);
            this.showToast('File deleted', 'success');
            this.removeFileFromState(f.key);
            window.Realtime.broadcastLocalChange('file_deleted', { key: f.key });
          }
        });

        // Preview box click -> preview or edit
        card.querySelector('.file-card-preview').addEventListener('click', (e) => {
          e.stopPropagation();
          if (f.isEditable) {
            window.Editor.open(f.key, f.extension);
          } else {
            this.openPreviewModal(f);
          }
        });

        // Card base click -> open inspector
        card.addEventListener('click', () => {
          this.openInspector(f.key);
        });

        this.fileGrid.appendChild(card);
      });
    }
  }

  renderTableView(folders, files) {
    if (!this.tableBody) return;
    this.tableBody.innerHTML = '';

    folders.forEach(f => {
      const tr = document.createElement('tr');
      const isSelected = this.selectedKeys.has(f.prefix);
      if (isSelected) tr.classList.add('selected');

      tr.innerHTML = `
        <td style="width:40px;">
          <div class="card-checkbox ${isSelected ? 'checked' : ''}" style="opacity:1;position:static;"></div>
        </td>
        <td class="col-name">
          <i class="hgi-stroke hgi-folder-02" style="color:var(--primary);font-size:1.3rem;"></i>
          <span>${f.name}</span>
        </td>
        <td>—</td>
        <td>Folder</td>
        <td>—</td>
        <td style="text-align:right;">
          <button class="btn btn-ghost btn-icon-sm" title="Delete Folder" data-action="delete-folder" style="color:var(--danger);">
            <i class="hgi-stroke hgi-delete-02"></i>
          </button>
        </td>
      `;

      tr.querySelector('.card-checkbox').addEventListener('click', (e) => {
        e.stopPropagation();
        this.toggleSelection(f.prefix, true);
      });

      tr.querySelector('[data-action="delete-folder"]').addEventListener('click', async (e) => {
        e.stopPropagation();
        if (confirm(`Delete folder "${f.name}" and all its contents recursively?`)) {
          await Api.deleteFolder(f.prefix);
          this.showToast('Folder deleted', 'success');
          this.refresh();
          window.Realtime.broadcastLocalChange('folder_deleted', { prefix: f.prefix });
        }
      });

      tr.addEventListener('click', () => this.navigateTo(f.prefix));
      this.tableBody.appendChild(tr);
    });

    files.forEach(f => {
      const tr = document.createElement('tr');
      const isSelected = this.selectedKeys.has(f.key);
      if (isSelected) tr.classList.add('selected');

      tr.innerHTML = `
        <td style="width:40px;">
          <div class="card-checkbox ${isSelected ? 'checked' : ''}" style="opacity:1;position:static;"></div>
        </td>
        <td class="col-name">
          <i class="hgi-stroke ${this.getFileIconClass(f.extension)}" style="color:var(--text-secondary);font-size:1.3rem;"></i>
          <span>${f.name}</span>
        </td>
        <td>${this.formatBytes(f.size)}</td>
        <td>${f.extension.toUpperCase() || 'FILE'}</td>
        <td>${this.formatRelativeTime(f.lastModified)}</td>
        <td style="text-align:right;">
          <div style="display:inline-flex;align-items:center;gap:4px;">
            <button class="btn btn-ghost btn-icon-sm" title="Copy Public URL" data-action="copy">
              <i class="hgi-stroke hgi-copy-01"></i>
            </button>
            <button class="btn btn-ghost btn-icon-sm" title="Download" data-action="download">
              <i class="hgi-stroke hgi-download-01"></i>
            </button>
            ${f.isEditable ? `
            <button class="btn btn-ghost btn-icon-sm" title="Edit File" data-action="edit">
              <i class="hgi-stroke hgi-edit-02"></i>
            </button>
            ` : ''}
            <button class="btn btn-ghost btn-icon-sm" title="Inspect Parameters" data-action="inspect">
              <i class="hgi-stroke hgi-view"></i>
            </button>
            <button class="btn btn-ghost btn-icon-sm" title="Delete" data-action="delete" style="color:var(--danger);">
              <i class="hgi-stroke hgi-delete-02"></i>
            </button>
          </div>
        </td>
      `;

      tr.querySelector('.card-checkbox').addEventListener('click', (e) => {
        e.stopPropagation();
        this.toggleSelection(f.key, false);
      });

      tr.querySelector('[data-action="copy"]').addEventListener('click', (e) => {
        e.stopPropagation();
        this.copyLink(f.url);
      });

      tr.querySelector('[data-action="download"]').addEventListener('click', (e) => {
        e.stopPropagation();
        this.downloadFile(f.key, f.name);
      });

      const editBtn = tr.querySelector('[data-action="edit"]');
      if (editBtn) {
        editBtn.addEventListener('click', (e) => {
          e.stopPropagation();
          window.Editor.open(f.key, f.extension);
        });
      }

      tr.querySelector('[data-action="inspect"]').addEventListener('click', (e) => {
        e.stopPropagation();
        this.openInspector(f.key);
      });

      tr.querySelector('[data-action="delete"]').addEventListener('click', async (e) => {
        e.stopPropagation();
        if (confirm(`Delete file "${f.name}"?`)) {
          await Api.deleteObject(f.key);
          this.showToast('File deleted', 'success');
          this.removeFileFromState(f.key);
          window.Realtime.broadcastLocalChange('file_deleted', { key: f.key });
        }
      });

      tr.addEventListener('click', () => this.openInspector(f.key));
      this.tableBody.appendChild(tr);
    });
  }

  removeFileFromState(key) {
    this.files = this.files.filter(f => f.key !== key);
    this.selectedKeys.delete(key);
    this.render();
  }

  toggleSelection(keyOrPrefix, isFolder) {
    if (this.selectedKeys.has(keyOrPrefix)) {
      this.selectedKeys.delete(keyOrPrefix);
    } else {
      this.selectedKeys.add(keyOrPrefix);
    }
    this.render();
  }

  clearSelection() {
    this.selectedKeys.clear();
    this.render();
  }

  updateSelectionBar() {
    const count = this.selectedKeys.size;
    if (this.selectionBar) {
      if (count > 0) {
        this.selectionBar.classList.add('visible');
        if (this.selectionCount) {
          this.selectionCount.textContent = `${count} selected`;
        }
      } else {
        this.selectionBar.classList.remove('visible');
      }
    }
  }

  async promptBatchDelete() {
    const count = this.selectedKeys.size;
    if (count === 0) return;
    if (!confirm(`Are you sure you want to permanently delete these ${count} selected item(s)?`)) {
      return;
    }

    const keys = [];
    const folders = [];
    this.selectedKeys.forEach(k => {
      if (k.endsWith('/')) {
        folders.push(k);
      } else {
        keys.push(k);
      }
    });

    try {
      await Api.deleteBatch(keys, folders);
      this.showToast(`Deleted ${count} item(s)`, 'success');
      this.clearSelection();
      this.refresh();
      window.Realtime.broadcastLocalChange('batch_deleted', { keys, folders });
    } catch (err) {
      this.showToast('Batch delete failed: ' + err.message, 'error');
    }
  }

  async openInspector(key) {
    this.activeInspectorKey = key;
    if (!this.inspectorEl) return;
    this.inspectorEl.classList.add('open');

    // Reset fields to loading
    document.getElementById('inspFileName').textContent = key.split('/').pop();
    document.getElementById('inspFilePath').textContent = key;
    document.getElementById('inspFileSize').textContent = 'Loading...';
    document.getElementById('inspLastModified').textContent = 'Loading...';
    document.getElementById('inspETag').textContent = 'Loading...';
    document.getElementById('inspContentType').value = '';
    document.getElementById('inspCacheControl').value = '';
    document.getElementById('inspPublicUrl').textContent = 'Loading...';

    const previewBox = document.getElementById('inspPreviewBox');
    if (previewBox) {
      const ext = key.split('.').pop().toLowerCase();
      if (['png', 'jpg', 'jpeg', 'webp', 'gif', 'svg'].includes(ext)) {
        previewBox.innerHTML = `<img src="api.php?action=preview&key=${encodeURIComponent(key)}" class="inspector-preview-img" alt="Preview">`;
      } else {
        previewBox.innerHTML = `<i class="hgi-stroke ${this.getFileIconClass(ext)}" style="font-size:4rem;color:var(--text-secondary);"></i>`;
      }
    }

    try {
      const params = await Api.getParameters(key);
      document.getElementById('inspFileSize').textContent = this.formatBytes(params.size) + ` (${params.size.toLocaleString()} bytes)`;
      document.getElementById('inspLastModified').textContent = params.lastModified || '—';
      document.getElementById('inspETag').textContent = params.etag || '—';
      document.getElementById('inspContentType').value = params.contentType || '';
      document.getElementById('inspCacheControl').value = params.cacheControl || '';
      document.getElementById('inspPublicUrl').textContent = params.publicUrl || '';

      // Bind Save Parameters
      const saveParamsBtn = document.getElementById('inspSaveParamsBtn');
      if (saveParamsBtn) {
        saveParamsBtn.onclick = async () => {
          saveParamsBtn.disabled = true;
          saveParamsBtn.textContent = 'Saving...';
          try {
            await Api.updateParameters(
              key,
              document.getElementById('inspContentType').value.trim(),
              document.getElementById('inspCacheControl').value.trim()
            );
            this.showToast('Parameters updated', 'success');
            saveParamsBtn.disabled = false;
            saveParamsBtn.innerHTML = '<i class="hgi-stroke hgi-checkmark-circle-01"></i> Save Parameters';
          } catch (e) {
            saveParamsBtn.disabled = false;
            this.showToast(e.message, 'error');
          }
        };
      }

      // Copy Public URL
      const copyUrlBtn = document.getElementById('inspCopyUrlBtn');
      if (copyUrlBtn) {
        copyUrlBtn.onclick = () => {
          navigator.clipboard.writeText(params.publicUrl);
          this.showToast('Public link copied to clipboard', 'success');
        };
      }

      // Generate Presigned URL
      const genPresignBtn = document.getElementById('inspGenPresignBtn');
      const presignExpirySelect = document.getElementById('inspPresignExpiry');
      const presignBox = document.getElementById('inspPresignResultBox');
      const presignUrlText = document.getElementById('inspPresignUrlText');
      if (genPresignBtn && presignExpirySelect) {
        genPresignBtn.onclick = async () => {
          const exp = parseInt(presignExpirySelect.value, 10);
          try {
            const pRes = await Api.getPresignedUrl(key, exp);
            presignUrlText.textContent = pRes.url;
            presignBox.style.display = 'flex';
          } catch (e) {
            this.showToast('Failed to generate presigned link', 'error');
          }
        };
      }

      // Copy Presigned URL
      const copyPresignBtn = document.getElementById('inspCopyPresignBtn');
      if (copyPresignBtn) {
        copyPresignBtn.onclick = () => {
          navigator.clipboard.writeText(presignUrlText.textContent);
          this.showToast('Presigned link copied', 'success');
        };
      }

      // Download Action
      const downloadBtn = document.getElementById('inspDownloadBtn');
      if (downloadBtn) {
        downloadBtn.onclick = () => {
          window.location.href = `api.php?action=download&key=${encodeURIComponent(key)}`;
        };
      }

      // Delete Action
      const deleteBtn = document.getElementById('inspDeleteBtn');
      if (deleteBtn) {
        deleteBtn.onclick = async () => {
          if (confirm(`Delete file "${key.split('/').pop()}"?`)) {
            await Api.deleteObject(key);
            this.closeInspector();
            this.showToast('File deleted', 'success');
            this.refresh();
            window.Realtime.broadcastLocalChange('file_deleted', { key });
          }
        };
      }

      // Edit Action
      const editBtn = document.getElementById('inspEditBtn');
      if (editBtn) {
        const ext = key.split('.').pop().toLowerCase();
        const isEditable = ['txt', 'md', 'html', 'css', 'js', 'json', 'xml', 'svg', 'csv', 'yml', 'yaml', 'php', 'sql'].includes(ext);
        editBtn.style.display = isEditable ? 'inline-flex' : 'none';
        editBtn.onclick = () => {
          window.Editor.open(key, params.contentType);
        };
      }

    } catch (err) {
      this.showToast('Failed to load file parameters', 'error');
    }
  }

  closeInspector() {
    if (this.inspectorEl) {
      this.inspectorEl.classList.remove('open');
    }
    this.activeInspectorKey = null;
  }

  openPreviewModal(file) {
    const modal = document.getElementById('previewModal');
    const container = document.getElementById('previewMediaContainer');
    const title = document.getElementById('previewModalTitle');
    if (!modal || !container) return;

    title.textContent = file.name;
    const url = `api.php?action=preview&key=${encodeURIComponent(file.key)}`;

    if (file.isImage) {
      container.innerHTML = `<img src="${url}" alt="${file.name}">`;
    } else if (file.isVideo) {
      container.innerHTML = `<video controls autoplay><source src="${url}"></video>`;
    } else if (file.isAudio) {
      container.innerHTML = `<audio controls autoplay><source src="${url}"></audio>`;
    } else if (file.extension === 'pdf') {
      container.innerHTML = `<iframe src="${url}" style="width:100%;height:65vh;border:none;"></iframe>`;
    } else {
      window.open(url, '_blank');
      return;
    }

    modal.classList.add('open');
  }

  getFileIconClass(ext) {
    switch (ext) {
      case 'png': case 'jpg': case 'jpeg': case 'gif': case 'webp': case 'svg': case 'ico':
        return 'hgi-ai-image';
      case 'mp4': case 'webm': case 'mov': case 'avi': case 'mkv':
        return 'hgi-file-video';
      case 'mp3': case 'wav': case 'ogg': case 'flac':
        return 'hgi-file-music';
      case 'zip': case 'tar': case 'gz': case 'rar': case '7z':
        return 'hgi-file-zip';
      case 'js': case 'ts': case 'json': case 'html': case 'css': case 'php': case 'py': case 'sql':
        return 'hgi-file-script';
      case 'pdf': case 'doc': case 'docx': case 'txt': case 'md':
        return 'hgi-file-attachment';
      default:
        return 'hgi-file-01';
    }
  }

  formatBytes(bytes) {
    if (!bytes || bytes === 0) return '0 B';
    const k = 1024;
    const sizes = ['B', 'KB', 'MB', 'GB', 'TB'];
    const i = Math.floor(Math.log(bytes) / Math.log(k));
    return parseFloat((bytes / Math.pow(k, i)).toFixed(1)) + ' ' + sizes[i];
  }

  formatRelativeTime(dateString) {
    if (!dateString) return '—';
    const d = new Date(dateString);
    const now = new Date();
    const diffSec = Math.floor((now - d) / 1000);

    if (diffSec < 60) return 'Just now';
    if (diffSec < 3600) return `${Math.floor(diffSec / 60)}m ago`;
    if (diffSec < 86400) return `${Math.floor(diffSec / 3600)}h ago`;
    if (diffSec < 2592000) return `${Math.floor(diffSec / 86400)}d ago`;

    return d.toLocaleDateString();
  }

  showToast(message, type = 'info') {
    const container = document.getElementById('toastContainer');
    if (!container) return;

    const toast = document.createElement('div');
    toast.className = `toast toast-${type}`;
    let iconClass = 'hgi-checkmark-circle-01';
    if (type === 'error') iconClass = 'hgi-cancel-circle';

    toast.innerHTML = `
      <i class="hgi-stroke ${iconClass}" style="font-size:1.2rem;color:${type === 'error' ? 'var(--danger)' : 'var(--success)'};"></i>
      <span>${message}</span>
    `;

    container.appendChild(toast);
    setTimeout(() => {
      toast.style.opacity = '0';
      toast.style.transform = 'translateY(10px)';
      toast.style.transition = 'all 0.25s ease';
      setTimeout(() => toast.remove(), 250);
    }, 3500);
  }
}

window.App = new DriveApp();
document.addEventListener('DOMContentLoaded', () => {
  window.App.init();
});

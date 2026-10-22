/**
 * Drive Vault - Uploader Engine
 * Supports file uploads, recursive folder uploads, drag & drop, and real-time progress.
 */

class UploadEngine {
  constructor() {
    this.queue = [];
    this.activeUploads = 0;
    this.maxConcurrent = 3;
    this.isProcessing = false;
    this.totalBytes = 0;
    this.uploadedBytes = 0;
    this.startTime = null;
    this.drawerEl = null;
    this.listEl = null;
    this.totalProgressBar = null;
    this.totalPercentText = null;
    this.speedText = null;
    this.onBatchComplete = null;
    this.closeTimeout = null;
  }

  init(options = {}) {
    this.drawerEl = document.getElementById('uploadDrawer');
    this.listEl = document.getElementById('uploadList');
    this.totalProgressBar = document.getElementById('uploadTotalProgress');
    this.totalPercentText = document.getElementById('uploadTotalPercent');
    this.speedText = document.getElementById('uploadSpeedText');
    this.onBatchComplete = options.onBatchComplete || null;

    this.setupDragAndDrop();
    this.setupFileInputs();

    const closeBtn = document.getElementById('closeUploadDrawerBtn');
    if (closeBtn) {
      closeBtn.addEventListener('click', () => this.closeDrawer());
    }
  }

  setupDragAndDrop() {
    const dropOverlay = document.getElementById('dropzoneOverlay');
    let dragCounter = 0;

    window.addEventListener('dragenter', (e) => {
      e.preventDefault();
      dragCounter++;
      dropOverlay.classList.add('active');
    });

    window.addEventListener('dragleave', (e) => {
      e.preventDefault();
      dragCounter--;
      if (dragCounter <= 0) {
        dragCounter = 0;
        dropOverlay.classList.remove('active');
      }
    });

    window.addEventListener('dragover', (e) => {
      e.preventDefault();
    });

    window.addEventListener('drop', async (e) => {
      e.preventDefault();
      dragCounter = 0;
      dropOverlay.classList.remove('active');

      const items = e.dataTransfer.items;
      if (items && items.length > 0) {
        const filesToUpload = [];
        const entries = [];
        for (let i = 0; i < items.length; i++) {
          const item = items[i];
          if (item.webkitGetAsEntry) {
            const entry = item.webkitGetAsEntry();
            if (entry) entries.push(entry);
          } else if (item.kind === 'file') {
            const file = item.getAsFile();
            if (file) filesToUpload.push({ file, relativePath: file.name });
          }
        }

        for (const entry of entries) {
          await this.scanEntry(entry, '', filesToUpload);
        }

        if (filesToUpload.length > 0) {
          this.enqueueFiles(filesToUpload);
        }
      } else if (e.dataTransfer.files.length > 0) {
        const files = Array.from(e.dataTransfer.files).map(f => ({ file: f, relativePath: f.name }));
        this.enqueueFiles(files);
      }
    });
  }

  async scanEntry(entry, path, list) {
    if (entry.isFile) {
      const file = await new Promise((resolve) => entry.file(resolve));
      list.push({ file, relativePath: (path ? path + '/' : '') + file.name });
    } else if (entry.isDirectory) {
      const dirReader = entry.createReader();
      const readEntries = async () => {
        const entries = await new Promise((resolve) => dirReader.readEntries(resolve));
        if (entries.length > 0) {
          for (const subEntry of entries) {
            await this.scanEntry(subEntry, (path ? path + '/' : '') + entry.name, list);
          }
          await readEntries();
        }
      };
      await readEntries();
    }
  }

  setupFileInputs() {
    const fileInput = document.getElementById('fileUploadInput');
    const folderInput = document.getElementById('folderUploadInput');

    if (fileInput) {
      fileInput.addEventListener('change', (e) => {
        const files = Array.from(e.target.files).map(f => ({ file: f, relativePath: f.name }));
        if (files.length > 0) {
          this.enqueueFiles(files);
          fileInput.value = '';
        }
      });
    }

    if (folderInput) {
      folderInput.addEventListener('change', (e) => {
        const files = Array.from(e.target.files).map(f => ({
          file: f,
          relativePath: f.webkitRelativePath || f.name
        }));
        if (files.length > 0) {
          this.enqueueFiles(files);
          folderInput.value = '';
        }
      });
    }
  }

  enqueueFiles(fileItems) {
    const currentPrefix = window.App ? window.App.currentPrefix : '';

    if (this.closeTimeout) {
      clearTimeout(this.closeTimeout);
      this.closeTimeout = null;
    }

    if (this.queue.length === 0) {
      this.totalBytes = 0;
      this.uploadedBytes = 0;
      this.startTime = Date.now();
      if (this.listEl) this.listEl.innerHTML = '';
    }

    fileItems.forEach(item => {
      const id = 'up_' + Math.random().toString(36).substr(2, 9);
      const queueItem = {
        id,
        file: item.file,
        relativePath: item.relativePath,
        prefix: currentPrefix,
        size: item.file.size,
        loaded: 0,
        status: 'pending' // pending, uploading, success, error
      };
      this.totalBytes += item.file.size;
      this.queue.push(queueItem);
      this.renderItemRow(queueItem);
    });

    if (this.drawerEl) {
      this.drawerEl.classList.add('visible');
    }

    this.processQueue();
  }

  renderItemRow(item) {
    if (!this.listEl) return;
    const row = document.createElement('div');
    row.id = `item-${item.id}`;
    row.className = 'upload-item-row';
    row.innerHTML = `
      <div class="upload-item-header">
        <span class="upload-item-name" title="${item.relativePath}">${item.relativePath}</span>
        <span class="upload-item-status" id="status-${item.id}">Queued</span>
      </div>
      <div class="upload-progress-bar-bg">
        <div class="upload-progress-bar-fill" id="fill-${item.id}"></div>
      </div>
    `;
    this.listEl.prepend(row);
  }

  processQueue() {
    while (this.activeUploads < this.maxConcurrent) {
      const nextItem = this.queue.find(i => i.status === 'pending');
      if (!nextItem) break;
      this.startUpload(nextItem);
    }
  }

  async startUpload(item) {
    this.activeUploads++;
    item.status = 'uploading';

    const statusEl = document.getElementById(`status-${item.id}`);
    const fillEl = document.getElementById(`fill-${item.id}`);
    if (statusEl) statusEl.textContent = '0%';

    try {
      const res = await Api.uploadFileWithProgress(
        item.file,
        item.prefix,
        item.relativePath,
        '',
        (progress) => {
          item.loaded = progress.loaded;
          if (fillEl) fillEl.style.width = `${progress.percent}%`;
          if (statusEl) statusEl.textContent = `${progress.percent}%`;
          this.updateTotalProgress();
        }
      );

      item.status = 'success';
      item.loaded = item.size;
      if (fillEl) fillEl.style.width = '100%';
      if (statusEl) {
        statusEl.textContent = 'Done';
        statusEl.style.color = 'var(--success)';
      }

      // Instant SPA UI update - show file in list immediately
      if (window.App && res.file) {
        window.App.insertUploadedFile(res.file);
      }
    } catch (err) {
      item.status = 'error';
      if (statusEl) {
        statusEl.textContent = 'Failed';
        statusEl.style.color = 'var(--danger)';
      }
      if (window.App) {
        window.App.showToast(`Upload failed for ${item.relativePath}: ${err.message}`, 'error');
      }
      console.error('Upload failed:', err);
    } finally {
      this.activeUploads--;
      this.updateTotalProgress();

      const hasMorePending = this.queue.some(i => i.status === 'pending');
      if (hasMorePending) {
        this.processQueue();
      } else if (this.activeUploads === 0) {
        // All finished
        if (this.onBatchComplete) {
          this.onBatchComplete();
        }

        // Auto-close upload drawer after 3 seconds
        if (this.closeTimeout) clearTimeout(this.closeTimeout);
        this.closeTimeout = setTimeout(() => {
          this.closeDrawer();
        }, 3000);
      }
    }
  }

  updateTotalProgress() {
    let currentLoaded = 0;
    this.queue.forEach(i => { currentLoaded += i.loaded; });

    const percent = this.totalBytes > 0 ? Math.round((currentLoaded / this.totalBytes) * 100) : 100;
    if (this.totalProgressBar) {
      this.totalProgressBar.style.width = `${percent}%`;
    }
    if (this.totalPercentText) {
      this.totalPercentText.textContent = `${percent}%`;
    }

    // Speed calculation
    const elapsedSeconds = (Date.now() - this.startTime) / 1000;
    if (elapsedSeconds > 0 && this.speedText) {
      const speed = currentLoaded / elapsedSeconds;
      this.speedText.textContent = this.formatSpeed(speed);
    }
  }

  formatSpeed(bytesPerSec) {
    if (bytesPerSec > 1048576) {
      return (bytesPerSec / 1048576).toFixed(1) + ' MB/s';
    }
    return (bytesPerSec / 1024).toFixed(0) + ' KB/s';
  }

  closeDrawer() {
    if (this.drawerEl) {
      this.drawerEl.classList.remove('visible');
    }
    if (this.closeTimeout) {
      clearTimeout(this.closeTimeout);
      this.closeTimeout = null;
    }
    setTimeout(() => {
      if (this.activeUploads === 0 && !this.queue.some(i => i.status === 'pending' || i.status === 'uploading')) {
        this.queue = [];
        if (this.listEl) this.listEl.innerHTML = '';
        if (this.totalProgressBar) this.totalProgressBar.style.width = '0%';
        if (this.totalPercentText) this.totalPercentText.textContent = '0%';
        if (this.speedText) this.speedText.textContent = '0 KB/s';
      }
    }, 300);
  }
}

window.Uploader = new UploadEngine();

/**
 * Drive Vault - In-Browser Code & Text Editor
 */

class CodeEditor {
  constructor() {
    this.modalEl = null;
    this.textareaEl = null;
    this.lineNumbersEl = null;
    this.titleEl = null;
    this.sizeEl = null;
    this.saveBtn = null;
    this.currentKey = null;
    this.currentMime = null;
    this.initialContent = '';
    this.isDirty = false;
  }

  init() {
    this.modalEl = document.getElementById('editorModal');
    this.textareaEl = document.getElementById('editorTextarea');
    this.lineNumbersEl = document.getElementById('editorLineNumbers');
    this.titleEl = document.getElementById('editorTitle');
    this.sizeEl = document.getElementById('editorSize');
    this.saveBtn = document.getElementById('editorSaveBtn');

    if (!this.modalEl || !this.textareaEl) return;

    // Line numbers update on input and scroll
    this.textareaEl.addEventListener('input', () => {
      this.updateLineNumbers();
      this.checkDirty();
    });

    this.textareaEl.addEventListener('scroll', () => {
      if (this.lineNumbersEl) {
        this.lineNumbersEl.scrollTop = this.textareaEl.scrollTop;
      }
    });

    // Handle Tab key
    this.textareaEl.addEventListener('keydown', (e) => {
      if (e.key === 'Tab') {
        e.preventDefault();
        const start = this.textareaEl.selectionStart;
        const end = this.textareaEl.selectionEnd;
        const value = this.textareaEl.value;
        this.textareaEl.value = value.substring(0, start) + '  ' + value.substring(end);
        this.textareaEl.selectionStart = this.textareaEl.selectionEnd = start + 2;
        this.updateLineNumbers();
        this.checkDirty();
      }

      // Cmd+S or Ctrl+S to save
      if ((e.ctrlKey || e.metaKey) && e.key === 's') {
        e.preventDefault();
        this.save();
      }

      // Escape to close
      if (e.key === 'Escape') {
        this.close();
      }
    });

    // Save button click
    if (this.saveBtn) {
      this.saveBtn.addEventListener('click', () => this.save());
    }

    // Word wrap button
    const wrapBtn = document.getElementById('editorWrapToggle');
    if (wrapBtn) {
      wrapBtn.addEventListener('click', () => {
        const isWrapped = this.textareaEl.style.whiteSpace === 'pre-wrap';
        this.textareaEl.style.whiteSpace = isWrapped ? 'pre' : 'pre-wrap';
        wrapBtn.classList.toggle('active', !isWrapped);
      });
    }

    // Close button
    const closeBtn = document.getElementById('editorCloseBtn');
    if (closeBtn) {
      closeBtn.addEventListener('click', () => this.close());
    }
  }

  async open(key, mimeType = '') {
    this.currentKey = key;
    this.currentMime = mimeType;
    if (this.titleEl) this.titleEl.textContent = key.split('/').pop();
    if (this.sizeEl) this.sizeEl.textContent = 'Loading...';
    this.textareaEl.value = '';
    this.lineNumbersEl.innerHTML = '1';
    this.isDirty = false;
    this.modalEl.classList.add('open');

    try {
      const res = await Api.getContent(key);
      this.initialContent = res.content || '';
      this.textareaEl.value = this.initialContent;
      this.currentMime = res.contentType || this.currentMime;
      if (this.sizeEl) this.sizeEl.textContent = this.formatBytes(res.size || 0);
      this.updateLineNumbers();
      this.textareaEl.focus();
    } catch (err) {
      if (window.App) window.App.showToast('Failed to load file content: ' + err.message, 'error');
      this.close();
    }
  }

  updateLineNumbers() {
    if (!this.lineNumbersEl || !this.textareaEl) return;
    const lines = this.textareaEl.value.split('\n').length;
    let numbers = '';
    for (let i = 1; i <= lines; i++) {
      numbers += i + '\n';
    }
    this.lineNumbersEl.textContent = numbers;
  }

  checkDirty() {
    const dirty = this.textareaEl.value !== this.initialContent;
    if (dirty !== this.isDirty) {
      this.isDirty = dirty;
      if (this.saveBtn) {
        this.saveBtn.innerHTML = dirty
          ? '<i class="hgi-stroke hgi-checkmark-circle-01"></i> Save Changes*'
          : '<i class="hgi-stroke hgi-checkmark-circle-01"></i> Saved';
      }
    }
    if (this.sizeEl) {
      this.sizeEl.textContent = this.formatBytes(new Blob([this.textareaEl.value]).size);
    }
  }

  async save() {
    if (!this.currentKey) return;
    const content = this.textareaEl.value;

    if (this.saveBtn) {
      this.saveBtn.disabled = true;
      this.saveBtn.innerHTML = '<i class="hgi-stroke hgi-arrow-reload-horizontal"></i> Saving...';
    }

    try {
      const res = await Api.saveContent(this.currentKey, content, this.currentMime);
      this.initialContent = content;
      this.isDirty = false;
      if (this.saveBtn) {
        this.saveBtn.disabled = false;
        this.saveBtn.innerHTML = '<i class="hgi-stroke hgi-checkmark-circle-01"></i> Saved';
      }
      if (window.App) {
        window.App.showToast('File saved successfully', 'success');
        // Update file in state without reloading
        window.App.updateFileInState(this.currentKey, {
          size: res.size || new Blob([content]).size,
          lastModified: res.lastModified || new Date().toISOString()
        });
      }
    } catch (err) {
      if (this.saveBtn) {
        this.saveBtn.disabled = false;
        this.saveBtn.innerHTML = '<i class="hgi-stroke hgi-checkmark-circle-01"></i> Save Changes';
      }
      if (window.App) window.App.showToast('Failed to save file: ' + err.message, 'error');
    }
  }

  close() {
    if (this.isDirty) {
      if (!confirm('You have unsaved changes. Are you sure you want to close?')) {
        return;
      }
    }
    this.modalEl.classList.remove('open');
    this.currentKey = null;
    this.isDirty = false;
  }

  formatBytes(bytes) {
    if (bytes === 0) return '0 B';
    const k = 1024;
    const sizes = ['B', 'KB', 'MB', 'GB'];
    const i = Math.floor(Math.log(bytes) / Math.log(k));
    return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i];
  }
}

window.Editor = new CodeEditor();

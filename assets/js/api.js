/**
 * Drive Vault - API Client (Zero-Cache & HTTPS Guaranteed)
 */

const Api = {
  baseUrl: 'api.php',

  /**
   * Helper to perform zero-cache fetch
   */
  async request(endpoint, options = {}) {
    const url = new URL(this.baseUrl, window.location.href);
    url.searchParams.set('_t', Date.now()); // Prevent any edge or browser cache

    if (options.params) {
      for (const [k, v] of Object.entries(options.params)) {
        if (v !== undefined && v !== null) {
          url.searchParams.set(k, v);
        }
      }
    }

    const headers = {
      'Cache-Control': 'no-cache, no-store, must-revalidate',
      'Pragma': 'no-cache',
      'Expires': '0',
      ...(options.headers || {})
    };

    const fetchConfig = {
      method: options.method || 'GET',
      headers,
      cache: 'no-store'
    };

    if (options.body) {
      if (options.body instanceof FormData) {
        fetchConfig.body = options.body;
      } else {
        headers['Content-Type'] = 'application/json';
        fetchConfig.body = JSON.stringify(options.body);
      }
    }

    const response = await fetch(url.toString(), fetchConfig);
    const data = await response.json().catch(() => ({}));

    if (!response.ok) {
      if (response.status === 401 || data.needsAuth) {
        window.location.href = 'login.php';
        return;
      }
      throw new Error(data.error || `HTTP error ${response.status}`);
    }

    return data;
  },

  /**
   * List directory contents
   */
  async list(prefix = '', token = '') {
    return this.request('', {
      params: { action: 'list', prefix, token }
    });
  },

  /**
   * Create new folder
   */
  async createFolder(name, prefix = '') {
    return this.request('', {
      method: 'POST',
      params: { action: 'create_folder' },
      body: { name, prefix }
    });
  },

  /**
   * Delete single object
   */
  async deleteObject(key) {
    return this.request('', {
      method: 'POST',
      params: { action: 'delete' },
      body: { key }
    });
  },

  /**
   * Delete folder recursively
   */
  async deleteFolder(prefix) {
    return this.request('', {
      method: 'POST',
      params: { action: 'delete_folder' },
      body: { prefix }
    });
  },

  /**
   * Batch delete multiple files and folders
   */
  async deleteBatch(keys = [], folders = []) {
    return this.request('', {
      method: 'POST',
      params: { action: 'delete_batch' },
      body: { keys, folders }
    });
  },

  /**
   * Rename or move an object
   */
  async rename(sourceKey, destKey) {
    return this.request('', {
      method: 'POST',
      params: { action: 'rename' },
      body: { sourceKey, destKey }
    });
  },

  /**
   * Get text/code content of file for editor
   */
  async getContent(key) {
    return this.request('', {
      params: { action: 'get_content', key }
    });
  },

  /**
   * Save edited text/code content
   */
  async saveContent(key, content, contentType, cacheControl) {
    return this.request('', {
      method: 'POST',
      params: { action: 'save_content' },
      body: { key, content, contentType, cacheControl }
    });
  },

  /**
   * Get all parameters & metadata for a file
   */
  async getParameters(key) {
    return this.request('', {
      params: { action: 'get_parameters', key }
    });
  },

  /**
   * Update file parameters (MIME, Cache-Control, Content-Disposition)
   */
  async updateParameters(key, contentType, cacheControl, contentDisposition) {
    return this.request('', {
      method: 'POST',
      params: { action: 'update_parameters' },
      body: { key, contentType, cacheControl, contentDisposition }
    });
  },

  /**
   * Generate Presigned temporary URL
   */
  async getPresignedUrl(key, expires = 3600) {
    return this.request('', {
      params: { action: 'presign', key, expires }
    });
  },

  /**
   * Upload single file with progress tracking
   */
  uploadFileWithProgress(file, prefix = '', relativePath = '', cacheControl = '', onProgress = null) {
    return new Promise((resolve, reject) => {
      const xhr = new XMLHttpRequest();
      const url = new URL(this.baseUrl, window.location.href);
      url.searchParams.set('action', 'upload');
      url.searchParams.set('_t', Date.now());

      xhr.open('POST', url.toString(), true);
      xhr.setRequestHeader('Cache-Control', 'no-cache, no-store, must-revalidate');
      xhr.setRequestHeader('Pragma', 'no-cache');

      if (xhr.upload && onProgress) {
        xhr.upload.onprogress = (evt) => {
          if (evt.lengthComputable) {
            const percent = Math.round((evt.loaded / evt.total) * 100);
            onProgress({ loaded: evt.loaded, total: evt.total, percent });
          }
        };
      }

      xhr.onload = () => {
        try {
          const res = JSON.parse(xhr.responseText);
          if (xhr.status >= 200 && xhr.status < 300 && res.success) {
            resolve(res);
          } else {
            reject(new Error(res.error || `HTTP ${xhr.status}`));
          }
        } catch (e) {
          reject(new Error(`Failed to parse response: ${xhr.responseText}`));
        }
      };

      xhr.onerror = () => reject(new Error('Network upload failed'));

      const formData = new FormData();
      formData.append('file', file);
      if (prefix) formData.append('prefix', prefix);
      if (relativePath) formData.append('relativePath', relativePath);
      if (cacheControl) formData.append('cacheControl', cacheControl);

      xhr.send(formData);
    });
  }
};

window.Api = Api;

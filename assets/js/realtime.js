/**
 * Drive Vault - Real-Time Synchronization Engine
 * Combines WebSockets, Server-Sent Events (SSE), and Cross-Tab BroadcastChannel
 * to guarantee instantaneous zero-cache synchronization across all sessions.
 */

class RealtimeSync {
  constructor() {
    this.ws = null;
    this.sse = null;
    this.channel = null;
    this.badgeEl = null;
    this.mode = 'sse';
    this.wsUrl = '';
    this.reconnectTimer = null;
    this.lastEventTime = Date.now() / 1000;
  }

  init(config = {}) {
    this.mode = config.mode || 'sse';
    this.wsUrl = config.websocketUrl || '';
    this.badgeEl = document.getElementById('realtimeBadge');

    // Setup Cross-Tab BroadcastChannel
    if ('BroadcastChannel' in window) {
      try {
        this.channel = new BroadcastChannel('vault_realtime_sync');
        this.channel.onmessage = (event) => {
          this.handleEvent(event.data);
        };
      } catch (e) {
        console.warn('BroadcastChannel not supported', e);
      }
    }

    // Connect via WS or SSE
    if (this.wsUrl) {
      this.connectWebSocket();
    } else {
      this.connectSSE();
    }
  }

  connectWebSocket() {
    try {
      this.ws = new WebSocket(this.wsUrl);

      this.ws.onopen = () => {
        this.setStatus('connected', 'Live WS Sync');
      };

      this.ws.onmessage = (event) => {
        try {
          const data = JSON.parse(event.data);
          this.handleEvent(data);
        } catch (e) {}
      };

      this.ws.onclose = () => {
        this.setStatus('reconnecting', 'Sync Reconnecting...');
        // Fallback to SSE if WS drops
        this.scheduleReconnect(() => this.connectSSE());
      };

      this.ws.onerror = () => {
        this.ws.close();
      };
    } catch (e) {
      this.connectSSE();
    }
  }

  connectSSE() {
    if (this.sse) {
      this.sse.close();
    }

    const sseUrl = new URL('api.php', window.location.href);
    sseUrl.searchParams.set('action', 'stream');
    sseUrl.searchParams.set('last_time', this.lastEventTime);
    sseUrl.searchParams.set('_t', Date.now());

    this.sse = new EventSource(sseUrl.toString());

    this.sse.onopen = () => {
      this.setStatus('connected', 'Live Sync');
    };

    this.sse.onmessage = (event) => {
      try {
        const data = JSON.parse(event.data);
        if (data.timestamp) {
          this.lastEventTime = data.timestamp;
        }
        if (data.type && data.type !== 'connected') {
          this.handleEvent(data);
        }
      } catch (e) {}
    };

    this.sse.onerror = () => {
      this.setStatus('reconnecting', 'Reconnecting...');
      this.sse.close();
      this.scheduleReconnect(() => this.connectSSE());
    };
  }

  scheduleReconnect(fn) {
    if (this.reconnectTimer) clearTimeout(this.reconnectTimer);
    this.reconnectTimer = setTimeout(() => {
      fn();
    }, 4000);
  }

  setStatus(state, label) {
    if (!this.badgeEl) return;
    const dot = this.badgeEl.querySelector('.realtime-dot');
    const text = this.badgeEl.querySelector('.realtime-text');

    if (text) text.textContent = label;
    if (state === 'connected') {
      this.badgeEl.style.borderColor = 'rgba(16, 185, 129, 0.25)';
      this.badgeEl.style.background = 'rgba(16, 185, 129, 0.1)';
      if (dot) dot.style.backgroundColor = 'var(--success)';
    } else {
      this.badgeEl.style.borderColor = 'rgba(245, 158, 11, 0.25)';
      this.badgeEl.style.background = 'rgba(245, 158, 11, 0.1)';
      if (dot) dot.style.backgroundColor = 'var(--warning)';
    }
  }

  broadcastLocalChange(eventType, details = {}) {
    const payload = {
      type: eventType,
      details,
      timestamp: Date.now() / 1000,
      source: 'local'
    };

    // Broadcast to other open browser tabs
    if (this.channel) {
      this.channel.postMessage(payload);
    }

    // Broadcast over WebSocket if active
    if (this.ws && this.ws.readyState === WebSocket.OPEN) {
      this.ws.send(JSON.stringify(payload));
    }
  }

  handleEvent(eventData) {
    // Avoid double triggering if source is current tab
    if (eventData.source === 'local') return;

    // Refresh directory without cache
    if (window.App && typeof window.App.refresh === 'function') {
      window.App.refresh(true); // silent background refresh
    }
  }
}

window.Realtime = new RealtimeSync();

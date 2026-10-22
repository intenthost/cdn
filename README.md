# Intent CDN

[![License: MIT](https://img.shields.io/badge/License-MIT-blue.svg)](LICENSE)
[![PHP Version](https://img.shields.io/badge/PHP-%3E%3D%207.4%20%7C%208.x-8892BF.svg)](https://www.php.net/)
[![Database](https://img.shields.io/badge/Database-Zero--DB%20(None)-success.svg)](#)
[![Architecture](https://img.shields.io/badge/Stack-Vanilla%20PHP%20%26%20JS-orange.svg)](#)

A fast, lightweight, and modern cloud asset manager built with pure PHP and Vanilla JavaScript. Designed specifically to run on standard **cPanel shared hosting**, VPS, or dedicated servers with **zero database configuration**.

Manage, upload, preview, edit, and serve your cloud assets directly through a clean, centered interface powered by Cloudflare R2 / S3-compatible cloud storage.

---

## ✨ Features

- ⚡ **Zero Database Required**: No MySQL, PostgreSQL, or SQLite. All objects and metadata are handled directly via a standalone AWS Signature v4 client.
- 🔐 **30-Day Persistent Authentication**: Single-admin credential authentication configured through `.env`. Features 30-day persistent cookies and auto-redirect from `/login` when authenticated.
- 📁 **Smart File & Folder Uploads**: Drag-and-drop multiple files or complete folder structures recursively with live progress tracking, transfer speed indicators, and automatic drawer dismiss.
- ✏️ **Built-in Code & Text Editor**: In-browser code editing for `.html`, `.css`, `.js`, `.json`, `.md`, `.txt`, `.svg`, `.xml`, `.php`, etc., with line numbers, tab indentation, and instant zero-cache saving.
- 🔍 **Asset Parameters & Inspector**: Inspect and update MIME Content-Type headers, customize Cache-Control policies, copy CDN public links, and generate expiring presigned URLs.
- 🔄 **Real-Time Zero-Cache Synchronization**: Real-time push updates across open tabs and devices using Server-Sent Events (SSE) and `BroadcastChannel`. Every request enforces zero edge/browser cache headers.
- 🎨 **Minimalist Light Interface**: Clean centered dashboard with 2px stroke Hugeicons and refined typography.

---

## 🚀 Quick Start

### 1. Clone the Repository

```bash
git clone https://github.com/intenthost/cdn.git
cd cdn
```

### 2. Configure Environment

Copy the example environment file and customize your settings:

```bash
cp .env.example .env
```

Open `.env` and fill in your cloud storage credentials and preferred admin password:

```env
# Application Branding & Domains
APP_NAME="Intent CDN"
APP_ADMIN_DOMAIN="https://admin.yourdomain.com"
STORAGE_PUBLIC_DOMAIN="https://cdn.yourdomain.com"

# Cloud Storage Credentials (Cloudflare R2 / S3 compatible)
STORAGE_ACCOUNT_ID="your_cloudflare_account_id"
STORAGE_ACCESS_KEY="your_s3_access_key"
STORAGE_SECRET_KEY="your_s3_secret_key"
STORAGE_BUCKET="your_bucket_name"
STORAGE_API_TOKEN="your_cloudflare_api_token"
STORAGE_ENDPOINT="" # Leave empty for automatic R2 endpoint
STORAGE_REGION="auto"

# Authentication Credentials
AUTH_USERNAME="admin"
AUTH_PASSWORD="your_secure_password"
SESSION_LIFETIME="2592000"  # 30 Days in seconds
```

### 3. Deploy to cPanel / Web Server

1. Upload the project files to your cPanel `public_html` directory (or a subdomain folder such as `cdna.yourdomain.com`).
2. Ensure the `.htaccess` file is uploaded to handle clean URLs (`/login`, `/logout`) and security headers.
3. Access your dashboard at `https://admin.yourdomain.com/login` and log in with the credentials defined in `.env`.

---

## ⚙️ Configuration Reference

| Variable | Description | Default |
| :--- | :--- | :--- |
| `APP_NAME` | Name displayed in the navigation header | `Intent CDN` |
| `APP_ADMIN_DOMAIN` | Dashboard URL for auto-forwarding from `/login` | Current host |
| `STORAGE_PUBLIC_DOMAIN` | Public CDN domain used for 1-click asset URL copies | Empty (falls back to presigned URLs) |
| `STORAGE_ACCOUNT_ID` | Cloudflare Account ID | *Required* |
| `STORAGE_ACCESS_KEY` | S3 / R2 Access Key ID | *Required* |
| `STORAGE_SECRET_KEY` | S3 / R2 Secret Access Key | *Required* |
| `STORAGE_BUCKET` | Target bucket name | *Required* |
| `STORAGE_ENDPOINT` | Custom storage endpoint | `{ACCOUNT_ID}.r2.cloudflarestorage.com` |
| `STORAGE_REGION` | Storage region | `auto` |
| `AUTH_USERNAME` | Administrator login username | `admin` |
| `AUTH_PASSWORD` | Administrator login password | *Required* |
| `SESSION_LIFETIME` | Session and persistent cookie duration (seconds) | `2592000` (30 days) |
| `REALTIME_MODE` | Real-time synchronization driver (`sse` or `ws`) | `sse` |

---

## 📁 Project Structure

```
├── .env.example          # Template for environment settings
├── .gitignore            # Git exclusion rules
├── .htaccess             # Apache / LiteSpeed rewrite rules & security headers
├── LICENSE               # MIT License
├── README.md             # Project documentation
├── api.php               # Zero-cache RESTful API and SSE realtime endpoint
├── config.php            # Environment loader and 30-day authentication handler
├── index.php             # Main dashboard UI
├── login.php             # Light-themed login screen
├── logout.php            # Session termination and cookie cleanup
├── s3_client.php         # Standalone AWS Signature v4 pure-PHP client
├── ws-server.php         # Optional standalone WebSocket daemon (RFC 6455)
└── assets/
    ├── css/
    │   └── style.css     # Clean light theme stylesheet
    └── js/
        ├── api.js        # Zero-cache API client
        ├── app.js        # Dashboard controller and asset parameter inspector
        ├── editor.js     # In-browser text and code editor
        ├── realtime.js   # SSE and cross-tab synchronization engine
        └── uploader.js   # Multi-file and recursive folder upload engine
```

---

## 🛡️ Security

- **Environment File Protection**: Apache / LiteSpeed configuration in `.htaccess` blocks public web access to `.env`.
- **Zero-Leak Guarantee**: `.gitignore` strictly excludes `.env` and local deployment scripts to prevent accidental commits to public repositories.
- **Strict HTTPS Sessions**: Cookies are marked `HttpOnly` and `SameSite=Lax` with automatic `Secure` flag detection over HTTPS.

---

## 📄 License

This project is licensed under the [MIT License](LICENSE) by **Intent Host**.

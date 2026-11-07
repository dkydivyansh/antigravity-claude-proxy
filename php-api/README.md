# PHP Storage API (SQLite)

Lightweight PHP + SQLite backend that gives your stateless Render deployment persistent storage for account configurations (OAuth tokens, refresh tokens, rate-limit state).

## Key Features

1. **Auto-creates Database:** `storage.sqlite` and the required `kv_store` table are automatically created on first request.
2. **Hardcoded Secret Built-In:** Preconfigured with a secret key so you don't even need `.env` on your PHP host (unless you want to override it).

---

## 1. Quick Setup on Your PHP Server

Upload `api.php` and `.htaccess` to your PHP host (e.g. into a folder named `proxy-storage`):

```bash
# Upload to your server
scp php-api/api.php php-api/.htaccess user@yourserver:/var/www/html/proxy-storage/

# Ensure directory is writable for SQLite database auto-creation
chmod 775 /var/www/html/proxy-storage
```

---

## 2. Render Environment Variables

Add these two environment variables to your Render service:

| Variable | Value |
|---|---|
| `REMOTE_STORAGE_URL` | `https://yourserver.com/proxy-storage/api.php` |
| `REMOTE_STORAGE_SECRET` | `antigravity_secret_render_key_2026` |

*(If you ever want to change the secret, edit `HARDCODED_SECRET` on line 19 of `api.php` or set `STORAGE_SECRET` in environment).*

---

## 3. Verification

Test in your browser or with curl:

- **Health check:**
  ```
  GET https://yourserver.com/proxy-storage/api.php?action=health
  ```
  Should return: `{"ok":true,"db_exists":true,"db_writable":true...}`

- **Test Load:**
  ```
  GET https://yourserver.com/proxy-storage/api.php?action=load&secret=antigravity_secret_render_key_2026
  ```
  Should return: `{"accounts":[],"settings":{},"activeIndex":0}`

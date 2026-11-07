# PHP Storage API

Lightweight PHP + SQLite backend that gives your stateless Render deployment a place to persist account config (OAuth tokens, refresh tokens, rate-limit state).

## Setup

```bash
# 1. Copy files to your PHP server (e.g. /var/www/html/proxy-storage/)
scp api.php .htaccess .env.example user@yourserver:/var/www/html/proxy-storage/
ssh user@yourserver
cd /var/www/html/proxy-storage

# 2. Create .env
cp .env.example .env
nano .env   # set STORAGE_SECRET to a random string

# 3. Make directory writable so PHP can create storage.sqlite
chmod 755 .
```

## Render Environment Variables

Set these on your Render service:

| Variable | Value |
|---|---|
| `REMOTE_STORAGE_URL` | `https://yourserver.com/proxy-storage/api.php` |
| `REMOTE_STORAGE_SECRET` | Same as `STORAGE_SECRET` in your `.env` |

## Endpoints

| Method | URL | Description |
|---|---|---|
| `GET` | `?action=health` | Liveness check (no auth) |
| `GET` | `?action=load` | Load full accounts JSON |
| `POST` | `?action=save` | Save full accounts JSON |

All requests (except `health`) require the header:
```
X-Api-Secret: <your-secret>
```


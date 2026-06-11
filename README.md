# Property Aggregator API

Symfony API that merges property data from two JSON files, normalizes it, caches the result, and exposes it at `GET /properties`.

---

## Quick start (Windows)

Open **two** PowerShell windows in the project folder (`C:\Sympony`).

### Terminal 1 — start the server (leave open)

```powershell
cd C:\Sympony
.\scripts\start-server.ps1
```

Or manually:

```powershell
php -S 127.0.0.1:8000 -t public
```

### Terminal 2 — test the API

```powershell
cd C:\Sympony
.\scripts\test-api.ps1
```

**Pagination example:**

```powershell
.\scripts\test-api.ps1 -Page 2 -Limit 5
```

**Or open in browser:**

```
http://127.0.0.1:8000/properties
http://127.0.0.1:8000/properties?page=1&limit=5
```

**Run automated tests:**

```powershell
.\scripts\run-tests.ps1
```

Or:

```powershell
php vendor\bin\phpunit
```

---

## How to control this project

| What you want | Command |
|---------------|---------|
| Install dependencies | `composer install` |
| Start API server | `.\scripts\start-server.ps1` |
| Test API from terminal | `.\scripts\test-api.ps1` |
| Test API in browser | `http://127.0.0.1:8000/properties` |
| Run unit tests | `.\scripts\run-tests.ps1` |
| Clear Symfony cache | `php bin/console cache:clear` |
| Start Redis (optional) | `docker compose up -d` |

### Important files you can edit

| File | Purpose |
|------|---------|
| `public/data/source_a.json` | Simulated API source A |
| `public/data/source_b.json` | Simulated API source B |
| `.env` | Cache TTL, Redis URL, app secret |
| `src/Service/PropertyAggregatorService.php` | Merge, normalize, cache logic |
| `src/Controller/PropertyController.php` | `/properties` endpoint |

After editing JSON source files, reload `/properties` — cache refreshes when file content changes.

---

## First-time setup

### Requirements

- PHP 8.2+
- Composer 2.x
- PHP extensions: `json`, `openssl`, `mbstring`, `curl`, `zip`
- Redis optional for local dev (filesystem cache is used in `dev`)

### Install

```powershell
cd C:\Sympony
composer install
copy .env.example .env
php bin/console cache:clear
```

---

## How to test

### Easiest — helper script

```powershell
.\scripts\test-api.ps1
```

### Browser

Open directly:

```
http://127.0.0.1:8000/properties
http://127.0.0.1:8000/properties?page=1&limit=5
```

### PowerShell

```powershell
Invoke-RestMethod -Uri "http://127.0.0.1:8000/properties?page=1&limit=5"
```

### What to check

| Test | Expected result |
|------|-----------------|
| `/properties` | JSON with `data` and `meta` |
| First request | `"cached": false` |
| Second request (same data files) | `"cached": true` |
| `?page=1&limit=5` | 5 items, `meta.total = 10` |
| Invalid JSON record in source file | Skipped silently, other records still returned |

---

## API reference

### GET /properties

**Query parameters:**

| Parameter | Default | Description |
|-----------|---------|-------------|
| `page` | `1` | Page number |
| `limit` | `20` | Items per page (max 100) |

**Example response:**

```json
{
  "data": [
    {
      "id": "EH-1001",
      "address": "42 Oak Avenue, Manchester, M1 2AB",
      "price": 285000,
      "source": "source_a"
    }
  ],
  "meta": {
    "page": 1,
    "limit": 5,
    "total": 10,
    "total_pages": 2,
    "cached": true,
    "sources_loaded": ["source_a", "source_b"]
  }
}
```

If one or more sources fail, the response includes an `errors` array.

---

## Project structure

```
public/data/source_a.json
public/data/source_b.json
src/Controller/PropertyController.php
src/Service/PropertyAggregatorService.php
scripts/start-server.ps1
scripts/test-api.ps1
scripts/run-tests.ps1
config/packages/cache.yaml
docker-compose.yml
tests/
```

---

## Caching

| Environment | Adapter | Notes |
|-------------|---------|-------|
| `dev` | Filesystem | Works without Redis |
| `prod` | Redis | Run `docker compose up -d` |

```env
PROPERTIES_CACHE_TTL=300
REDIS_URL=redis://127.0.0.1:6379
```

- Cache key: `properties_aggregated_v1`
- Cache-first when source files are unchanged
- Invalidates when file size or modification time changes
- Falls back to stale cache if sources fail

---

## Error handling

| Scenario | Behavior |
|----------|----------|
| Source files unchanged | Served from cache, `meta.cached = true` |
| Source files modified | Cache invalidated, data reloaded |
| Missing source file | Error recorded, other source still processed |
| Corrupted JSON | Error recorded, file skipped |
| Invalid record | Record skipped, processing continues |
| All sources fail + cache exists | Stale cache returned |
| All sources fail + no cache | Empty `data` with errors |

---

## Automated tests

```powershell
php vendor\bin\phpunit
```

**10 tests** covering merge, normalization, cache, and pagination.

---

## Troubleshooting

| Problem | Fix |
|---------|-----|
| `Unable to connect` | Start server: `.\scripts\start-server.ps1` |
| `composer` not found | Install Composer and reopen terminal |
| Wrong folder | Use `C:\Sympony` |
| Cache always `false` | Run `php bin/console cache:clear`, hit endpoint twice |
| PowerShell `curl` fails | Use `.\scripts\test-api.ps1` or `Invoke-RestMethod` |

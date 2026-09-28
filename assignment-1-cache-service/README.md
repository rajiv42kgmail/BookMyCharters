# Assignment 1: Cache Service

A small Yii2 service that exposes products and category listings, cached with
Yii FileCache only. This folder contains my fix for the stale-cache bug and a
small reusable cache invalidation layer.

## Requirements

- PHP 8.0 or higher
- Composer
- MySQL, with a database named `bmc_cache`

Database settings are in `config/db.php` (default: user `root`, empty password,
host `127.0.0.1`). Change them if yours differ.

## Setup

```bash
# 1. Install dependencies
composer install

# 2. Create the database
mysql -u root -e "CREATE DATABASE IF NOT EXISTS bmc_cache"

# 3. Create tables and seed data
php yii migrate
```

## Run the service

```bash
php yii serve
```

The service runs at http://localhost:8080.

Endpoints:

| Method | URL | Purpose |
|--------|-----|---------|
| GET | `/products/{id}` | Product details (cached) |
| PUT | `/products/{id}` | Update a product (JSON body) |
| GET | `/categories/{id}/products` | Products in a category (cached) |

## Run the regression test

With the service running, in a second terminal:

```bash
bash tests/regression.sh
```

Expected output: `PASS`. The test warms the category listing cache, edits a
product, then checks that the category listing shows the edit. This is the
scenario that failed before the fix.

## What changed

- `services/EntityCache.php` owns all cache keys and invalidation rules. Each
  entity type has one entry in a rules list that says which cache keys are
  affected when it changes. Entries expire after 1 hour as a safety net only.
- `models/Product.php` triggers invalidation in `afterSave` and `afterDelete`,
  so every write path clears the cache, including a product moving between
  categories (both the old and new category listings are cleared).
- Controllers only read and write cached responses. They contain no
  invalidation logic.
- `tests/regression.sh` is the test that would have caught the original bug.

## More detail

See `DECISIONS.md` for the reproduction steps, the design choices and rejected
alternatives, the response to the product manager's 24-hour expiry suggestion,
and what I deliberately did not do.
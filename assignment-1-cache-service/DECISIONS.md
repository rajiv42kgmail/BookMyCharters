# Assignment 1 — Decisions

## Original bug and exact reproduction

The stale data is caused by two independent cache representations of the same product:

- `GET /products/{id}` uses `product:{id}`.
- `GET /categories/{id}/products` uses `category:{id}:products`.

The update action deletes only the first key.

### Reproduction

Starting from the seeded database:

```bash
# 1. Populate the category listing cache.
curl http://localhost:8080/categories/1/products

# 2. Update product 1.
curl -X PUT http://localhost:8080/products/1 \
  -H "Content-Type: application/json" \
  -d '{"name":"Airbus H125 (2024)","price":47000}'

# 3. The product endpoint reflects the update.
curl http://localhost:8080/products/1

# 4. The category endpoint still returns the old product data.
curl http://localhost:8080/categories/1/products
```

The sequence is important: the category endpoint must be called first so its cached response exists before the product is updated.

## Design choices

### 1. Centralise cache access and invalidation

I introduced `app\services\EntityCache`.

Controllers now ask the service for cached data and ask it to invalidate an entity. Cache key construction and dependency knowledge are therefore not embedded in controller actions.

EntityCache uses a rules list, so a new entity type is one new entry rather than editing a switch.

**Alternative rejected:** keep the existing `Yii::$app->cache->get/set/delete()` calls in controllers.

**Reason:** it would fix today's bug but make every future entity/list projection responsible for remembering its own cache dependencies. That is exactly the class of correctness problem this assignment exposes.

### 2. Use `invalidateEntity()` rather than TTL as the correctness mechanism

`invalidateEntity('product', $id, $categoryIds)` deletes both the product detail cache and affected category listing caches.

**Alternative rejected:** set a 24-hour expiry on every entry, as suggested in the product-manager note.

**Reason:** expiry only bounds how long stale data can remain; it does not provide immediate correctness after an edit. A product could still be stale for almost 24 hours. The reported bug is specifically a correctness problem, so invalidation is the appropriate mechanism.

TTL could still be useful as a defensive upper bound in a larger system, but it is not the primary fix here.

### 3. Invalidate both old and new categories

The update records the product's old `category_id` before saving, then invalidates both the old and new category IDs.

**Alternative rejected:** invalidate only the current/new category.

**Reason:** if `category_id` changes, the old category listing also changes because the product has disappeared from it. Invalidating both is small and prevents a second form of stale data.

### 4. Keep the abstraction deliberately small

The service supports the cache projections present in this assignment and exposes one entity-oriented invalidation entry point.

**Alternative rejected:** I put invalidation in Product::afterSave/afterDelete so every write path is covered, not just one controller. The cost is that the model knows about the cache service. I accepted that because a missed invalidation is a correctness bug, while the coupling is only a design preference.

**Reason:**  

## Response to the product manager

I would not implement the proposed 24-hour expiry as the fix.

The problem is not that cached data eventually becomes old; the problem is that an explicit product mutation does not invalidate a dependent cached projection. A 24-hour TTL would make the symptom less permanent but would still allow customer-facing stale data after an edit.

The correct small change is explicit invalidation of all projections affected by the mutation. A TTL could be added later as a defensive maximum lifetime if there is a separate reason for it.

I did add a 1-hour TTL, but only as a safety net for race conditions. It is not the fix, because a 1-hour or 24-hour delay would still show wrong prices after an edit.

## What I deliberately did not do, and why

- **Did not add Redis/Memcached:** explicitly outside the assignment's constraints.
- **Did not add a third-party cache library:** unnecessary for Yii FileCache.
- **Did not add a 24-hour TTL as the primary fix:** does not guarantee immediate consistency.
- **Did not build a generic cache-tag framework:** disproportionate to the small service.
- **Did not change database schema:** the existing schema already provides the relationship needed to identify affected category listings.
- **Did not cache the update response separately:** the existing product detail cache is repopulated naturally on the next GET.

## Test that matters most

The highest-value test is:

1. Populate `category:1:products`.
2. Update product 1.
3. Request category 1 again.
4. Assert the response contains the updated product.

That test directly fails with the starter implementation and passes only when the dependent category cache is invalidated.

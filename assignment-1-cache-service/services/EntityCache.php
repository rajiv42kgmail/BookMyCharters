<?php

declare(strict_types=1);

namespace app\services;

use Yii;
use yii\caching\CacheInterface;

/**
 * Keeps cache keys and invalidation rules out of controllers.
 *
 * The abstraction is intentionally small: each entity type owns the keys
 * that represent it and any known dependent projections.
 */
class EntityCache
{
    private CacheInterface $cache;
    private const TTL = 3600; // safety net: 1 hour

    private array $rules;

    public function __construct(?CacheInterface $cache = null)
    {
             $this->cache = $cache ?? Yii::$app->cache;

            // One entry per entity type: "when this changes, which cache keys are affected?"
            $this->rules = [
                'product' => fn(int $id, array $ctx): array => array_merge(
                    [$this->productKey($id)],
                    array_map(
                        fn($categoryId) => $this->categoryProductsKey((int) $categoryId),
                        $ctx['categoryIds'] ?? []
                    )
                ),
            ];
    }

    public function getProduct(int $id): mixed
    {
        return $this->cache->get($this->productKey($id));
    }

    public function setProduct(int $id, array $data): bool
    {
        return $this->cache->set($this->productKey($id), $data, self::TTL);
    }

    public function getCategoryProducts(int $categoryId): mixed
    {
        return $this->cache->get($this->categoryProductsKey($categoryId));
    }

    public function setCategoryProducts(int $categoryId, array $data): bool
    {
        return $this->cache->set($this->categoryProductsKey($categoryId), $data,  self::TTL);
    }

    /**
     * Invalidates all cached projections affected by an entity change.
     *
     * For a product, the product detail and its category listing are both
     * affected. Additional related category IDs can be supplied when an
     * update moves a product between categories.
     */
   public function invalidateEntity(string $entityType, int $id, array $context = []): void
{
    if (!isset($this->rules[$entityType])) {
        throw new \InvalidArgumentException("Unsupported cache entity: {$entityType}");
    }

    foreach ($this->rules[$entityType]($id, $context) as $key) {
        $this->cache->delete($key);
    }
}

    private function productKey(int $id): string
    {
        return 'product:' . $id;
    }

    private function categoryProductsKey(int $categoryId): string
    {
        return 'category:' . $categoryId . ':products';
    }
}

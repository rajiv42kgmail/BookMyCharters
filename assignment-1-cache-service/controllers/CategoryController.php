<?php

declare(strict_types=1);

namespace app\controllers;

use app\models\Category;
use app\models\Product;
use app\services\EntityCache;
use yii\web\Controller;
use yii\web\NotFoundHttpException;

/**
 * Categories API.
 */
class CategoryController extends Controller
{
    public $enableCsrfValidation = false;

    private EntityCache $entityCache;

    public function __construct($id, $module, EntityCache $entityCache, $config = [])
    {
        $this->entityCache = $entityCache;
        parent::__construct($id, $module, $config);
    }

    /**
     * GET /categories/{id}/products
     */
    public function actionProducts(int $id): array
    {
        $category = Category::findOne($id);
        if ($category === null) {
            throw new NotFoundHttpException('Category not found.');
        }

        $data = $this->entityCache->getCategoryProducts($id);
        if ($data !== false) {
            return $data;
        }

        $products = Product::find()
            ->where(['category_id' => $id])
            ->orderBy(['id' => SORT_ASC])
            ->all();

        $data = array_map(static fn(Product $p): array => $p->toArray(), $products);
        $this->entityCache->setCategoryProducts($id, $data);

        return $data;
    }
}

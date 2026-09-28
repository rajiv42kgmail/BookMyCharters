<?php

declare(strict_types=1);

namespace app\controllers;

use app\models\Product;
use app\services\EntityCache;
use Yii;
use yii\web\Controller;
use yii\web\NotFoundHttpException;

/**
 * Products API.
 */
class ProductController extends Controller
{
    public $enableCsrfValidation = false;

    private EntityCache $entityCache;

    public function __construct($id, $module, EntityCache $entityCache, $config = [])
    {
        $this->entityCache = $entityCache;
        parent::__construct($id, $module, $config);
    }

    /**
     * GET /products/{id}
     */
    public function actionView(int $id): array
    {
        $data = $this->entityCache->getProduct($id);
        if ($data !== false) {
            return $data;
        }

        $product = Product::findOne($id);
        if ($product === null) {
            throw new NotFoundHttpException('Product not found.');
        }

        $data = $product->toArray();
        $this->entityCache->setProduct($id, $data);

        return $data;
    }

    /**
     * PUT /products/{id}
     */
    public function actionUpdate(int $id): array
    {
        $product = Product::findOne($id);
        if ($product === null) {
            throw new NotFoundHttpException('Product not found.');
        }

        // Keep the old relationship so a category listing is also invalidated
        // if the product is moved to another category.
       // $oldCategoryId = (int) $product->category_id;

        $product->load(Yii::$app->request->getBodyParams(), '');

        if (!$product->save()) {
            Yii::$app->response->statusCode = 422;
            return ['errors' => $product->getErrors()];
        }

      //  $newCategoryId = (int) $product->category_id;
    //    $categoryIds = array_values(array_unique([$oldCategoryId, $newCategoryId]));

      //  $this->entityCache->invalidateEntity('product', $id, $categoryIds);

        return $product->toArray();
    }
}

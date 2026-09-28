<?php

declare(strict_types=1);

namespace app\models;

use yii\db\ActiveRecord;
use yii\db\ActiveQuery;
use Yii;
use app\services\EntityCache;

/**
 * @property int $id
 * @property string $name
 * @property int $category_id
 * @property string $price
 * @property string|null $description
 */
class Product extends ActiveRecord
{
    public static function tableName(): string
    {
        return 'product';
    }

    public function rules(): array
    {
        return [
            [['name', 'category_id', 'price'], 'required'],
            [['category_id'], 'integer'],
            [['price'], 'number', 'min' => 0],
            [['name'], 'string', 'max' => 255],
            [['description'], 'string'],
            [['category_id'], 'exist', 'targetClass' => Category::class, 'targetAttribute' => 'id'],
        ];
    }

    public function getCategory(): ActiveQuery
    {
        return $this->hasOne(Category::class, ['id' => 'category_id']);
    }

    /**
     * Fields returned by toArray() / the JSON responses.
     */
    public function fields(): array
    {
        return ['id', 'name', 'category_id', 'price', 'description'];
    }
    public function afterSave($insert, $changedAttributes)
    {
        parent::afterSave($insert, $changedAttributes);

        // The category the product is in now
        $categoryIds = [(int) $this->category_id];

        // If it moved, the OLD category's list changed too
        if (array_key_exists('category_id', $changedAttributes)) {
            $categoryIds[] = (int) $changedAttributes['category_id'];
        }

        Yii::$container->get(EntityCache::class)
            ->invalidateEntity('product', (int) $this->id, ['categoryIds' => array_unique($categoryIds)]);
    }

    public function afterDelete()
    {
        parent::afterDelete();

        Yii::$container->get(EntityCache::class)
            ->invalidateEntity('product', (int) $this->id, ['categoryIds' => [(int) $this->category_id]]);
    }
}

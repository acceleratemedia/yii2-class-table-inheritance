<?php

namespace bvb\cti;

use yii\base\Model;
use yii\helpers\ArrayHelper;

/**
 * Convenient functions and reusable functionality for search models
 */
trait CtiSearchModelTrait
{
    /**
     * {@inheritdoc}
     */
    public function scenarios()
    {
        // bypass scenarios() implementation in the parent class
        return Model::scenarios();
    }

    /**
     * Creates data provider instance with search query applied
     * Classes using this trait can implement a function called searchAdjustments
     * which will allow them to manipulate the returned ActiveDataProvider
     * which will also give them access to the query to make any adjustments there
     *
     * @param array $params
     * @return ActiveDataProvider
     */
    public function search($params)
    {
        $activeDataProvider = $this->getParentSearchModelClass()::instance()->search($params);

        // --- Set the modelClass for the query to be the one implementing this trait
        $activeDataProvider->query->modelClass = static::class;

        if(method_exists($this, 'searchAdjustments')){
            $activeDataProvider = $this->searchAdjustments($params, $activeDataProvider);
        }

        return $activeDataProvider;
    }
}

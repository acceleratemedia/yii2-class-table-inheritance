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
    public function rules()
    {
        $rules = $this->getParentSearchModelClass()::instance()::rules();

        if(method_exists($this, 'ruleAdjustments')){
            $rules = ArrayHelper::merge($rules, $this->ruleAdjustments());
        }

        return $rules;
    }

    /**
     * @inheritdoc
     */
    public function scenarios()
    {
        // bypass scenarios() implementation in the parent class
        return Model::scenarios();
    }

    /**
     * Creates data provider instance with search query applied
     *
     * @param array $params
     *
     * @return ActiveDataProvider
     */
    public function search($params)
    {
        $activeDataProvider = $this->getParentSearchModelClass()::instance()->search($params);

        if(method_exists($this, 'ruleAdjustments')){
            $activeDataProvider = $this->ruleAdjustments($params, $activeDataProvider);
        }

        return $activeDataProvider;
    }
}

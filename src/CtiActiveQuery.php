<?php

namespace bvb\cti;

use yii\db\ActiveQuery;

class CtiActiveQuery extends ActiveQuery
{
    /**
     * Automatically inner join with the parent relation. This should only be 
     * used on CtiActiveRecord classes and that relation is auto-defined
     * {@inheritdoc}
     */
    public function init()
    {
        parent::init();
        $this->innerJoinWith(['parentRelation']);
    }

    /**
     * {@inheritdoc}
     */
    public function populate($rows)
    {
        $models = parent::populate($rows);

        // --- If the query is asArray apply the attributes we want to inherit from the parent
        // --- to the modelclass using CtiActiveQuery
        if ($this->asArray) {
            foreach($models as &$model){
                // --- Check the parent relation is set. One scenario where it may not be is 
                // --- when the UniqueValidator runs and uses asArray but doesn't load relations
                // --- because it doesn't need them to check for uniqueness
                if(isset($model['parentRelation'])){
                    foreach($model['parentRelation'] as $attribute => $value){
                        if(in_array($attribute, $this->modelClass::instance()->parentAttributesInherited())){
                            $model[$attribute] = $value;
                        }
                    }                    
                }
            }
        }
        
        return $models;
    }
}

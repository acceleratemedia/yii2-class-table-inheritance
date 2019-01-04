<?php

namespace bvb\cti;

use yii\db\ActiveQuery;

class ChildActiveQuery extends ActiveQuery
{
    /**
     * {@inheritdoc}
     */
    public function populate($rows)
    {
        $models = parent::populate($rows);

        // --- If the query is asArray apply the attributes we want to inherit from the parent
        // --- to the modelclass using ChildActiveQuery
        if ($this->asArray) {
            foreach($models as &$model){
                foreach($model['parentRelation'] as $attribute => $value){
                    if(in_array($attribute, $this->modelClass::$attributes_to_inherit)){
                        $model[$attribute] = $value;
                    }
                }
            }
        }
        
        return parent::populate($models);
    }
}

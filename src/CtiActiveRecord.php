<?php

namespace bvb\cti;

use ArrayObject;
use ReflectionProperty;
use yii\base\InvalidArgumentException;
use yii\base\InvalidConfigException;
use yii\base\UnknownMethodException;
use yii\base\UnknownPropertyException;
use yii\db\ActiveRecord;
use yii\helpers\ArrayHelper;
use yii\validators\Validator;

/**
 * Meant to allow us to use an ActiveRecord model as a child of another
 * ActiveRecord model by accessing all of the properties of the parent
 * on the child
 */
class CtiActiveRecord extends ActiveRecord
{
    /**
     * Parent classname
     * @var string
     */
    protected $parentClass;

    /**
     * Attribute belonging to the table that this model represents
     * @var array
     */
    protected $ownAttributes;

    /**
     * Attribute name on the extending class that is a foreign key to $parentClass
     * @var string
     */
    protected $foreignKeyField;

    /**
     * Contains the model of the parent class
     * @var mixed
     */
    protected $_parent_model;

    /**
     * Just need this because it's private and updateInternal uses it
     * {@inheritdoc}
     */
    private $_oldAttributes;

    /**
     * Make sure that the
     * {@inheritdoc}
     */
    public function init()
    {
        if($this->parentClass === null){
            throw new InvalidConfigException('Classes extending from CtiActiveRecord must declare a property `parentClass` which should be the classname of the ActiveRecord class that '.static::class.' considered a child of');
        }
        if($this->foreignKeyField === null){
            throw new InvalidConfigException('Classes implementing '.self::class.' must declare a property `foreignKeyField` whose value is a string of the foreign key to the table that represents the parent model/class/object of '.static::class);
        }
        if($this->parentAttributesInherited() === null){
            throw new InvalidConfigException('Classes implementing '.self::class.' must declare a static property `parentAttributesInherited` whose value is a array of attributes from the parent model/class/table that should are intended to be inherited by  '.static::class);
        }

        // --- Sets our attribute defaults so they will pass up the parent if needed
        // --- Set them this way rather than mass assigning attributes because mass
        // --- assignment will check for safe attributes, creating validators before
        // --- the record has initialized, which will call getting the parent model
        // --- and we will always have an empty parent model
        foreach($this->parentAttributeDefaults() as $attributeName => $attributeValue){
            $this->{$attributeName} = $attributeValue;
        }
        parent::init();
    }

    /**
     * Extend the default functionality of the getter to get the properties of the parent Content model
     * @{inheritdoc}
     */
    public function __get($name)
    {
        try{
            return parent::__get($name);   
        } catch(UnknownPropertyException $e){
            try{
                return $this->getParentModel()->{$name};
            } catch(UnknownPropertyException $f){
                throw $e;
            }
        }
    }

    /**
     * Extend the default functionality of the getter to get the properties of the parent Content model
     * @{inheritdoc}
     */
    public function canGetProperty($name, $checkVars = true, $checkBehaviors = true)
    {
        if(!parent::canGetProperty($name, $checkVars)){
            return $this->getParentModel()->canGetProperty($name, $checkVars, $checkBehaviors);
        }
        return true;
    }

    /**
     * Extend the default functionality of the setter to get the properties of the parent Content model
     * @{inheritdoc}
     */
    public function __set($name, $value)
    {
        try{
            parent::__set($name, $value);   
        } catch(UnknownPropertyException $e){
            try{
               $this->getParentModel()->{$name} = $value;
            } catch(UnknownPropertyException $f){
                throw $e;
            }
        }
    }

    /**
     * Extend the default functionality of the setter to get the properties of the parent Content model
     * @{inheritdoc}
     */
    public function canSetProperty($name, $checkVars = true, $checkBehaviors = true)
    {
        if(!parent::canSetProperty($name, $checkVars, $checkBehaviors)){
            return $this->getParentModel()->canSetProperty($name, $checkVars, $checkBehaviors);
        }
        return true;
    }

    /**
     * Works in tandem with hasMethod() to be able to call functions on the 'parent' from the 'child'
     * {@inheritdoc}
     */
    public function __call($name, $params)
    {
        try{
            parent::__call($name, $params);
        }catch(UnknownMethodException $e){
            call_user_func_array([$this->getParentModel(), $name], $params);
        }
    }

    /**
     * Override this function to add methods from parent class to this one
     * This helps with inline validation functions in rules() from the parent class
     * being inherited and able to be run on the child class
     * {@inheritdoc}
     */
    public function hasMethod($name, $checkBehaviors = true)
    {
        if(!parent::hasMethod($name, $checkBehaviors)){
            return $this->getParentModel()->hasMethod($name, $checkBehaviors);
        }
    }

    /**
     * Attributes which we do not want to apply to the child from the parent
     * @return array
     */
    public function parentAttributesIgnored()
    {
        return [];
    }

    /**
     * Attributes which we want applied to the child model from the parent
     * This must be static because we must be able to access it as a class-level
     * property when populating models using asArray in CtiActiveQuery
     * @return array
     */
    public function parentAttributesInherited()
    {
        return [];
    }

    /**
     * A list of default values we want applied to the parent model
     * @return array
     */
    public function parentAttributeDefaults()
    {
        return [];
    }


    /**
     * Mimics the ActiveRecord relations and adds one for the owner to their parent model
     * @return \yii\db\ActiveQuery
     */
    public function getParentRelation()
    {
        return $this->hasOne($this->parentClass, ['id' => $this->foreignKeyField]);
    }

    /**
     * Returns an instance of the parent model. This will either return a new instance or it will
     * return the one on the parent relation. This is critical to the working of assigning properties
     * between the parent/child relationship since we can't use the relation itself because it will
     * return null on new models but on those new models we need the parent since we will
     * be creating it as a prerequisite for saving the child model
     * @return \yii\db\ActiveRecord
     */
    public function getParentModel()
    {
        if(empty($this->_parent_model)){
            if($this->isNewRecord){
                if(!empty($this->parentAttributeDefaults())){
                    $this->_parent_model = new $this->parentClass($this->parentAttributeDefaults());
                } else {
                    $this->_parent_model = new $this->parentClass;
                }
            } else {
                return $this->parentRelation;
            }
        }

        return $this->_parent_model;
    }

    /**
     * Use our CtiActiveQuery class to make sure that when forming an array from result
     * we will include certain parent table attributes in the result for this (child) result
     * Also, always inner join with the parent table to get that data
     * {@inheritdoc}
     */
    public static function find() {
        $query = new CtiActiveQuery(get_called_class());
        return $query->innerJoinWith(['parentRelation']);
    }

    /**
     * After we do the find, apply all of the parent attributes to inherit to the current model
     * {@inheritdoc}
     */
    public function afterFind() {
        parent::afterFind();
        foreach($this->parentRelation->attributes as $attributeName => $attributeValue){
            if(in_array($attributeName, $this->parentAttributesInherited())){
                $this->{$attributeName} = $attributeValue;
            }
        }
    }

    /**
     * Extend the default functionality of this to add our parentRelation in there. Ultimately,
     * this allows us use the 'with' functionality of ActiveQueries to join with the parent table/class
     * without explicitly declaring that relationship. Also, this will check for a relation
     * on the parent model and create a new relation for this model onto that one 'via'
     * the parentRelation
     * {@inheritdoc}
     */
    public function getRelation($name, $throwException = true)
    {
        try{
            return parent::getRelation($name);   
        } catch(InvalidArgumentException $e){
            // --- First try to see if they are getting the 'parent' model using a magic relation name
            $reflect = new \ReflectionClass($this->parentClass);
            $magic_relation_name = lcfirst($reflect->getShortName());
            if($name == $magic_relation_name){
                return $this->getParentRelation();
            }
            if(method_exists($this->getParentModel(), 'get'.ucFirst($name))){
                $parentRelation = $this->getParentModel()->getRelation($name);
                $parentRelation->primaryModel = $this;
                return $parentRelation->via('parentRelation');
            }
            throw $e;
        }
    }

    /**
     * Overridden to get attribute labels from parent as well
     * {@inheritdoc}
     */
    public function getAttributeLabel($attribute)
    {
        $parent_labels = $this->getParentModel()->attributeLabels();
        $labels = array_merge($parent_labels, $this->attributeLabels());
        return isset($labels[$attribute]) ? $labels[$attribute] : $this->generateAttributeLabel($attribute);
    }

    /**
     * Overridden to get attribute hints from parent as well
     * {@inheritdoc}
     */
    public function getAttributeHint($attribute)
    {
        $parent_hints = $this->getParentModel()->attributeHints();
        $hints = array_merge($parent_hints, $this->attributeHints());
        return isset($hints[$attribute]) ? $hints[$attribute] : '';
    }

    /**
     * Add all of the parent model validation rules. Uses ArrayHelper::merge() so that if a user
     * keys their rules in the parent model they can then unset and alter them in the child model
     * {@inheritdoc}
     */
    public function createValidators()
    {
        $validators = new ArrayObject();
        $parentRules = $this->getParentModel()->rules();
        foreach($parentRules as $i => $parentRule){
            if(
                // --- Ignore inline validators! Running it once on the parentModel is enough
                method_exists($this->getParentModel(), $parentRule[1]) || 
                $parentRule[1] == \bvb\content\backend\validators\InheritanceUniqueValidator::class
            ){
                unset($parentRules[$i]);
            }
        }

        $rules = ArrayHelper::merge(
            $parentRules,
            $this->rules()
        );

        foreach ($rules as $rule) {
            if ($rule instanceof Validator) {
                $validators->append($rule);
            } elseif (is_array($rule) && isset($rule[0], $rule[1])) { // attributes, validator type
                $validator = Validator::createValidator($rule[1], $this, (array) $rule[0], array_slice($rule, 2));
                $validators->append($validator);
            } else {
                throw new InvalidConfigException('Invalid validation rule: a rule must specify both attribute names and validator type.');
            }
        }
        return $validators;
    }

    /**
     * MAke sure to save or update the parent model before doing this one
     * {@inheritdoc}
     */
    public function beforeSave($insert)
    {
        if(!parent::beforeSave($insert)){
            return false;
        }

        // --- Loop through all attributes on a Parent model and apply the child's values to the Parent model for saving
        foreach($this->getParentModel()->attributes as $attribute_name => $value){
            if(
                in_array($attribute_name, $this->parentAttributesIgnored()) ||
                in_array($attribute_name, $this->getOwnAttributes()) ||
                !in_array($attribute_name, $this->parentAttributesInherited() )
            ){
                // --- ignore variables we don't want to apply to the class using this behavior
                continue;
            }
            $this->getParentModel()->{$attribute_name} = $this->{$attribute_name};
        }

        // --- We need to do this for other class properties that aren't yii magic attributes and are actual member vairables
        foreach(get_class_vars($this->parentClass) as $attribute_name => $value){
            // --- Make sure the property isn't static
            $property = new ReflectionProperty($this->parentClass, $attribute_name);
            if(
                in_array($attribute_name, $this->parentAttributesIgnored()) || 
                in_array($attribute_name, $this->getOwnAttributes()) ||
                !in_array($attribute_name, $this->parentAttributesInherited() ) ||
                $property->isStatic()
            ){
                // --- ignore variables we don't want to apply to the class using this behavior
                continue;
            }
            $this->getParentModel()->{$attribute_name} = $this->{$attribute_name};
        }
        if(!$this->getParentModel()->save()){
            // --- Apply any validation errors that may be on the Parent the child model model just in case
            foreach($this->getParentModel()->getErrors() as $attribute_name => $errors){
                foreach($errors as  $error){
                    $this->addError($attribute_name, $error);    
                }
            }
            return false;
        } else {
            // --- Sets the foreign key field for this model to the id of the saved parent
            $this->{$this->foreignKeyField} = $this->getParentModel()->id;
        }

        return true;
    }

    /**
     * Also delete the parent model
     * {@inheritdoc}
     */
    public function afterDelete()
    {
        parent::afterDelete();
        $this->parentRelation->delete();
    }

    /**
     * Include attribute of the parent database table as well as the attributes
     * for the current model
     * {@inheritdoc}
     */
    public function attributes()
    {
        return array_merge(parent::attributes(), array_keys($this->parentClass::getTableSchema()->columns));
    }

    /**
     * Exact function as in parent but does not include attribtues from parent
     * model that are in that table in the insert statement
     * {@inheritdoc}
     */
    protected function insertInternal($attributes = null)
    {
        if (!$this->beforeSave(true)) {
            return false;
        }
        $values = $this->getDirtyAttributes($attributes);
        /** Start our edits */
        foreach($values as $attributeName => $attributeValue){
            if(!in_array($attributeName, $this->getOwnAttributes())){
                unset($values[$attributeName]);
            }
        }
        /** End our edits */
        if (($primaryKeys = static::getDb()->schema->insert(static::tableName(), $values)) === false) {
            return false;
        }
        foreach ($primaryKeys as $name => $value) {
            $id = static::getTableSchema()->columns[$name]->phpTypecast($value);
            $this->setAttribute($name, $id);
            $values[$name] = $id;
        }

        $changedAttributes = array_fill_keys(array_keys($values), null);
        $this->setOldAttributes($values);
        $this->afterSave(true, $changedAttributes);

        return true;
    }

    /**
     * Exact function as in parent but does not include attribtues from parent
     * model that are in that table in the update statement
     * {@inheritdoc}
     */
    protected function updateInternal($attributes = null)
    {
        if (!$this->beforeSave(false)) {
            return false;
        }
        $values = $this->getDirtyAttributes($attributes);
        /** Start our edits */
        foreach($values as $attributeName => $attributeValue){
            if(!in_array($attributeName, $this->getOwnAttributes())){
                unset($values[$attributeName]);
            }
        }
        /** End our edits */
        if (empty($values)) {
            $this->afterSave(false, $values);
            return 0;
        }
        $condition = $this->getOldPrimaryKey(true);
        $lock = $this->optimisticLock();
        if ($lock !== null) {
            $values[$lock] = $this->$lock + 1;
            $condition[$lock] = $this->$lock;
        }
        // We do not check the return value of updateAll() because it's possible
        // that the UPDATE statement doesn't change anything and thus returns 0.
        $rows = static::updateAll($values, $condition);
        if ($lock !== null && !$rows) {
            throw new StaleObjectException('The object being updated is outdated.');
        }
        if (isset($values[$lock])) {
            $this->$lock = $values[$lock];
        }
        $changedAttributes = [];
        foreach ($values as $name => $value) {
            $changedAttributes[$name] = isset($this->_oldAttributes[$name]) ? $this->_oldAttributes[$name] : null;
            $this->_oldAttributes[$name] = $value;
        }
        $this->afterSave(false, $changedAttributes);
        return $rows;
    }

    /**
     * Gets the list of attributes which belong to only this model from the
     * database table for it (not the parent)
     * @return array
     */
    public function getOwnAttributes()
    {
        if(empty($this->ownAttributes)){
            $this->ownAttributes = array_keys(static::getTableSchema()->columns);    
        }
        return $this->ownAttributes;
    }
}
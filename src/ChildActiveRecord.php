<?php

namespace common\db;

use yii\base\InvalidArgumentException;
use yii\base\InvalidConfigException;
use yii\base\UnknownPropertyException;
use yii\db\ActiveRecord;

/**
 * Meant to allow us to use an ActiveRecord model as a child of another
 * ActiveRecord model by accessing all of the properties of the parent
 * on the child
 */
class ChildActiveRecord extends ActiveRecord
{
    /**
     * Parent classname
     * @var string
     */
    protected $parent_class;

    /**
     * Gives default attributes values to the parent model. Key is the name of the attribute 
     * on the parent model and the value is the desired default value
     * @var array
     */
    static $attributes_to_inherit;

    /**
     * Name of the field on the attached model that is a foreign key to the parent record
     * @var string
     */
    protected $foreign_key_field;

    /**
     * Shared attribtues is a list of attributes shared between the parent and child
     * that we do not want to overwrite on the child model when applying parent
     * properties to the child . This is mainly for when using asArray in db queries
     * since by default magic methods will return the correct property
     * @var array
     */
    protected $shared_attributes;

    /**
     * Contains the model of the parent class
     * @var mixed
     */
    protected $_parent_model;

    /**
     * Make sure that the
     * {@inheritdoc}
     */
    public function init()
    {
        if($this->parent_class === null){
            throw new InvalidConfigException('Classes extending from ChildActiveRecord must declare a property `parent_class` which should be the classname of the ActiveRecord class that '.static::class.' considered a child of');
        }
        if($this->foreign_key_field === null){
            throw new InvalidConfigException('Classes implementing '.self::class.' must declare a property `foreign_key_field` whose value is a string of the foreign key to the table that represents the parent model/class/object of '.static::class);
        }
        if(static::$attributes_to_inherit === null){
            throw new InvalidConfigException('Classes implementing '.self::class.' must declare a static property `attributes_to_inherit` whose value is a array of attributes from the parent model/class/table that should be added to the array from query results for  '.static::class);
        }
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
            return $this->getParentModel()->{$name};
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
            $this->getParentModel()->{$name} = $value;
        }
    }

    /**
     * Extend the default functionality of the setter to get the properties of the parent Content model
     * @{inheritdoc}
     */
    public function canSetProperty($name, $checkVars = true, $checkBehaviors = true)
    {
        if(!parent::canSetProperty($name, $checkVars)){
            return $this->getParentModel()->canSetProperty($name, $checkVars, $checkBehaviors);
        }
        return true;
    }

    /**
     * Mimics the ActiveRecord relations and adds one for the owner to their parent model
     * @return \yii\db\ActiveQuery
     */
    public function getParentRelation()
    {
        return $this->hasOne($this->parent_class, ['id' => $this->foreign_key_field]);
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
                if(isset($this->parent_attribute_defaults)){
                    $this->_parent_model = new $this->parent_class($this->parent_attribute_defaults);
                } else {
                    $this->_parent_model = new $this->parent_class;
                }
            } else {
                $this->_parent_model = $this->parentRelation;
            }
        }

        return $this->_parent_model;
    }

    /**
     * Use our ChildActiveQuery class to make sure that when forming an array from result
     * we will include certain parent table attributes in the result for this (child) result
     * Also, always inner join with the parent table to get that data
     * {@inheritdoc}
     */
    public static function find() {
        $query = new ChildActiveQuery(get_called_class());
        return $query->innerJoinWith(['parentRelation']);
    }

    /**
     * Extend the default functionality of this to add our parentRelation in there. Ultimately,
     * this allows us use the 'with' functionality of ActiveQueries to join with the parent table/class
     * without explicitly declaring that relationship
     * {@inheritdoc}
     */
    public function getRelation($name, $throwException = true)
    {
        try{
            return parent::getRelation($name);   
        } catch(InvalidArgumentException $e){
            // --- First try to see if they are getting the 'parent' model using a magic relation name
            $reflect = new \ReflectionClass($this->parent_class);
            $magic_relation_name = lcfirst($reflect->getShortName());
            if($name == $magic_relation_name){
                return $this->getParentRelation();
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
     * Implement our own custom implementation of rules() where the child and parent class
     * must now put their rules in a static class called validationRules() and we will
     * apply all of them to the child model. There are some rules that are exceptions we don't
     * want to apply to the child class such as the UNIQUE validator so we have to find and 
     * account for those
     * {@inheritdoc}
     */
    public function rules()
    {
        $rules = method_exists($this, 'validationRules') ? $this->validationRules() : [];
        $parent_rules = method_exists($this->getParentModel(), 'validationRules') ? $this->getParentModel()->validationRules() : [];
        foreach($parent_rules as $rule_array){
            if($rule_array[1] != 'unique'){
                $rules[] = $rule_array;
            }
        }
        return $rules;
    }

    /**
     * Empty implementation of this function so we make sure that it is set on the 
     * classes extending from this one
     * @return array
     */
    static function validationRules()
    {
        return [];
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

        // --- Loop through all attributes on a Parent model and apply the owner's values to the Parent model for saving
        foreach($this->getParentModel()->attributes as $attribute_name => $value){
            if(in_array($attribute_name, $this->shared_attributes)){
                // --- ignore variables we don't want to apply to the class using this behavior
                continue;
            }
            $this->getParentModel()->{$attribute_name} = $this->owner->{$attribute_name};
        }

        // --- We need to do this for other class properties that aren't yii magic attributes and are actual member vairables
        foreach(get_class_vars($this->parent_class) as $attribute_name => $value){
            if(in_array($attribute_name, $this->shared_attributes)){
                // --- ignore variables we don't want to apply to the class using this behavior
                continue;
            }
            $this->getParentModel()->{$attribute_name} = $this->owner->{$attribute_name};
        }

        if(!$this->getParentModel()->save()){
            // --- Apply any validation errors that may be on the Parent the owner model model just in case
            foreach($this->getParentModel()->getErrors() as $attribute_name => $errors){
                foreach($errors as  $error){
                    $this->owner->addError($attribute_name, $error);    
                }
            }
            return false;
        } else {
            // --- Sets the foreign key field for this model to the id of the saved parent
            $this->{$this->foreign_key_field} = $this->getParentModel()->id;
        }

        return true;
    }

    /**
     * Also delete the parent model
     * {@inheritdoc}
     */
    public function afterDelete()
    {
        $this->getParentModel()->delete();
    }
}
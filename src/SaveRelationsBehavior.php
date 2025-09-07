<?php

namespace ensostudio\saveRelationsBehavior;

use RuntimeException;
use Yii;
use yii\base\Behavior;
use yii\base\Exception;
use yii\base\InvalidArgumentException;
use yii\base\InvalidConfigException;
use yii\base\ModelEvent;
use yii\base\UnknownPropertyException;
use yii\db\ActiveQuery;
use yii\db\BaseActiveRecord;
use yii\db\Exception as DbException;
use yii\helpers\ArrayHelper;
use yii\helpers\Inflector;
use yii\helpers\VarDumper;

/**
 * This Active Record Behavior allows to validate and save the Model relations when the save() method is invoked.
 * List of handled relations should be declared using the $relations parameter via an array of relation names.
 *
 * @author albanjubert
 * @property-read array $dirtyRelations
 * @property-read array $oldRelations
 * @property BaseActiveRecord $owner
 */
class SaveRelationsBehavior extends Behavior
{
    public const RELATION_KEY_FORM_NAME = 'formName';
    public const RELATION_KEY_RELATION_NAME = 'relationName';

    /**
     * @var (array|string)[] An array of model relations: relation name or/and name => configuration.
     * The relation configuration:
     * - 'scenario': string, the scenario name of related model (optional)
     * - 'extraColumns': array, the additional column values to be saved into the junction table (optional)
     * - 'cascadeDelete': bool, whether to delete related records (optional)
     */
    public array $relations = [];
    public string $relationKeyName = self::RELATION_KEY_FORM_NAME;
    /**
     * @var bool Relations attributes now honor the `safe` validation rule
     * @since 2.0.0
     */
    public bool $onlySafeAttributes = false;

    private array $_relations = [];
    /**
     * @var array Store initial relations value
     */
    private array $_oldRelationValue = [];
    /**
     * @var array Store update relations value
     */
    private array $_newRelationValue = [];
    private array $_relationsToDelete = [];
    private bool $_relationsSaveStarted = false;
    /**
     * @var BaseActiveRecord[]
     */
    private array $_savedHasOneModels = [];
    private array $_relationsScenario = [];
    private array $_relationsExtraColumns = [];
    private array $_relationsCascadeDelete = [];

    public function init()
    {
        parent::init();

        $allowedProperties = ['scenario', 'extraColumns', 'cascadeDelete'];
        foreach ($this->relations as $key => $value) {
            if (is_int($key)) {
                $this->_relations[] = $value;
            } else {
                $this->_relations[] = $key;
                if (is_array($value)) {
                    foreach ($value as $propertyKey => $propertyValue) {
                        if (in_array($propertyKey, $allowedProperties)) {
                            $this->{'_relations' . ucfirst($propertyKey)}[$key] = $propertyValue;
                        } else {
                            throw new UnknownPropertyException(
                                'The relation property named ' . $propertyKey . ' is not supported'
                            );
                        }
                    }
                }
            }
        }
    }

    public function events(): array
    {
        return [
            BaseActiveRecord::EVENT_BEFORE_VALIDATE => 'beforeValidate',
            BaseActiveRecord::EVENT_AFTER_VALIDATE => 'afterValidate',
            BaseActiveRecord::EVENT_AFTER_INSERT => 'afterSave',
            BaseActiveRecord::EVENT_AFTER_UPDATE => 'afterSave',
            BaseActiveRecord::EVENT_BEFORE_DELETE => 'beforeDelete',
            BaseActiveRecord::EVENT_AFTER_DELETE => 'afterDelete'
        ];
    }

    /**
     * Check if the behavior is attached to an ActiveRecord
     *
     * @throws RuntimeException
     */
    public function attach($owner)
    {
        if (!$owner instanceof BaseActiveRecord) {
            throw new RuntimeException('Owner must be instance of yii\db\BaseActiveRecord');
        }

        parent::attach($owner);
    }

    /**
     * Override `canSetProperty` method to be able to detect if a relation setter is allowed.
     * Setter is allowed if the relation is declared in the `relations` parameter
     * {@inheritDoc}
     */
    public function canSetProperty($name, $checkVars = true): bool
    {
        if (in_array($name, $this->_relations) && $this->owner->getRelation($name, false) !== null) {
            return true;
        }

        return parent::canSetProperty($name, $checkVars);
    }

    /**
     * Override `__set` method to be able to set relations values either by providing a model instance,
     * a primary key value or an associative array
     * {@inheritDoc}
     */
    public function __set($name, $value)
    {
        if (
            in_array($name, $this->_relations)
            && (!$this->onlySafeAttributes || in_array($name, $this->owner->safeAttributes()))
        ) {
            Yii::debug("Setting $name relation value", __METHOD__);
            /** @var ActiveQuery $relation */
            $relation = $this->owner->getRelation($name);
            if (!isset($this->_oldRelationValue[$name])) {
                if ($this->owner->isNewRecord) {
                    if ($relation->multiple) {
                        $this->_oldRelationValue[$name] = [];
                    } else {
                        $this->_oldRelationValue[$name] = null;
                    }
                } else {
                    $this->_oldRelationValue[$name] = $this->owner->{$name};
                }
            }
            if ($relation->multiple) {
                $this->setMultipleRelation($name, $value);
            } else {
                $this->setSingleRelation($name, $value);
            }
        }
    }

    /**
     * Set the named multiple relation with the given value
     *
     * @param mixed $value
     * @return void
     * @throws InvalidArgumentException
     */
    protected function setMultipleRelation(string $relationName, $value)
    {
        /** @var ActiveQuery $relation */
        $relation = $this->owner->getRelation($relationName);
        $newRelations = [];
        if (!is_array($value)) {
            if (!empty($value)) {
                $value = [$value];
            } else {
                $value = [];
            }
        }
        foreach ($value as $entry) {
            if ($entry instanceof $relation->modelClass) {
                $newRelations[] = $entry;
            } else {
                // TODO handle this with one DB request to retrieve all models
                $newRelations[] = $this->processModelAsArray($entry, $relation, $relationName);
            }
        }
        $this->_newRelationValue[$relationName] = $newRelations;
        $this->owner->populateRelation($relationName, $newRelations);
    }

    /**
     * Get a `BaseActiveRecord` model using the given `$data` parameter.
     * `$data` could either be a model ID or an associative array representing model attributes => values
     *
     * @param mixed $data
     * @throws InvalidConfigException
     */
    protected function processModelAsArray($data, ActiveQuery $relation, string $relationName): ?BaseActiveRecord
    {
        $fks = $this->_getRelatedFks($data, $relation, $relation->modelClass);

        return $this->_loadOrCreateRelationModel($data, $fks, $relation->modelClass, $relationName);
    }

    /**
     * Get the related model foreign keys
     *
     * @param mixed $data
     * @param class-string $modelClass
     * @return mixed
     */
    private function _getRelatedFks($data, ActiveQuery $relation, string $modelClass)
    {
        $fks = [];
        if (is_array($data)) {
            // Get the right link definition
            if ($relation->via instanceof BaseActiveRecord) {
                $link = $relation->via->link;
            } elseif (is_array($relation->via)) {
                [, $viaQuery] = $relation->via;
                $link = $viaQuery->link;
            } else {
                $link = $relation->link;
            }
            // search PK
            foreach ($modelClass::primaryKey() as $modelAttribute) {
                if (isset($data[$modelAttribute])) {
                    $fks[$modelAttribute] = $data[$modelAttribute];
                } elseif ($relation->multiple && !$relation->via) {
                    foreach ($link as $relatedAttribute => $relatedModelAttribute) {
                        if (!isset($data[$relatedAttribute]) && in_array(
                                $relatedAttribute,
                                $modelClass::primaryKey()
                            )) {
                            $fks[$relatedAttribute] = $this->owner->{$relatedModelAttribute};
                        }
                    }
                } else {
                    $fks = [];
                    break;
                }
            }
            if (empty($fks)) {
                foreach ($link as $modelAttribute) {
                    if (isset($data[$modelAttribute])) {
                        $fks[$modelAttribute] = $data[$modelAttribute];
                    }
                }
            }
        } else {
            $fks = $data;
        }
        return $fks;
    }

    /**
     * Load existing model or create one if no key was provided and data is not empty
     *
     * @param mixed $data
     * @param mixed $fks
     * @param class-string $modelClass
     * @throws InvalidConfigException
     */
    private function _loadOrCreateRelationModel(
        $data,
        $fks,
        string $modelClass,
        string $relationName
    ): ?BaseActiveRecord
    {
        $relationModel = null;
        /** @var BaseActiveRecord $relationModel */
        if (!empty($fks)) {
            $relationModel = $modelClass::findOne($fks);
        }
        if (!($relationModel instanceof BaseActiveRecord) && !empty($data)) {
            $relationModel = Yii::createObject($modelClass);
        }
        // If a custom scenario is set, apply it here to correctly be able to set the model attributes
        if (array_key_exists($relationName, $this->_relationsScenario)) {
            $relationModel->setScenario($this->_relationsScenario[$relationName]);
        }
        if (($relationModel instanceof BaseActiveRecord) && is_array($data)) {
            $relationModel->setAttributes($data);
            if ($relationModel->hasMethod('loadRelationsForSave')) {
                $relationModel->loadRelationsForSave($data);
            }
        }
        return $relationModel;
    }

    /**
     * @return void
     * @throws InvalidConfigException
     */
    public function loadRelationsForSave(array $data)
    {
        $this->loadRelations($data);
    }

    /**
     * Populates relations with input data
     *
     * @return void
     * @throws InvalidConfigException
     */
    public function loadRelations(array $data)
    {
        foreach ($this->_relations as $relationName) {
            $keyName = $this->_getRelationKeyName($relationName);
            if (array_key_exists($keyName, $data)) {
                $this->owner->{$relationName} = $data[$keyName];
            }
        }
    }

    /**
     * @throws InvalidConfigException
     */
    private function _getRelationKeyName(string $relationName): string
    {
        switch ($this->relationKeyName) {
            case self::RELATION_KEY_RELATION_NAME:
                $keyName = $relationName;
                break;
            case self::RELATION_KEY_FORM_NAME:
                /** @var ActiveQuery $relation */
                $relation = $this->owner->getRelation($relationName);
                /** @var BaseActiveRecord $relationalModel */
                $relationalModel = Yii::createObject($relation->modelClass);
                $keyName = $relationalModel->formName();
                break;
            default:
                throw new InvalidConfigException('Unknown relation key name');
        }
        return $keyName;
    }

    /**
     * Set the named single relation with the given value
     *
     * @param mixed $value
     * @return void
     * @throws InvalidArgumentException
     */
    protected function setSingleRelation(string $relationName, $value)
    {
        /** @var ActiveQuery $relation */
        $relation = $this->owner->getRelation($relationName);
        if (!$value instanceof $relation->modelClass) {
            $value = $this->processModelAsArray($value, $relation, $relationName);
        }
        $this->_newRelationValue[$relationName] = $value;
        $this->owner->populateRelation($relationName, $value);
    }

    /**
     * Before the owner model validation, save related models.
     * For `hasOne()` relations, set the according foreign keys of the owner model to be able to validate it
     *
     * @return void
     * @throws DbException
     * @throws InvalidConfigException
     */
    public function beforeValidate(ModelEvent $event)
    {
        if (!$this->_relationsSaveStarted && !empty($this->_oldRelationValue)) {
            if ($this->saveRelatedRecords($this->owner, $event)) {
                // If relation is has_one, try to set related model attributes
                foreach ($this->_relations as $relationName) {
                    if (array_key_exists($relationName, $this->_oldRelationValue)) {
                        // Relation was not set, do nothing...
                        $this->_setRelationForeignKeys($relationName);
                    }
                }
            }
        }
    }

    /**
     * Prepare each related model (validate or save if needed).
     * This is done during the before validation process to be able
     * to set the related foreign keys for newly created has one records.
     *
     * @throws DbException
     * @throws InvalidArgumentException
     * @throws InvalidConfigException
     */
    protected function saveRelatedRecords(BaseActiveRecord $model, ModelEvent $event): bool
    {
        try {
            foreach ($this->_relations as $relationName) {
                if (array_key_exists($relationName, $this->_oldRelationValue)) {
                    // Relation was not set, do nothing...
                    /** @var ActiveQuery $relation */
                    $relation = $model->getRelation($relationName);
                    if (!empty($model->{$relationName})) {
                        if ($relation->multiple === false) {
                            $this->_prepareHasOneRelation($model, $relationName, $event);
                        } else {
                            $this->_prepareHasManyRelation($model, $relationName);
                        }
                    }
                }
            }
            if (!$event->isValid) {
                throw new Exception('One of the related model could not be validated');
            }
        } catch (Exception $e) {
            Yii::warning(
                get_class($e)
                    . ' was thrown while saving related records during beforeValidate event: ' . $e->getMessage(),
                __METHOD__
            );
            // Rollback saved records during validation process, if any
            $this->_rollbackSavedHasOneModels();
            $model->addError($model->formName(), $e->getMessage());
            // Stop saving, something went wrong
            $event->isValid = false;

            return false;
        }
        return true;
    }

    /**
     * @return void
     */
    private function _prepareHasOneRelation(BaseActiveRecord $model, string $relationName, ModelEvent $event)
    {
        Yii::debug("_prepareHasOneRelation for $relationName", __METHOD__);
        $relationModel = $model->{$relationName};
        $this->validateRelationModel(self::prettyRelationName($relationName), $relationName, $model->{$relationName});
        $relation = $model->getRelation($relationName);
        $p1 = $model->isPrimaryKey(array_keys($relation->link));
        $p2 = $relationModel::isPrimaryKey(array_values($relation->link));
        if ($relationModel->getIsNewRecord() && $p1 && !$p2) {
            // Save Has one relation new record
            if ($event->isValid && (count($model->dirtyAttributes) || $model->{$relationName}->isNewRecord)) {
                Yii::debug('Saving ' . self::prettyRelationName($relationName) . ' relation model', __METHOD__);
                if ($model->{$relationName}->save()) {
                    $this->_savedHasOneModels[] = $model->{$relationName};
                }
            }
        }
    }

    /**
     * Validate a relation model and add an error message to owner model attribute if needed
     *
     * @return void
     */
    protected function validateRelationModel(
        string $prettyRelationName,
        string $relationName,
        ?BaseActiveRecord $relationModel
    ) {
        if ($relationModel && ($relationModel->isNewRecord || count($relationModel->getDirtyAttributes()))) {
            Yii::debug(
                "Validating $prettyRelationName relation model using $relationModel->scenario scenario",
                __METHOD__
            );
            if (!$relationModel->validate()) {
                $this->_addError($relationModel, $this->owner, $relationName, $prettyRelationName);
            }
        }
    }

    /**
     * Attach errors to owner relational attributes
     *
     * @return void
     */
    private function _addError(
        BaseActiveRecord $relationModel,
        BaseActiveRecord $owner,
        string $relationName,
        string $prettyRelationName
    ) {
        foreach ($relationModel->errors as $attributeErrors) {
            foreach ($attributeErrors as $error) {
                $owner->addError($relationName, "$prettyRelationName: $error");
            }
        }
    }

    protected static function prettyRelationName(string $relationName, ?int $i = null): string
    {
        return Inflector::camel2words($relationName) . ($i === null ? '' : " #$i");
    }

    /**
     * @return void
     */
    private function _prepareHasManyRelation(BaseActiveRecord $model, string $relationName)
    {
        /** @var BaseActiveRecord $relationModel */
        foreach ($model->{$relationName} as $i => $relationModel) {
            $this->validateRelationModel(self::prettyRelationName($relationName, $i), $relationName, $relationModel);
        }
    }

    /**
     * Delete newly created Has one models if any
     *
     * @throws DbException
     */
    private function _rollbackSavedHasOneModels()
    {
        foreach ($this->_savedHasOneModels as $savedHasOneModel) {
            $savedHasOneModel->delete();
        }
        $this->_savedHasOneModels = [];
    }

    /**
     * Set relation foreign keys that point to owner primary key
     *
     * @return void
     */
    protected function _setRelationForeignKeys(string $relationName)
    {
        /** @var ActiveQuery $relation */
        $relation = $this->owner->getRelation($relationName);
        if (!$relation->multiple && !empty($this->owner->{$relationName})) {
            Yii::debug("Setting foreign keys for $relationName", __METHOD__);
            foreach ($relation->link as $relatedAttribute => $modelAttribute) {
                if ($this->owner->{$modelAttribute} !== $this->owner->{$relationName}->{$relatedAttribute}) {
                    if ($this->owner->{$relationName}->isNewRecord) {
                        $this->owner->{$relationName}->save();
                    }
                    $this->owner->{$modelAttribute} = $this->owner->{$relationName}->{$relatedAttribute};
                }
            }
        }
    }

    /**
     * After the owner model validation, rollback newly saved hasOne relations if it fails
     *
     * @return void
     * @throws DbException
     */
    public function afterValidate()
    {
        if (!empty($this->_savedHasOneModels) && $this->owner->hasErrors()) {
            $this->_rollbackSavedHasOneModels();
        }
    }

    /**
     * Link the related models.
     * If the models have not been changed, nothing will be done.
     * Related records will be linked to the owner model using the BaseActiveRecord `link()` method.
     *
     * @throws Exception
     */
    public function afterSave()
    {
        if (!$this->_relationsSaveStarted) {
            $this->_relationsSaveStarted = true;
            // Populate relations with updated values
            foreach ($this->_newRelationValue as $name => $value) {
                $this->owner->populateRelation($name, $value);
            }
            try {
                foreach ($this->_relations as $relationName) {
                    if (array_key_exists($relationName, $this->_oldRelationValue)) {
                        // Relation was not set, do nothing...
                        Yii::debug("Linking $relationName relation", __METHOD__);
                        /** @var ActiveQuery $relation */
                        $relation = $this->owner->getRelation($relationName);
                        if ($relation->multiple) {
                            // Has many relation
                            $this->_afterSaveHasManyRelation($relationName);
                        } else {
                            // Has one relation
                            $this->_afterSaveHasOneRelation($relationName);
                        }
                        unset($this->_oldRelationValue[$relationName]);
                    }
                }
            } catch (Exception $e) {
                Yii::warning(
                    get_class($e)
                        . ' was thrown while saving related records during afterSave event: ' . $e->getMessage(),
                    __METHOD__
                );
                $this->_rollbackSavedHasOneModels();
                /**
                 * Sadly mandatory because the error occurred during afterSave event
                 * and we don't want the user/developper not to be aware of the issue.
                 */
                throw $e;
            }
            $this->owner->refresh();
            $this->_relationsSaveStarted = false;
        }
    }

    /**
     * @throws DbException
     */
    public function _afterSaveHasManyRelation(string $relationName)
    {
        /** @var ActiveQuery $relation */
        $relation = $this->owner->getRelation($relationName);

        // Process new relations
        $existingRecords = [];
        /** @var BaseActiveRecord $relationModel */
        foreach ($this->owner->{$relationName} as $i => $relationModel) {
            if ($relationModel->isNewRecord) {
                if (!empty($relation->via)) {
                    if (!$relationModel->save()) {
                        $this->_addError(
                            $relationModel,
                            $this->owner,
                            $relationName,
                            self::prettyRelationName($relationName, $i)
                        );
                        throw new DbException(
                            'Related record ' . self::prettyRelationName($relationName, $i) . ' could not be saved.'
                        );
                    }
                }
                $junctionTableColumns = $this->_getJunctionTableColumns($relationName, $relationModel);
                $this->owner->link($relationName, $relationModel, $junctionTableColumns);
            } else {
                $existingRecords[] = $relationModel;
            }
            if (count($relationModel->dirtyAttributes) || count($this->_newRelationValue)) {
                if (!$relationModel->save()) {
                    $this->_addError(
                        $relationModel,
                        $this->owner,
                        $relationName,
                        self::prettyRelationName($relationName)
                    );
                    throw new DbException(
                        'Related record ' . self::prettyRelationName($relationName) . ' could not be saved.'
                    );
                }
            }
        }
        $junctionTablePropertiesUsed = array_key_exists($relationName, $this->_relationsExtraColumns);

        // Process existing added and deleted relations
        [$addedPks, $deletedPks] = $this->_computePkDiff(
            $this->_oldRelationValue[$relationName],
            $existingRecords,
            $junctionTablePropertiesUsed
        );

        // Deleted relations
        $initialModels = ArrayHelper::index(
            $this->_oldRelationValue[$relationName],
            function (BaseActiveRecord $model) {
                return implode('-', $model->getPrimaryKey(true));
            }
        );
        $initialRelations = $this->owner->{$relationName};
        foreach ($deletedPks as $key) {
            $this->owner->unlink($relationName, $initialModels[$key], true);
        }

        // Added relations
        $actualModels = ArrayHelper::index(
            $junctionTablePropertiesUsed ? $initialRelations : $this->owner->{$relationName},
            function (BaseActiveRecord $model) {
                return implode('-', $model->getPrimaryKey(true));
            }
        );
        foreach ($addedPks as $key) {
            $junctionTableColumns = $this->_getJunctionTableColumns($relationName, $actualModels[$key]);
            $this->owner->link($relationName, $actualModels[$key], $junctionTableColumns);
        }
    }

    /**
     * Return array of columns to save to the junction table for a related model having a many-to-many relation.
     *
     * @throws \RuntimeException
     */
    private function _getJunctionTableColumns(string $relationName, BaseActiveRecord $model): array
    {
        $junctionTableColumns = [];
        if (array_key_exists($relationName, $this->_relationsExtraColumns)) {
            if (is_callable($this->_relationsExtraColumns[$relationName])) {
                $junctionTableColumns = $this->_relationsExtraColumns[$relationName]($model);
            } elseif (is_array($this->_relationsExtraColumns[$relationName])) {
                $junctionTableColumns = $this->_relationsExtraColumns[$relationName];
            }
            if (!is_array($junctionTableColumns)) {
                throw new RuntimeException(
                    'Junction table columns definition must return an array, got ' . gettype($junctionTableColumns)
                );
            }
        }
        return $junctionTableColumns;
    }

    /**
     * Compute the difference between two set of records using primary keys "tokens"
     * If third parameter is set to true all initial related records will be marked for removal even if their
     * properties did not change. This can be handy in a many-to-many `relation_` involving a junction table.
     *
     * @param BaseActiveRecord[] $initialRelations
     * @param BaseActiveRecord[] $updatedRelations
     */
    private function _computePkDiff(array $initialRelations, array $updatedRelations, bool $forceSave = false): array
    {
        // Compute differences between initial relations and the current ones
        $oldPks = ArrayHelper::getColumn(
            $initialRelations,
            function (BaseActiveRecord $model) {
                return implode('-', $model->getPrimaryKey(true));
            }
        );
        $newPks = ArrayHelper::getColumn(
            $updatedRelations,
            function (BaseActiveRecord $model) {
                return implode('-', $model->getPrimaryKey(true));
            }
        );
        if ($forceSave) {
            $addedPks = $newPks;
            $deletedPks = $oldPks;
        } else {
            $identicalPks = array_intersect($oldPks, $newPks);
            $addedPks = array_values(array_diff($newPks, $identicalPks));
            $deletedPks = array_values(array_diff($oldPks, $identicalPks));
        }
        return [$addedPks, $deletedPks];
    }

    /**
     * @throws InvalidArgumentException
     */
    private function _afterSaveHasOneRelation(string $relationName)
    {
        if ($this->_oldRelationValue[$relationName] !== $this->owner->{$relationName}) {
            if ($this->owner->{$relationName} instanceof BaseActiveRecord) {
                $this->owner->link($relationName, $this->owner->{$relationName});
            } else {
                if ($this->_oldRelationValue[$relationName] instanceof BaseActiveRecord) {
                    $this->owner->unlink($relationName, $this->_oldRelationValue[$relationName]);
                }
            }
        }
        if ($this->owner->{$relationName} instanceof BaseActiveRecord) {
            $this->owner->{$relationName}->save();
        }
    }

    /**
     * Get the list of owner model relations in order to be able to delete them after its deletion
     */
    public function beforeDelete()
    {
        /** @var BaseActiveRecord $owner */
        $owner = $this->owner;
        foreach ($this->_relationsCascadeDelete as $relationName => $params) {
            if ($params === true) {
                /** @var ActiveQuery $relation */
                $relation = $owner->getRelation($relationName);
                if (!empty($owner->{$relationName})) {
                    if ($relation->multiple === true) { // Has many relation
                        $this->_relationsToDelete = ArrayHelper::merge(
                            $this->_relationsToDelete,
                            $owner->{$relationName}
                        );
                    } else {
                        $this->_relationsToDelete[] = $owner->{$relationName};
                    }
                }
            }
        }
    }

    /**
     * Delete related models marked as to be deleted
     *
     * @throws Exception
     */
    public function afterDelete()
    {
        /** @var BaseActiveRecord $modelToDelete */
        foreach ($this->_relationsToDelete as $modelToDelete) {
            try {
                if (!$modelToDelete->delete()) {
                    throw new DbException(
                        'Could not delete the related record: ' . $modelToDelete::className(
                        ) . '(' . VarDumper::dumpAsString($modelToDelete->primaryKey) . ')'
                    );
                }
            } catch (Exception $e) {
                Yii::warning(
                    get_class(
                        $e
                    ) . ' was thrown while deleting related records during afterDelete event: ' . $e->getMessage(),
                    __METHOD__
                );
                $this->_rollbackSavedHasOneModels();
                throw $e;
            }
        }
    }

    /**
     * Set the scenario for a given relation
     *
     * @throws InvalidArgumentException
     */
    public function setRelationScenario(string $relationName, string $scenario)
    {
        $relation = $this->owner->getRelation($relationName, false);
        if (in_array($relationName, $this->_relations) && !is_null($relation)) {
            $this->_relationsScenario[$relationName] = $scenario;
        } else {
            throw new InvalidArgumentException('Unknown ' . $relationName . ' relation');
        }
    }

    /**
     * Return the old relations values.
     *
     * @return array The old relations (name-value pairs)
     */
    public function getOldRelations(): array
    {
        $oldRelations = [];
        foreach ($this->_relations as $relationName) {
            $oldRelations[$relationName] = $this->getOldRelation($relationName);
        }

        return $oldRelations;
    }

    /**
     * Returns the old value of the named relation.
     *
     * @param string $relationName The relations name as defined in the behavior `relations` parameter
     * @return mixed
     */
    public function getOldRelation(string $relationName)
    {
        return array_key_exists($relationName, $this->_oldRelationValue)
            ? $this->_oldRelationValue[$relationName]
            : $this->owner->{$relationName};
    }

    /**
     * Returns the relations that have been modified since they are loaded.
     */
    public function getDirtyRelations(): array
    {
        $dirtyRelations = [];
        foreach ($this->_relations as $relationName) {
            if (array_key_exists($relationName, $this->_oldRelationValue)) {
                $dirtyRelations[$relationName] = $this->owner->{$relationName};
            }
        }
        return $dirtyRelations;
    }

    /**
     * Mark a relation as dirty
     */
    public function markRelationDirty(string $relationName): bool
    {
        if (
            in_array($relationName, $this->_relations)
            && !array_key_exists($relationName, $this->_oldRelationValue)
        ) {
            $this->_oldRelationValue[$relationName] = $this->owner->{$relationName};
            return true;
        }

        return false;
    }
}

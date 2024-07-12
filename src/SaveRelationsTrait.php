<?php

namespace luckynvic\saveRelationsBehavior;

use yii\db\ActiveQuery;
use yii\db\ActiveRecord;

/**
 * @mixin ActiveRecord
 * @mixin SaveRelationsBehavior
 */
trait SaveRelationsTrait
{
    public function load($data, $formName = null)
    {
        $loaded = parent::load($data, $formName);
        if ($loaded && $this->hasMethod('loadRelationsForSave')) {
            $this->_prepareLoadData($data, $formName);

            $this->loadRelationsForSave($data);
        }
        return $loaded;
    }

    /**
     * @param $data
     */
    private function _prepareLoadData(&$data, $formName) {
        $scope = $formName === null ? $this->formName() : $formName;

        foreach ($this->relations as $key => $value) {
            if (is_int($key)) {
                $relationName = $value;
            } else {
                $relationName = $key;
            }

            if(!isset($data[$scope][$relationName])) {
                continue;
            }

            $relation = $this->getRelation($relationName);

            if(!$relation) {
                continue;
            }

            $modelClass = $relation->modelClass;
            /** @var ActiveQuery $relationalModel */
            $relationalModel = new $modelClass;
            $keyName = $relationalModel->formName();

            $data[$keyName] = $data[$scope][$relationName];

            unset($data[$scope][$relationName]);
        }
    }
}

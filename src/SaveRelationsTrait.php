<?php

namespace ensostudio\saveRelationsBehavior;

use Yii;

/**
 * @mixin \yii\db\BaseActiveRecord
 * @method void loadRelationsForSave(array $data)
 * @property array $relations
 */
trait SaveRelationsTrait
{
    /**
     * @throws \yii\base\InvalidConfigException
     */
    public function load($data, $formName = null): bool
    {
        $loaded = parent::load($data, $formName);
        if ($loaded && $this->hasMethod('loadRelationsForSave')) {
            $this->prepareLoadData($data, $formName);

            $this->loadRelationsForSave($data);
        }

        return $loaded;
    }

    /**
     * @return void
     * @throws \yii\base\InvalidConfigException
     */
    private function prepareLoadData(array &$data, ?string $formName)
    {
        $scope = $formName === null ? $this->formName() : $formName;

        foreach ($this->relations as $key => $value) {
            if (is_int($key)) {
                $relationName = $value;
            } else {
                $relationName = $key;
            }

            if (!isset($data[$scope][$relationName])) {
                continue;
            }

            $relation = $this->getRelation($relationName);
            if (!$relation) {
                continue;
            }

            /** @var \yii\db\BaseActiveRecord $relationalModel */
            $relationalModel = Yii::createObject($relation->modelClass);
            $keyName = $relationalModel->formName();

            $data[$keyName] = $data[$scope][$relationName];

            unset($data[$scope][$relationName]);
        }
    }
}

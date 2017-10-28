<?php

namespace Eddmash\PowerOrmFaker;

use Eddmash\PowerOrm\Model\Field\AutoField;
use Eddmash\PowerOrm\Model\Field\CharField;
use Eddmash\PowerOrm\Model\Field\Field;
use Eddmash\PowerOrm\Model\Model;
use Faker\Generator;
use Faker\Guesser\Name;

/**
 * Service class for populating a table through a PowerOrm Entity class.
 */
class EntityPopulator
{
    public $generator;
    public $userFormatters;
    /**
     * @var Model
     */
    protected $model;
    /**
     * @var array
     */
    protected $columnFormatters = array();
    /**
     * @var array
     */
    protected $modifiers = array();

    /**
     * Class constructor.
     *
     * @param Model $class
     */
    public function __construct(Model $class)
    {
        $this->model = $class;
    }

    /**
     * @return string
     */
    public function getClass()
    {
        return $this->model->meta->getNamespacedModelName();
    }

    /**
     * @param $columnFormatters
     */
    public function setColumnFormatters($columnFormatters)
    {
        $this->columnFormatters = $columnFormatters;
    }

    /**
     * @return array
     */
    public function getColumnFormatters()
    {
        return $this->columnFormatters;
    }

    public function mergeColumnFormattersWith($columnFormatters)
    {
        $this->columnFormatters = array_merge($this->columnFormatters, $columnFormatters);
    }

    public function setGenerator(Generator $generator)
    {
        $this->generator = $generator;
    }

    /**
     * @param array $modifiers
     */
    public function setModifiers(array $modifiers)
    {
        $this->modifiers = $modifiers;
    }

    /**
     * @return array
     */
    public function getModifiers()
    {
        return $this->modifiers;
    }

    /**
     * @param array $modifiers
     */
    public function mergeModifiersWith(array $modifiers)
    {
        $this->modifiers = array_merge($this->modifiers, $modifiers);
    }

    /**
     * @param Generator $generator
     *
     * @return array
     */
    public function guessColumnFormatters()
    {
        $userFormatters = $this->getUserFormatters();

        $formatters = array();
        $nameGuesser = new Name($this->generator);
        $columnTypeGuesser = new ColumnTypeGuesser($this->generator);
        /** @var $field Field */
        foreach ($this->model->meta->localFields as $name => $field) {
            if ($field instanceof AutoField || $field->isRelation) {
                continue;
            }
            $fieldName = $field->getName();

            if (array_key_exists($fieldName, $userFormatters)):
                $formatters[$fieldName] = $userFormatters[$fieldName];
                continue;
            endif;
            $size = ($field->hasProperty('maxLength') === true) ? $field->maxLength : null;

            if ($formatter = $nameGuesser->guessFormat($fieldName, $size)) {
                $formatters[$fieldName] = $formatter;
                continue;
            }
            if ($formatter = $columnTypeGuesser->guessFormat($fieldName, $this->model)) {
                $formatters[$fieldName] = $formatter;
                continue;
            }
        }

        // take of relationships
        foreach ($this->model->meta->localFields as $name => $field) :
            if ($field->isRelation === false):
                continue;
            endif;

            $fieldName = $field->getName();
            $relatedClass = $field->relation->getToModel();
            $relatedClass = (is_string($relatedClass)) ? $relatedClass : $relatedClass->meta->getNamespacedModelName();
            $index = 0;
            $unique = $field->isUnique();
            $optional = $field->isNull();

            $formatters[$fieldName] = function ($generator, $object, $inserted) use (
                $relatedClass, &$index, $unique,
                $optional
            ) {
                if (isset($inserted[$relatedClass])) :
                    if ($unique):
                        $related = null;
                        if (isset($inserted[$relatedClass][$index]) || !$optional) :
                            $related = $inserted[$relatedClass][$index];
                        endif;
                        ++$index;

                        return $related;

                    endif;

                    return $inserted[$relatedClass][mt_rand(0, count($inserted[$relatedClass]) - 1)];
                endif;
            };

        endforeach;

        // take of relationships
        foreach ($this->model->meta->localManyToMany as $name => $field) :
            if ($field->isRelation === false):
                continue;
            endif;

            $fieldName = $field->getName();
            $relatedClass = $field->relation->getToModel();
            $relatedClass = (is_string($relatedClass)) ? $relatedClass : $relatedClass->meta->getNamespacedModelName();
            $index = 0;
            $unique = $field->isUnique();
            $optional = $field->isNull();

            $formatters[$fieldName] = function ($generator, $object, $inserted) use (
                $relatedClass, &$index, $unique,
                $optional
            ) {
                if (isset($inserted[$relatedClass])) :
                    if ($unique):
                        $related = null;
                        if (isset($inserted[$relatedClass][$index]) || !$optional) :
                            $related = $inserted[$relatedClass][$index];
                        endif;
                        ++$index;

                        return $related;

                    endif;

                    return array_slice($inserted[$relatedClass], 0, mt_rand(0, count($inserted[$relatedClass]) - 1));
                endif;
            };

        endforeach;

        return $formatters;
    }

    /**
     * Insert one new record using the Entity class.
     *
     * @param array $insertedEntities a list of all inserted records ids per model
     * @param bool $generateId
     *
     * @return Model
     */
    public function execute($insertedEntities, $generateId = false)
    {

        $class = $this->getClass();
        /** @var $obj Model */
        $obj = new $class();

        $this->fillColumns($obj, $insertedEntities);
        $this->callMethods($obj, $insertedEntities);
        $obj->save();
        $this->saveM2M($obj, $insertedEntities);

        return $obj;

    }

    /**
     * @param Model $obj
     * @param $insertedEntities
     * @author: Eddilbert Macharia (http://eddmash.com)<edd.cowan@gmail.com>
     */
    private function fillColumns(Model $obj, $insertedEntities)
    {
        foreach ($this->columnFormatters as $fieldName => $format) {
            if (null !== $format) {
                // Add some extended debugging information to any errors thrown by the formatter
                try {
                    $value = is_callable($format) ? $format($this->generator, $obj, $insertedEntities) : $format;
                } catch (\InvalidArgumentException $ex) {
                    throw new \InvalidArgumentException(
                        sprintf(
                            'Failed to generate a value for %s::%s: %s',
                            get_class($obj),
                            $fieldName,
                            $ex->getMessage()
                        )
                    );
                }
                if ($obj->meta->getField($fieldName)->manyToMany):
                    continue;
                endif;
                $obj->{$fieldName} = $this->prepareValue($obj, $fieldName, $value);
            }
        }

    }

    /**
     * @param Model $obj
     * @param $insertedEntities
     * @author: Eddilbert Macharia (http://eddmash.com)<edd.cowan@gmail.com>
     */
    private function saveM2M(Model $obj, $insertedEntities)
    {
        foreach ($this->columnFormatters as $fieldName => $format) {
            if (null !== $format) {
                // Add some extended debugging information to any errors thrown by the formatter
                try {
                    $value = is_callable($format) ? $format($this->generator, $obj,$insertedEntities) : $format;
                } catch (\InvalidArgumentException $ex) {
                    throw new \InvalidArgumentException(
                        sprintf(
                            'Failed to generate a value for %s::%s: %s',
                            get_class($obj),
                            $fieldName,
                            $ex->getMessage()
                        )
                    );
                }
                if ($obj->meta->getField($fieldName)->manyToMany) :
                    $obj->{$fieldName}->set($value);
                endif;
            }
        }
    }

    private function prepareValue(Model $obj, $fieldName, $value)
    {
        $field = $obj->meta->getField($fieldName);

        if ($field instanceof CharField && $field->maxLength && is_string($value)) :
            $value = substr($value, 0, $field->maxLength);
        endif;

        return $value;
    }

    private function callMethods($obj, $insertedEntities)
    {
        foreach ($this->getModifiers() as $modifier) {
            $modifier($obj, $insertedEntities);
        }
    }

    private function getUserFormatters()
    {
        return $this->userFormatters;
    }

    /**
     * @param mixed $userFormatters
     */
    public function setUserFormatters($userFormatters)
    {
        $this->userFormatters = $userFormatters;
    }



}

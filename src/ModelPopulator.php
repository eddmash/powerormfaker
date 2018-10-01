<?php

namespace Eddmash\PowerOrmFaker;

use Eddmash\PowerOrm\Exception\FieldDoesNotExist;
use Eddmash\PowerOrm\Model\Field\AutoField;
use Eddmash\PowerOrm\Model\Field\CharField;
use Eddmash\PowerOrm\Model\Field\Field;
use Eddmash\PowerOrm\Model\Model;
use Faker\Generator;
use Faker\Guesser\Name;

/**
 * Service class for populating a table through a PowerOrm Model class.
 */
class ModelPopulator
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
    protected $columnFormatters = [];
    /**
     * @var array
     */
    protected $modifiers = [];

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
        return $this->model->getMeta()->getNSModelName();
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

        $formatters = [];
        $nameGuesser = new Name($this->generator);
        $columnTypeGuesser = new ColumnTypeGuesser($this->generator);
        /** @var $field Field */
        foreach ($this->model->getMeta()->localFields as $name => $field) {
            if ($field instanceof AutoField || $field->isRelation) {
                continue;
            }
            $fieldName = $field->getName();

            if (array_key_exists($fieldName, $userFormatters)) {
                $formatters[$fieldName] = $userFormatters[$fieldName];
                continue;
            }
            $size = (true === $field->hasProperty('maxLength')) ?
                $field->maxLength : null;

            if ($formatter = $nameGuesser->guessFormat($fieldName, $size)) {
                $formatters[$fieldName] = $formatter;
                continue;
            }
            $formatter = $columnTypeGuesser->guessFormat(
                $fieldName,
                $this->model
            );
            if ($formatter) {
                $formatters[$fieldName] = $formatter;
                continue;
            }
        }

        // take care of forward relationships non m2m
        foreach ($this->model->getMeta()->localFields as $name => $field) {
            if (false === $field->isRelation) {
                continue;
            }
            $fieldName = $field->getName();
            $relatedClass = $field->relation->getToModel();
            $relatedClass = (is_string($relatedClass)) ? $relatedClass :
                $relatedClass->getMeta()->getNSModelName();
            $index = 0;
            $unique = $field->isUnique();
            $optional = $field->isNull();

            $formatters[$fieldName] = function ($generator, $object, $inserted) use (
                $relatedClass,
                &$index,
                $unique,
                $optional
            ) {
                if (isset($inserted[$relatedClass])) {
                    if ($unique) {
                        $related = null;
                        if (isset($inserted[$relatedClass][$index]) || !$optional) {
                            $related = $inserted[$relatedClass][$index];
                        }
                        ++$index;

                        return $related;
                    }
                    $val = mt_rand(0, count($inserted[$relatedClass]) - 1);

                    return $inserted[$relatedClass][$val];
                }
            };
        }

        // take of care m2m relationships
        foreach ($this->model->getMeta()->localManyToMany as $name => $field) {
            if (false === $field->isRelation) {
                continue;
            }

            $fieldName = $field->getName();
            $relatedClass = $field->relation->getToModel();
            $relatedClass = (is_string($relatedClass)) ? $relatedClass :
                $relatedClass->getMeta()->getNSModelName();
            $index = 0;
            $unique = $field->isUnique();
            $optional = $field->isNull();

            $formatters[$fieldName] = function ($generator, $object, $inserted) use (
                $relatedClass,
                &$index,
                $unique,
                $optional
            ) {
                if (isset($inserted[$relatedClass])) {
                    if ($unique) {
                        $related = null;
                        if (isset($inserted[$relatedClass][$index]) || !$optional) {
                            $related = $inserted[$relatedClass][$index];
                        }
                        ++$index;

                        return $related;
                    }

                    return array_slice(
                        $inserted[$relatedClass],
                        0,
                        mt_rand(0, count($inserted[$relatedClass]) - 1)
                    );
                }
            };
        }

        return $formatters;
    }

    /**
     * Insert one new record using the Entity class.
     *
     * @param array $insertedEntities a list of all inserted records ids per model
     * @param bool  $generateId
     *
     * @return Model
     *
     * @throws \Eddmash\PowerOrm\Exception\ValueError
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
     * @param       $insertedEntities
     * @author: Eddilbert Macharia (http://eddmash.com)<edd.cowan@gmail.com>
     */
    private function fillColumns(Model $obj, $insertedEntities)
    {
        foreach ($this->columnFormatters as $fieldName => $format) {
            if (null !== $format) {
                // Add some extended debugging information to any errors
                // thrown by the formatter
                try {
                    $value = is_callable($format) ?
                        $format($this->generator, $obj, $insertedEntities) :
                        $format;
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

                try {
                    if ($obj->getMeta()->getField($fieldName)->manyToMany) {
                        continue;
                    }
                    $obj->{$fieldName} = $this->prepareValue($obj, $fieldName, $value);
                } catch (FieldDoesNotExist $e) {
                }
            }
        }
    }

    /**
     * @param Model $obj
     * @param       $insertedEntities
     * @author: Eddilbert Macharia (http://eddmash.com)<edd.cowan@gmail.com>
     */
    private function saveM2M(Model $obj, $insertedEntities)
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
                if ($obj->getMeta()->getField($fieldName)->manyToMany) {
                    $obj->{$fieldName}->set($value);
                }
            }
        }
    }

    private function prepareValue(Model $obj, $fieldName, $value)
    {
        $field = $obj->getMeta()->getField($fieldName);

        if ($field instanceof CharField && $field->maxLength && is_string($value)) {
            $value = substr($value, 0, $field->maxLength);
        }

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
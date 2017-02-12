<?php

namespace Eddmash\PowerOrmFaker;

use Eddmash\PowerOrm\Model\Field\Field;
use Eddmash\PowerOrm\Model\Model;
use Faker\Generator;
use Faker\Guesser\Name;

/**
 * Service class for populating a table through a PowerOrm Entity class.
 */
class EntityPopulator
{
    /**
     * @var Model
     */
    protected $class;
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
        $this->class = $class;
    }

    /**
     * @return string
     */
    public function getClass()
    {
        return $this->class->meta->modelName;
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
    public function guessColumnFormatters(Generator $generator)
    {
        $formatters = array();
        $nameGuesser = new Name($generator);
        $columnTypeGuesser = new ColumnTypeGuesser($generator);
        /** @var $field Field */
        foreach ($this->class->meta->localFields as $name => $field) {
            if ($field->primaryKey || $field->isRelation) {
                continue;
            }
            $fieldName = $field->getColumnName();
            $size = ($field->hasProperty('maxLength') === true) ? $field->maxLength : null;

            if ($formatter = $nameGuesser->guessFormat($fieldName, $size)) {
                $formatters[$fieldName] = $formatter;
                continue;
            }
            if ($formatter = $columnTypeGuesser->guessFormat($fieldName, $this->class)) {
                $formatters[$fieldName] = $formatter;
                continue;
            }
        }

        foreach ($this->class->meta->localFields as $name => $field) :
            if ($field->isRelation === false):
                continue;
            endif;

            $fieldName = $field->name;
            $relatedClass = $field->relation->getToModel();
            $relatedClass = (is_string($relatedClass)) ? $relatedClass : $relatedClass->meta->modelName;
            $index = 0;
            $unique = $field->isUnique();
            $optional = $field->null;

            $formatters[$fieldName] = function ($inserted) use ($relatedClass, &$index, $unique, $optional) {
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

        return $formatters;
    }

    /**
     * Insert one new record using the Entity class.
     *
     * @param array $insertedEntities a list of all inserted records ids per model
     * @param bool  $generateId
     *
     * @return EntityPopulator
     */
    public function execute($insertedEntities, $generateId = false)
    {
        $class = $this->getClass();
        /** @var $obj Model */
        $obj = new $class();

        $this->fillColumns($obj, $insertedEntities);
        $this->callMethods($obj, $insertedEntities);

        $obj->save();

//        $pk = $obj->meta->primaryKey->name;

        return $obj;

    }

    private function fillColumns($obj, $insertedEntities)
    {
        foreach ($this->columnFormatters as $fieldName => $format) {
            if (null !== $format) {
                // Add some extended debugging information to any errors thrown by the formatter
                try {
                    $value = is_callable($format) ? $format($insertedEntities, $obj) : $format;
                } catch (\InvalidArgumentException $ex) {
                    throw new \InvalidArgumentException(sprintf(
                        'Failed to generate a value for %s::%s: %s',
                        get_class($obj),
                        $fieldName,
                        $ex->getMessage()
                    ));
                }

                $obj->{$fieldName} = $value;
            }
        }
    }

    private function callMethods($obj, $insertedEntities)
    {
        foreach ($this->getModifiers() as $modifier) {
            $modifier($obj, $insertedEntities);
        }
    }

}

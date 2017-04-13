<?php

namespace Eddmash\PowerOrmFaker;

use Eddmash\PowerOrm\BaseOrm;
use Eddmash\PowerOrm\Model\Model;
use Faker\Generator;

class ColumnTypeGuesser
{
    protected $generator;

    /**
     * @param Generator $generator
     */
    public function __construct(Generator $generator)
    {
        $this->generator = $generator;
    }

    /**
     * @param Model $class
     *
     * @return \Closure|null
     */
    public function guessFormat($fieldName, Model $class)
    {
        $generator = $this->generator;
        $type = $class->meta->getField($fieldName)->dbType(BaseOrm::getDbConnection());
        switch ($type) {
            case 'boolean':
                return function () use ($generator) {
                    return $generator->boolean;
                };
            case 'decimal':
                $size = isset($class->fieldMappings[$fieldName]['precision']) ? $class->fieldMappings[$fieldName]['precision'] : 2;

                return function () use ($generator, $size) {
                    return $generator->randomNumber($size + 2) / 100;
                };
            case 'smallint':
                return function () {
                    return mt_rand(0, 65535);
                };
            case 'integer':
                return function () {
                    return mt_rand(0, intval('2147483647'));
                };
            case 'bigint':
                return function () {
                    return mt_rand(0, intval('18446744073709551615'));
                };
            case 'float':
                return function () {
                    return mt_rand(0, intval('4294967295')) / mt_rand(1, intval('4294967295'));
                };
            case 'string':
                $size = ($class->hasProperty('maxLength')) ? $class->maxLength : 255;

                return function () use ($generator, $size) {
                    return $generator->text($size);
                };
            case 'text':
                return function () use ($generator) {
                    return $generator->text;
                };
            case 'datetime':
            case 'date':
            case 'time':
                return function () use ($generator) {
                    return $generator->datetime;
                };
            default:
                // no smart way to guess what the user expects here
                return;
        }
    }
}

<?php

namespace Eddmash\PowerOrmFaker;

use Eddmash\PowerOrm\Exception\ValueError;
use Eddmash\PowerOrm\Model\Field\RelatedField;
use Eddmash\PowerOrm\Model\Model;
use Faker\Generator;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Service class for populating a database using the PowerORM.
 */
class Populator
{
    protected $generator;
    protected $manager;
    protected $entities = array();
    protected $quantities = array();
    protected $generateId = array();
    protected $relationMap = array();

    /**
     * @param Generator $generator
     */
    public function __construct(Generator $generator)
    {
        $this->generator = $generator;
    }

    /**
     * Add an order for the generation of $number records for $entity.
     *
     * @param mixed      $entity                 a Model instance, or a \Eddmash\PowerOrmFaker\EntityPopulator instance
     * @param int        $number                 The number of entities to populate
     * @param array      $customColumnFormatters
     * @param array      $customModifiers
     * @param bool|false $generateId
     *
     * @since 1.1.0
     *
     * @author Eddilbert Macharia (http://eddmash.com) <edd.cowan@gmail.com>
     */
    public function addModel(
        $entity,
        $number,
        $customColumnFormatters = array(),
        $customModifiers = array(),
        $generateId = false
    )
    {
        if ($entity instanceof Model):
            $this->relationMap[$entity->meta->getNamespacedModelName()] = [];
            /** @var $field RelatedField */
            foreach ($entity->meta->getFields() as $name => $field) :
                if ($field->isRelation && $field->concrete):
                    $model = $field->relation->getToModel();
                    $relatedModel = (is_string($model)) ? $model : $model->meta->getNamespacedModelName();
                    // todo ignore recursive for now
                    if ($relatedModel === $entity->meta->getNamespacedModelName()):
                        continue;
                    endif;
                    $this->relationMap[$entity->meta->getNamespacedModelName()][] = $relatedModel;
                endif;
            endforeach;

        endif;

        if (!$entity instanceof EntityPopulator):
            $entity = new EntityPopulator($entity);
        endif;

        $entity->setColumnFormatters($entity->guessColumnFormatters($this->generator));
        if ($customColumnFormatters) :
            $entity->mergeColumnFormattersWith($customColumnFormatters);
        endif;

        $entity->mergeModifiersWith($customModifiers);
        $this->generateId[$entity->getClass()] = $generateId;

        $class = $entity->getClass();
        $this->entities[$class] = $entity;
        $this->quantities[$class] = $number;
    }

    /**
     * Populate the database using all the Entity classes previously added.
     *
     * @param OutputInterface $output cli output
     *
     * @return array A list of the inserted PKs
     */
    public function execute($output)
    {
        $insertedEntities = array();

        /* @var $entity EntityPopulator */

        $sortedClasses = $this->topologicalSort($this->relationMap);

        foreach ($sortedClasses as $class) :
            $generateId = $this->generateId[$class];
            $number = $this->quantities[$class];

            $output->writeln(sprintf('Populating %s :', $class));
            $progressBar = new ProgressBar($output, $number);
            for ($i = 0; $i < $number; ++$i) {
                $entity = $this->entities[$class];
                $insertedEntities[$class][] = $entity->execute($insertedEntities, $generateId);
                $progressBar->advance();
            }
            $progressBar->finish();
            $output->writeln(' ');

        endforeach;

        return $insertedEntities;
    }

    /**
     * sorts the operations in topological order using kahns algorithim.
     *
     * @param $operations
     * @param $dependency
     *
     * @return array
     *
     * @since 1.1.0
     *
     * @author Eddilbert Macharia (http://eddmash.com) <edd.cowan@gmail.com>
     */
    private function topologicalSort($dependency)
    {
        $sorted = [];
        $deps = $dependency;
        while ($deps):

            $noDeps = [];

            foreach ($deps as $index => $dep) :
                if (!$dep):
                    $noDeps[] = $index;
                endif;
            endforeach;

            if (!$noDeps):
                throw new ValueError('Cyclic dependency on topological sort');
            endif;

            $sorted = array_merge($sorted, $noDeps);

            $newDeps = [];
            foreach ($deps as $index => $dep) :
                if (!in_array($index, $noDeps)):
                    $parents = array_diff($dep, $noDeps);
                    $newDeps[$index] = $parents;
                endif;
            endforeach;
            $deps = $newDeps;

        endwhile;

        return $sorted;
    }

    public function isResolved($class, $insertedEntities)
    {
        if (empty($this->relationMap[$class])):
            unset($this->relationMap[$class]);
        else:

            $allResolved = false;

            foreach ($this->relationMap[$class] as $depends) :
                if (isset($insertedEntities[$depends])):
                    $key = array_search($depends, $this->relationMap[$class]);

                    $allResolved = $allResolved && isset($insertedEntities[$depends]);
//                    unset($this->relationMap[$class][$key]);
                endif;
            endforeach;

            return $allResolved;
        endif;

        return true;
    }
}

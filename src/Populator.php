<?php

namespace Eddmash\PowerOrmFaker;

use Eddmash\PowerOrm\Db\ConnectionInterface;
use Eddmash\PowerOrm\Exception\CommandError;
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
    protected $entities = [];
    protected $quantities = [];
    protected $generateId = [];
    protected $relationMap = [];

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
     * @param mixed      $entity                 a Model instance, or
     *                                           an \Eddmash\PowerOrmFaker\EntityPopulator instance
     * @param int        $number                 The number of entities to populate
     * @param array      $customColumnFormatters
     * @param array      $customModifiers
     * @param bool|false $generateId
     *
     * @since  1.1.0
     *
     * @author Eddilbert Macharia (http://eddmash.com) <edd.cowan@gmail.com>
     */
    public function addModel(
        $entity,
        $number,
        $customColumnFormatters = [],
        $customModifiers = [],
        $generateId = false
    ) {
        if ($entity instanceof Model) {
            $modelName = $entity->getMeta()->getNSModelName();
            $this->relationMap[$modelName] = [];
            /** @var $field RelatedField */
            foreach ($entity->getMeta()->getFields() as $name => $field) {
                if ($field->isRelation && $field->concrete) {
                    $model = $field->relation->getToModel();

                    $relatedModel = $model->getMeta()->getConcreteModel()
                        ->getMeta()->getNSModelName();
                    // todo ignore recursive for now
                    if ($relatedModel === $modelName) {
                        continue;
                    }
                    $this->relationMap[$modelName][] = $relatedModel;
                }
            }
        }

        $userFormatters = [];
        if ($entity instanceof FakeableInterface) {
            $userFormatters = $entity->registerFormatter($this->generator);
        }

        if (!$entity instanceof ModelPopulator) {
            $entity = new ModelPopulator($entity);
        }
        $entity->setUserFormatters($userFormatters);

        $entity->setGenerator($this->generator);
        $entity->setColumnFormatters($entity->guessColumnFormatters());
        if ($customColumnFormatters) {
            $entity->mergeColumnFormattersWith($customColumnFormatters);
        }

        $entity->mergeModifiersWith($customModifiers);

        $this->generateId[$entity->getClass()] = $generateId;

        $class = $entity->getClass();
        $this->entities[$class] = $entity;
        $this->quantities[$class] = $number;
    }

    /**
     * Populate the database using all the Entity classes previously added.
     *
     * @param ConnectionInterface $connection
     * @param OutputInterface     $output     cli output
     *
     * @throws CommandError
     */
    protected function run(
        ConnectionInterface $connection,
        OutputInterface $output
    ) {
        $insertedEntities = [];

        /* @var $entity ModelPopulator */

        try {
            $sortedClasses = $this->topologicalSort($this->relationMap);
        } catch (ValueError $e) {
            throw new CommandError(
                'Models might depends on models '.
                "whose data isn't being generated"
            );
        }

        try {
            foreach ($sortedClasses as $class) {
                $generateId = $this->generateId[$class];
                $number = $this->quantities[$class];

                $output->writeln(sprintf('Populating %s :', $class));
                $progressBar = new ProgressBar($output, $number);
                for ($i = 0; $i < $number; ++$i) {
                    $entity = $this->entities[$class];
                    $insertedEntities[$class][] = $entity->execute(
                        $insertedEntities,
                        $generateId
                    );
                    $progressBar->advance();
                }
                $progressBar->finish();
                $output->writeln(' ');
            }
        } catch (\Exception $e) {
            throw new CommandError($e->getMessage());
        }
    }

    /**
     * @param ConnectionInterface $connection
     * @param OutputInterface     $output
     *
     * @throws CommandError
     */
    public function execute(
        ConnectionInterface $connection,
        OutputInterface $output
    ) {
        // catch everything even notice
        // we do this to avoid inconsistently generated data.
        $this->handleAllErrors();
        $connection->beginTransaction();
        try {
            $this->run($connection, $output);
            $connection->commit();
        } catch (\Exception $e) {
            $connection->rollback();
            throw new CommandError('Failed To Generate :: '.$e->getMessage());
        } finally {
            $this->restoreDefaultHandlers();
        }
    }

    /**
     * sorts the operations in topological order using kahns algorithim.
     * http://faculty.simpson.edu/lydia.sinapova/www/cmsc250/LN250_Weiss/L20-TopSort.htm.
     *
     * @param $operations
     * @param $dependency
     *
     * @return array
     *
     * @since  1.1.0
     *
     * @author Eddilbert Macharia (http://eddmash.com) <edd.cowan@gmail.com>
     *
     * @throws ValueError
     */
    private function topologicalSort($dependency)
    {
        $sorted = [];
        $deps = $dependency;
        while ($deps) {
            $noDeps = [];

            foreach ($deps as $parent => $dep) {
                if (empty($dep)) {
                    $noDeps[] = $parent;
                }
            }

            // we don't have  a vertice with 0 indegree hence we have loop
            if (empty($noDeps)) {
                throw new ValueError('Cyclic dependency on topological sort');
            }

            $sorted = array_merge($sorted, $noDeps);

            $newDeps = [];

            foreach ($deps as $parent => $dep) {
                // if parent has already been added to sort skip it
                if (!in_array($parent, $noDeps)) {
                    //if its already sorted remove it
                    $newDeps[$parent] = array_diff($dep, $sorted);
                }
            }

            $deps = $newDeps;
        }

        return $sorted;
    }

    public function isResolved($class, $insertedEntities)
    {
        if (empty($this->relationMap[$class])) {
            unset($this->relationMap[$class]);
        } else {
            $allResolved = false;

            foreach ($this->relationMap[$class] as $depends) {
                if (isset($insertedEntities[$depends])) {
                    $key = array_search($depends, $this->relationMap[$class]);

                    $allResolved = $allResolved && isset($insertedEntities[$depends]);
                    //                    unset($this->relationMap[$class][$key]);
                }
            }

            return $allResolved;
        }

        return true;
    }

    public function handleAllErrors()
    {
        set_error_handler(
            function ($errno, $errstr, $errfile, $errline) {
                $msg = $errstr.' on line '.$errline.' in file '.$errfile;
                throw new \Exception($msg);
            }
        );
    }

    public function restoreDefaultHandlers()
    {
        restore_error_handler();
    }
}

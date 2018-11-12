<?php

/*
* This file is part of the powerorm package.
*
* (c) Eddilbert Macharia <edd.cowan@gmail.com>
*
* For the full copyright and license information, please view the LICENSE
* file that was distributed with this source code.
*/

namespace Eddmash\PowerOrmFaker\Commands;

use Eddmash\PowerOrm\BaseOrm;
use Eddmash\PowerOrm\Components\AppInterface;
use Eddmash\PowerOrm\Exception\CommandError;
use Eddmash\PowerOrm\Exception\ComponentException;
use Eddmash\PowerOrm\Model\Model;
use Eddmash\PowerOrmFaker\Populator;
use Faker\Factory;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class Generatedata extends Command
{
    public $help = 'Generate sample data for your models.';

    /**
     * {@inheritdoc}
     */
    public function handle(InputInterface $input, OutputInterface $output)
    {
        $app_name = $input->getArgument('app_name');
        try {
            $component = BaseOrm::getInstance()->getComponent($app_name);
        } catch (ComponentException $e) {
            throw new CommandError(sprintf("Could not find an application with the name %s", $app_name));
        }
        if (!$component instanceof AppInterface) {
            throw new CommandError(sprintf("is not an application %s, it does not implement %s", $app_name,
                AppInterface::class));
        }
        try {
            $connection = BaseOrm::getDbConnection();

            $faker = Factory::create();

            $seed = $input->getOption('seed');
            if ($seed) {
                $faker->seed($seed);
            }
            $populator = new Populator($faker);

            $onlys = $input->getOption('only');

            // assumes the app is using composer to autload using psr-4
            $namespace = sprintf("%s\%s", $component->getNamespace(), substr($component->getModelsPath(),
                strrpos($component->getModelsPath(), DIRECTORY_SEPARATOR) + 1));
            $namespace = ltrim($namespace, "\\");

            $classnames = [];
            foreach ($onlys as $only) {
                if (!strrpos($only, "\\")) {
                    $classnames[] = sprintf("%s\%s", $namespace, $only);
                } else {
                    $classnames[] = $only;
                }
            }

            if ($classnames) {
                $models = $this->getModels($classnames);
            } else {
                $models = BaseOrm::getRegistry()->getModels(true);

                $ignore = $input->getOption('ignore');
                if ($ignore) {
                    // just a check to ensure we have the model provided
                    foreach ($ignore as $item) {
                        BaseOrm::getRegistry()->getModel($item);
                    }
                    // get remaining only
                    $only = array_diff(array_keys($models), $ignore);
                    $models = $this->getModels($only);
                }
            }

            if (!$models) {
                throw new CommandError('Sorry could not locate models');
            }

            $number = $input->getOption('records');
            if (!$number) {
                $number = 5;
            }

            $ignore = array_change_key_case(
                array_flip($input->getOption('ignore')),
                CASE_LOWER
            );
            $only = array_change_key_case(
                array_flip($input->getOption('only')),
                CASE_LOWER
            );

            if ($ignore && $only) {
                throw new CommandError(
                    'Its not allowed to set both ' .
                    "'only' and 'ignore' options at the same time"
                );
            }

            /** @var $model Model */
            foreach ($models as $name => $model) {
                if ($model->getMeta()->autoCreated ||
                    array_key_exists(strtolower($name), $ignore)) {
                    continue;
                }

                $populator->addModel($model, $number);
            }

            $populator->execute($connection, $output);
        } catch (\Exception $exception) {
            throw new CommandError($exception->getMessage());
        }
    }

    protected function configure()
    {
        $this->setName($this->guessCommandName())
            ->setDescription($this->help)
            ->setHelp($this->help)
            ->addArgument(
                'app_name',
                InputArgument::REQUIRED,
                'App for which to generate data for',
                null
            )
            ->addOption(
                'seed',
                '-s',
                InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY,
                'Always the same generated data. ' .
                'using the same seed produces the same results',
                null
            )
            ->addOption(
                'only',
                '-o',
                InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY,
                'The list of models to use when generating records.',
                null
            )
            ->addOption(
                'ignore',
                '-i',
                InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY,
                'The list of models to ignore when generating records.',
                null
            )
            ->addOption(
                'records',
                '-r',
                InputOption::VALUE_REQUIRED,
                'The number of records to generate per model.',
                null
            );
    }

    public function getModels($names = [])
    {
        $models = [];
        foreach ($names as $name) {
            $models[] = BaseOrm::getRegistry()->getModel($name);
        }

        return $models;
    }
}

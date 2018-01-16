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
use Eddmash\PowerOrm\Exception\CommandError;
use Eddmash\PowerOrm\Model\Model;
use Eddmash\PowerOrmFaker\Populator;
use Faker\Factory;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class Generatedata extends Command
{
    public $help = 'Generate sample data for your models.';
    
    /**
     * @inheritdoc
     */
    public function handle(InputInterface $input, OutputInterface $output)
    {
        try {
            $connection = BaseOrm::getDbConnection();
            
            $faker = Factory::create();
            
            $seed = $input->getOption('seed');
            if ($seed):
                $faker->seed($seed);
            endif;
            $populator = new Populator($faker);
            
            $only = $input->getOption('only');
            
            if ($only) :
                $models = $this->getModels($only);
            else:
                
                $models = BaseOrm::getRegistry()->getModels(true);
                
                $ignore = $input->getOption('ignore');
                if ($ignore) :
                    // just a check to ensure we have the model provided
                    foreach ($ignore as $item) :
                        BaseOrm::getRegistry()->getModel($item);
                    endforeach;
                    // get remaining only
                    $only = array_diff(array_keys($models), $ignore);
                    $models = $this->getModels($only);
                endif;
            endif;
            
            if (!$models):
                throw new CommandError('Sorry could not locate models');
            endif;
            
            $number = $input->getOption('records');
            if (!$number):
                $number = 5;
            endif;
            
            $ignore = array_change_key_case(
                array_flip($input->getOption('ignore')),
                CASE_LOWER
            );
            $only = array_change_key_case(
                array_flip($input->getOption('only')),
                CASE_LOWER
            );
            
            if ($ignore && $only):
                throw new CommandError(
                    "Its not allowed to set both ".
                    "'only' and 'ignore' options at the same time"
                );
            endif;
            
            /** @var $model Model */
            foreach ($models as $name => $model) :
                
                if ($model->getMeta()->autoCreated ||
                    array_key_exists(strtolower($name), $ignore)):
                    continue;
                endif;
                
                $populator->addModel($model, $number);
            endforeach;
            
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
             ->addOption(
                 'seed',
                 '-s',
                 InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY,
                 'Always the same generated data. '.
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
        foreach ($names as $name) :
            $models[] = BaseOrm::getRegistry()->getModel($name);
        endforeach;
        
        return $models;
    }
}

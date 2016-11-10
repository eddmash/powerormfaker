<?php

/*
* This file is part of the ci306 package.
*
* (c) Eddilbert Macharia <edd.cowan@gmail.com>
*
* For the full copyright and license information, please view the LICENSE
* file that was distributed with this source code.
*/

namespace Eddmash\PowerOrmFaker\Console\Command;

use Eddmash\PowerOrm\BaseOrm;
use Eddmash\PowerOrm\Console\Command\BaseCommand;
use Eddmash\PowerOrm\Exception\CommandError;
use Eddmash\PowerOrm\Model\Model;
use Eddmash\PowerOrmFaker\Populator;
use Faker\Factory;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class Generatedata extends BaseCommand
{
    public $help = 'Generate sample data for your models.';

    public function handle(InputInterface $input, OutputInterface $output)
    {
        $faker = Factory::create();
        $populator = new Populator($faker);

        $models = BaseOrm::getRegistry()->getModels(true);

        $number = $input->getOption('records');
        if(!$number):
            $number = 5;
        endif;

        $ignore = array_change_key_case(array_flip($input->getOption('ignore')), CASE_LOWER);
        $only = array_change_key_case(array_flip($input->getOption('only')), CASE_LOWER);

        if($ignore && $only):
            throw new CommandError("Its not allowed to set both 'only' and 'ignore' options at the same time");
        endif;

        /** @var $model Model */
        foreach ($models as $name => $model) :

            if($model->meta->autoCreated || array_key_exists(strtolower($name), $ignore)):
                continue;
            endif;

            $populator->addModel($model, $number);
        endforeach;

        list($insertedPKs, $failed) = $populator->execute($output);

        if($failed):
            foreach ($failed as $model => $related) :
                $output->writeln(sprintf('<error>Failed for model "%s", could not locate related model(s) [%s]</error>',
                    $model, implode(', ', $related)));
            endforeach;

        endif;

        return $insertedPKs;
    }

    protected function configure()
    {
        $this->setName($this->guessCommandName())
            ->setDescription($this->help)
            ->setHelp($this->help)
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

}

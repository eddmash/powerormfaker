<?php
/**
 * Created by PhpStorm.
 * User: edd
 * Date: 12/25/17
 * Time: 7:44 PM.
 */

namespace Eddmash\PowerOrmFaker\Commands;

use Eddmash\PowerOrm\Console\Command\BaseCommand;

class Command extends BaseCommand
{
    public function guessCommandName()
    {
        return sprintf('faker:%s', parent::guessCommandName());
    }
}

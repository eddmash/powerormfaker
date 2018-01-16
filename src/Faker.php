<?php

/**
 * This file is part of the powercomponents package.
 *
 * (c) Eddilbert Macharia (http://eddmash.com)<edd.cowan@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Eddmash\PowerOrmFaker;

use Eddmash\PowerOrm\BaseOrm;
use Eddmash\PowerOrm\Components\Component;
use Eddmash\PowerOrmFaker\Commands\Generatedata;

class Faker extends Component
{
    public function ready(BaseOrm $baseOrm)
    {
    }

    /**
     * Name to use when querying this component.
     *
     * @return mixed
     *
     * @since 1.1.0
     *
     * @author Eddilbert Macharia (http://eddmash.com) <edd.cowan@gmail.com>
     */
    public function getName()
    {
        return 'faker';
    }

    /**
     * Command classes.
     *
     * @return array
     *
     * @since 1.1.0
     *
     * @author Eddilbert Macharia (http://eddmash.com) <edd.cowan@gmail.com>
     */
    public function getCommands()
    {
        return [
            Generatedata::class,
        ];
    }
}
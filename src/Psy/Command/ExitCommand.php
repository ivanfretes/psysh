<?php

/*
 * This file is part of PsySH
 *
 * (c) 2012 Justin Hileman
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Psy\Command;

use Psy\Command\Command;
use Psy\Exception\BreakException;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Exit PsySH.
 *
 * Just what it says on the tin.
 */
class ExitCommand extends Command
{
    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this
            ->setName('exit')
            ->setAliases(array('quit', 'q'))
            ->setDefinition(array())
            ->setDescription('End the current session and return to caller.')
            ->setHelp(<<<EOL
End the current session and return to caller.

e.g.
<return>>>> exit</return>
EOL
            )
        ;
    }

    /**
     * {@inheritdoc}
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        throw new BreakException('<strong>Goodbye.</strong>');
    }
}
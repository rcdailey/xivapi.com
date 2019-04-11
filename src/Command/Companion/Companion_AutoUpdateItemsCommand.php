<?php

namespace App\Command\Companion;

use App\Command\CommandConfigureTrait;
use App\Service\Companion\Updater\MarketUpdater;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class Companion_AutoUpdateItemsCommand extends Command
{
    use CommandConfigureTrait;
    
    const COMMAND = [
        'name' => 'Companion_AutoUpdateItemsCommand',
        'desc' => 'Auto-Update prices and history of all items on all servers.',
        'args' => [
            [ 'priority',      InputArgument::OPTIONAL, 'Item priority queue to process' ],
            [ 'queue',         InputArgument::OPTIONAL, 'Queue number, this should be incremental' ],
            [ 'queue_patreon', InputArgument::OPTIONAL, 'Update a patreon queue' ],
        ]
    ];

    /** @var MarketUpdater */
    private $marketUpdater;

    public function __construct(
        MarketUpdater $marketUpdater,
        $name = null
    ) {
        $this->marketUpdater = $marketUpdater;

        parent::__construct($name);
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        /**
         * php bin/console Companion_AutoUpdateCommand 10 1
         * php bin/console Companion_AutoUpdateCommand 10 1 1234
         * php bin/console Companion_AutoUpdateCommand 10 1 1234
         */
        $this->marketUpdater->update(
            $input->getArgument('priority'),
            $input->getArgument('queue'),
            $input->getArgument('queue_patreon') ?: null
        );
    }
}
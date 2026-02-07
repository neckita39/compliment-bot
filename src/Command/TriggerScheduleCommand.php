<?php

namespace App\Command;

use App\Message\SendScheduledCompliment;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Messenger\MessageBusInterface;

#[AsCommand(
    name: 'app:trigger-schedule',
    description: 'Dispatch scheduled compliment message'
)]
class TriggerScheduleCommand extends Command
{
    public function __construct(
        private MessageBusInterface $bus
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->bus->dispatch(new SendScheduledCompliment());
        $output->writeln('[' . date('Y-m-d H:i:s') . '] Scheduled message dispatched');
        
        return Command::SUCCESS;
    }
}

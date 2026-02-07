<?php

namespace App\Scheduler;

use App\Message\SendScheduledCompliment;
use Symfony\Component\Scheduler\Attribute\AsSchedule;
use Symfony\Component\Scheduler\RecurringMessage;
use Symfony\Component\Scheduler\Schedule;
use Symfony\Component\Scheduler\ScheduleProviderInterface;

#[AsSchedule('compliments')]
class ComplimentSchedule implements ScheduleProviderInterface
{
    public function getSchedule(): Schedule
    {
        $schedule = new Schedule();

        // Check every minute for users whose time has come
        $schedule->add(
            RecurringMessage::cron('* * * * *', new SendScheduledCompliment())
        );

        return $schedule;
    }
}

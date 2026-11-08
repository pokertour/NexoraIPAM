<?php

use App\Console\Commands\QueueDhcpImport;
use App\Console\Commands\QueueScheduledDevicePolls;
use App\Console\Commands\QueueScheduledDiscoveries;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command(QueueScheduledDiscoveries::class)
    ->everyMinute()->withoutOverlapping(10);

Schedule::command(QueueScheduledDevicePolls::class)
    ->everyMinute()->withoutOverlapping(10);

Schedule::command(QueueDhcpImport::class)
    ->everyMinute()->withoutOverlapping(10);

<?php

use Illuminate\Support\Facades\Schedule;

Schedule::command('review:sweep-runs')->hourly();
Schedule::command('review:sync-model-prices')->dailyAt('04:30');
Schedule::command('review:sync-exchange-rates')->dailyAt('04:35');
Schedule::command('review:prune-logs')->dailyAt('04:40');
Schedule::command('review:prune-caches')->dailyAt('04:45');
// Whose turn the open pull requests are, where a delivery was missed.
Schedule::command('review:sync-pull-requests')->dailyAt('04:50');
// The numbers behind Horizon's metrics page.
Schedule::command('horizon:snapshot')->everyFiveMinutes();
// What the installation is doing, for the admin panel's capacity figures.
Schedule::command('review:sample-capacity')->everyMinute();

<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
|--------------------------------------------------------------------------
| The scheduled engine
|--------------------------------------------------------------------------
|
| Sprint 2's exit criterion is that points mature and expire "with nobody
| running anything". This is the nobody.
|
| Everything below is idempotent, so a missed run is caught up by the next one
| rather than needing a human to work out what was skipped. That is why each
| carries withoutOverlapping() and none carries any state of its own: the ledger
| is the state, and every sweep asks it what still needs doing.
|
| Times are in the application timezone. The birthday job takes its own day from
| the shop's reporting timezone (Europe/London), because a member's birthday is
| a date in OSC's day, not in UTC.
|
| In production this needs one cron entry:
|
|     * * * * * cd /path/to/web && php artisan schedule:run >> /dev/null 2>&1
|
| and a queue worker for the webhook-driven work that lands beside it.
*/

// Hourly. A member whose pending period ended at nine in the morning should not
// wait until midnight to see their points.
Schedule::command('loyalty:mature-points')
    ->hourly()
    ->withoutOverlapping()
    ->runInBackground();

// Daily, and deliberately after maturity: points that mature and expire on the
// same day must do both in that order, not in whichever order cron fired.
Schedule::command('loyalty:expire-points')
    ->dailyAt('02:10')
    ->withoutOverlapping();

// Early, so a member's birthday reward is waiting when they wake up rather than
// arriving during the afternoon.
Schedule::command('loyalty:issue-birthday-rewards')
    ->dailyAt('00:20')
    ->withoutOverlapping();

// Nightly. Cheap, derived, and identical on a re-run, so the only cost of
// running it often is the reading.
Schedule::command('loyalty:recalculate-segments')
    ->dailyAt('03:00')
    ->withoutOverlapping();

// Often, because a quote is short-lived by design. A twenty minute quote swept
// every five minutes lives at most twenty-five, which is close enough to what
// the member was told - and an entitlement left published after a member's
// points have expired is the one way a redemption could spend points nobody
// has.
Schedule::command('loyalty:expire-quotes')
    ->everyFiveMinutes()
    ->withoutOverlapping();

// Changes nothing and exits non-zero on drift, so this is the one that should
// alarm. Run after the sweeps have moved everything they are going to move.
Schedule::command('loyalty:verify-ledger')
    ->dailyAt('04:00')
    ->withoutOverlapping();

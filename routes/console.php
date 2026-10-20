<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('app:send-usage-reminders')->dailyAt('09:00');
Schedule::command('app:sync-clients')->daily();
// Drop expired password-reset rows so old links don't linger in the database.
Schedule::command('auth:clear-resets')->dailyAt('03:30');
// Cut off dedicated-ISP lines over quota (and revive recovered ones). Relay
// agents report usage every 2 min; enforce a little behind that. Only re-pushes
// the relay config when a line's status actually flips, so idle runs are cheap.
Schedule::command('isp:enforce-traffic')->everyFiveMinutes()->withoutOverlapping();
// Self-heal the relay: reconcile config to every node, auto-repairing one
// that was unreachable (e.g. 主力 suspended for bandwidth) the moment its SSH
// is back. Content-hash guarded, so a node already in sync is skipped — no
// reload, no dropped connections — making the frequent cadence cheap.
Schedule::command('relay:sync')->everyThreeMinutes()->withoutOverlapping();
// Proactive health alerts to platform admins (Telegram) — catch a node/relay
// going down or an onip balance running low BEFORE customers hit it.
Schedule::command('nodes:health-check')->everyFiveMinutes()->withoutOverlapping();
Schedule::command('onip:balance-check')->dailyAt('09:05');

<?php

use Illuminate\Support\Facades\Schedule;

Schedule::command('geo:process-outbox')->everyMinute()->withoutOverlapping();
Schedule::command('billing:renew')->everyMinute()->withoutOverlapping();
Schedule::command('billing:reconcile')->everyFiveMinutes()->withoutOverlapping();
Schedule::command('geo:process-privacy')->everyMinute()->withoutOverlapping();
Schedule::command('geo:maintain-locations')->hourly()->withoutOverlapping();

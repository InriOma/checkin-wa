<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schedule;

Schedule::command('attendance:detect-incidents')->everyFifteenMinutes()->withoutOverlapping();

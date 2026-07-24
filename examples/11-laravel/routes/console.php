<?php

declare(strict_types=1);

use BugBoard\Laravel\Facades\BugBoard;
use Illuminate\Support\Facades\Schedule;

/**
 * Report a failed scheduled task. The title is stable, so repeated failures
 * dedupe into one card.
 */
Schedule::command('reports:generate')
    ->daily()
    ->onFailure(function () {
        BugBoard::major('Scheduled task failed: reports:generate', null, ['scheduler']);
    });

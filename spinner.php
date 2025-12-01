#!/usr/bin/env php
<?php

declare(strict_types=1);

use Two13Tec\L10nGuy\Utility\ProgressIndicator;

$autoloadPath = __DIR__ . '/../../Packages/Libraries/autoload.php';
if (!file_exists($autoloadPath)) {
    fwrite(STDERR, "Autoloader not found at {$autoloadPath}\n");
    exit(1);
}

require $autoloadPath;

$indicator = new ProgressIndicator('%d ticks (Ctrl-C to stop)');
$ticks = 0;
$running = true;

if (function_exists('pcntl_async_signals')) {
    pcntl_async_signals(true);
    pcntl_signal(SIGINT, function () use (&$running, $indicator): void {
        $indicator->finish('Interrupted.');
        $running = false;
    });
}

while ($running) {
    $ticks++;
    $indicator->tick($ticks, $ticks);
    usleep(500000);
}

if ($running) {
    $indicator->finish();
}

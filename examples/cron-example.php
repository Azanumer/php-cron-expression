<?php
require __DIR__ . '/../src/CronExpression.php';

// Weekday 9am standup, plus every 15 minutes during work hours.
$expressions = array(
    '0 9 * * MON-FRI',
    '*/15 9-17 * * *',
    '@daily',
);

foreach ($expressions as $expr) {
    $cron = CronExpression::fromString($expr);
    $now = new DateTimeImmutable('2026-10-26 08:47'); // A Monday.
    echo $expr . " — due now? " . ($cron->isDue($now) ? 'yes' : 'no') . PHP_EOL;
    foreach ($cron->getNextRuns($now, 3) as $run) {
        echo '  next: ' . $run->format('Y-m-d H:i (l)') . PHP_EOL;
    }
}

// Invalid expressions fail loudly instead of silently mis-scheduling.
try {
    CronExpression::fromString('0 0 * *'); // Only 4 fields.
} catch (InvalidArgumentException $e) {
    echo 'Rejected: ' . $e->getMessage() . PHP_EOL;
}

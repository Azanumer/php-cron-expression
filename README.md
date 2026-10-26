# PHP Cron Expression

A dependency-free cron expression parser for PHP: check whether a moment is due, and compute upcoming run times. Useful for custom schedulers, queue workers, and any app that stores schedules as cron strings without pulling in a framework.

## Features

- Standard 5-field expressions: `minute hour day-of-month month day-of-week`
- Lists (`1,15,30`), ranges (`9-17`), steps (`*/15`, `1-30/5`)
- Month and weekday names (`JAN-DEC`, `MON-SUN`); both `0` and `7` mean Sunday
- Shortcuts: `@yearly`, `@annually`, `@monthly`, `@weekly`, `@daily`, `@midnight`, `@hourly`
- Correct day rule: when both day-of-month and day-of-week are restricted, a day matches if **either** matches (standard cron semantics, easy to get wrong)
- Loud failures: wrong field count, out-of-range values, bad steps and bad names all throw `InvalidArgumentException` — a bad schedule never silently runs at the wrong time

## Usage

```php
require 'src/CronExpression.php';

$cron = CronExpression::fromString('0 9 * * MON-FRI');

$cron->isDue(new DateTimeImmutable('2026-10-26 09:00')); // true (Monday)
$cron->getNextRun(new DateTimeImmutable('2026-10-26 09:01'));
// -> 2026-10-27 09:00

foreach ($cron->getNextRuns(new DateTimeImmutable(), 5) as $run) {
    echo $run->format('Y-m-d H:i'), PHP_EOL;
}
```

A 6-field expression (with a seconds field) is rejected explicitly — silently ignoring the extra field would schedule everything wrong.

## Example

Run `php examples/cron-example.php` for a runnable demo, including the invalid-expression rejection.

## Requirements

PHP 7.2+ (no extensions required). Works on PHP 8.x.

## License

MIT

<?php
/**
 * CronExpression — dependency-free cron expression parser.
 *
 * Supports the standard 5-field form (minute hour day-of-month month day-of-week)
 * plus @yearly, @monthly, @weekly, @daily, @midnight and @hourly shortcuts.
 * Each field supports: * lists (1,5,9) ranges (1-5) steps (*\/15, 1-30/5)
 * and month/day names (JAN-DEC, MON-SUN). Day-of-week accepts both 0 and 7
 * for Sunday. Day-of-month and day-of-week follow cron's OR rule: when both
 * are restricted, a day matches if EITHER field matches.
 *
 * Example:
 *   $cron = CronExpression::fromString('0 9 * * MON-FRI');
 *   $cron->isDue(new DateTimeImmutable('2026-10-26 09:00')); // true (Monday)
 *   $cron->getNextRun(new DateTimeImmutable('2026-10-26 09:01'));
 */

class CronExpression
{
    /** @var array<int, array<int, bool>> Parsed sets per field. */
    private $fields = array();

    /** @var array<string, string> Shortcut -> expression. */
    private static $shortcuts = array(
        '@yearly'   => '0 0 1 1 *',
        '@annually' => '0 0 1 1 *',
        '@monthly'  => '0 0 1 * *',
        '@weekly'   => '0 0 * * 0',
        '@daily'    => '0 0 * * *',
        '@midnight' => '0 0 * * *',
        '@hourly'   => '0 * * * *',
    );

    private static $months = array(
        'JAN' => 1, 'FEB' => 2, 'MAR' => 3, 'APR' => 4, 'MAY' => 5, 'JUN' => 6,
        'JUL' => 7, 'AUG' => 8, 'SEP' => 9, 'OCT' => 10, 'NOV' => 11, 'DEC' => 12,
    );

    private static $weekdays = array(
        'SUN' => 0, 'MON' => 1, 'TUE' => 2, 'WED' => 3,
        'THU' => 4, 'FRI' => 5, 'SAT' => 6,
    );

    private function __construct(array $fields)
    {
        $this->fields = $fields;
    }

    /**
     * Parse an expression. Throws InvalidArgumentException on bad input.
     * A 6-field expression (with a leading seconds field) is rejected
     * explicitly, since silently ignoring it would schedule wrongly.
     */
    public static function fromString($expression)
    {
        $expression = trim($expression);
        if (isset(self::$shortcuts[strtolower($expression)])) {
            $expression = self::$shortcuts[strtolower($expression)];
        }
        $parts = preg_split('/\s+/', $expression);
        if (count($parts) !== 5) {
            throw new InvalidArgumentException(
                'Cron expression must have exactly 5 fields (minute hour day-of-month month day-of-week)'
            );
        }
        $ranges = array(
            array(0, 59), array(0, 23), array(1, 31), array(1, 12), array(0, 7),
        );
        $fields = array();
        foreach ($parts as $i => $part) {
            $fields[] = self::parseField($part, $ranges[$i][0], $ranges[$i][1], $i);
        }
        // Normalise day-of-week: 7 is Sunday too.
        if (isset($fields[4][7])) {
            $fields[4][0] = true;
            unset($fields[4][7]);
        }
        return new self($fields);
    }

    private static function translateName($token, $fieldIndex)
    {
        $upper = strtoupper($token);
        if ($fieldIndex === 3 && isset(self::$months[$upper])) {
            return self::$months[$upper];
        }
        if ($fieldIndex === 4 && isset(self::$weekdays[$upper])) {
            return self::$weekdays[$upper];
        }
        return $token;
    }

    /** Parse one field into a set of allowed values. */
    private static function parseField($field, $min, $max, $fieldIndex)
    {
        $values = array();
        foreach (explode(',', $field) as $item) {
            $step = 1;
            if (strpos($item, '/') !== false) {
                list($item, $step) = explode('/', $item, 2);
                if (!ctype_digit((string) $step) || (int) $step < 1) {
                    throw new InvalidArgumentException("Invalid step in field: {$field}");
                }
                $step = (int) $step;
            }
            if ($item === '*') {
                $from = $min;
                $to = $max;
            } elseif (strpos($item, '-') !== false) {
                list($from, $to) = explode('-', $item, 2);
                $from = self::translateName($from, $fieldIndex);
                $to = self::translateName($to, $fieldIndex);
                if (!is_numeric($from) || !is_numeric($to)) {
                    throw new InvalidArgumentException("Invalid range in field: {$field}");
                }
                $from = (int) $from;
                $to = (int) $to;
            } else {
                $single = self::translateName($item, $fieldIndex);
                if (!is_numeric($single)) {
                    throw new InvalidArgumentException("Invalid value in field: {$field}");
                }
                $from = $to = (int) $single;
            }
            if ($from < $min || $to > $max || $from > $to) {
                throw new InvalidArgumentException("Value out of range in field: {$field}");
            }
            for ($v = $from; $v <= $to; $v += $step) {
                $values[$v] = true;
            }
        }
        if (empty($values)) {
            throw new InvalidArgumentException("Empty field: {$field}");
        }
        return $values;
    }

    /** Is the given moment due? Seconds are ignored (minute precision). */
    public function isDue(DateTimeInterface $when)
    {
        $minute = (int) $when->format('i');
        $hour = (int) $when->format('G');
        $dom = (int) $when->format('j');
        $month = (int) $when->format('n');
        $dow = (int) $when->format('w');
        if (!isset($this->fields[0][$minute]) || !isset($this->fields[1][$hour])
            || !isset($this->fields[3][$month])) {
            return false;
        }
        // Cron's OR rule: when both day fields are restricted, either may match.
        $domRestricted = count($this->fields[2]) < 31;
        $dowRestricted = count($this->fields[4]) < 7;
        $domMatch = isset($this->fields[2][$dom]);
        $dowMatch = isset($this->fields[4][$dow]);
        if ($domRestricted && $dowRestricted) {
            return $domMatch || $dowMatch;
        }
        return $domMatch && $dowMatch;
    }

    /**
     * Next run strictly after $from (minute-stepped, up to 366 days out).
     * Returns null only for impossible schedules such as Feb 31.
     */
    public function getNextRun(DateTimeInterface $from)
    {
        $candidate = DateTimeImmutable::createFromInterface($from)
            ->setTime((int) $from->format('G'), (int) $from->format('i'), 0)
            ->modify('+1 minute');
        $limit = 366 * 24 * 60;
        for ($i = 0; $i < $limit; $i++) {
            if ($this->isDue($candidate)) {
                return $candidate;
            }
            $candidate = $candidate->modify('+1 minute');
        }
        return null;
    }

    /** Next $count runs after $from. */
    public function getNextRuns(DateTimeInterface $from, $count = 5)
    {
        $runs = array();
        $cursor = $from;
        for ($i = 0; $i < $count; $i++) {
            $next = $this->getNextRun($cursor);
            if ($next === null) {
                break;
            }
            $runs[] = $next;
            $cursor = $next;
        }
        return $runs;
    }
}

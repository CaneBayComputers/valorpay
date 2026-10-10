<?php

namespace CaneBayComputers\ValorPay\Subscriptions;

/**
 * How often a subscription charges: ValorPay's recurring_type codes.
 */
enum Frequency: int
{
    case Weekly = 0;
    case BiWeekly = 1;
    case Monthly = 2;
    case Quarterly = 3;
    case Annually = 4;
    case Daily = 5;
    case RunAt = 6;

    /**
     * Weekly schedules take a weekday for charge_on (0-6); the rest take a day of the month (1-30).
     */
    public function isWeekBased(): bool
    {
        return $this === self::Weekly || $this === self::BiWeekly;
    }
}

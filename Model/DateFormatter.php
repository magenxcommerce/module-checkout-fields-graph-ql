<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\CheckoutFieldsGraphQl\Model;

use Magento\Framework\Stdlib\DateTime\TimezoneInterface;

/**
 * Formats a stored delivery date for people (admin order view, emails).
 */
class DateFormatter
{
    /**
     * @param TimezoneInterface $timezone
     */
    public function __construct(
        private readonly TimezoneInterface $timezone
    ) {
    }

    /**
     * Normalize a DATE column value to YYYY-MM-DD, or '' when unset/invalid.
     *
     * @param mixed $value
     * @return string
     */
    public static function toIso(mixed $value): string
    {
        $date = substr(trim((string) $value), 0, 10);

        return Config::isIsoDate($date) ? $date : '';
    }

    /**
     * Long, locale-aware date ("Tuesday, 20 October 2026") in the current
     * locale. Formatted in UTC so a calendar date is never shifted by a time
     * zone conversion.
     *
     * @param mixed $value
     * @return string
     */
    public function format(mixed $value): string
    {
        $iso = self::toIso($value);
        if ($iso === '') {
            return '';
        }

        return (string) $this->timezone->formatDateTime(
            new \DateTime($iso, new \DateTimeZone('UTC')),
            \IntlDateFormatter::FULL,
            \IntlDateFormatter::NONE,
            null,
            'UTC'
        );
    }
}

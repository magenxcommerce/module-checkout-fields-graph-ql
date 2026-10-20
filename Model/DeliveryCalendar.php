<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\CheckoutFieldsGraphQl\Model;

use Magento\Framework\Stdlib\DateTime\TimezoneInterface;

/**
 * Computes which delivery dates a shopper may pick, in the store time zone.
 *
 * Single source of truth for the rules: the storefront calendar renders the
 * list this returns, the mutation validates against it, and the order
 * submit observer re-checks against it (the cut-off can pass between the
 * shopper picking a date and paying).
 */
class DeliveryCalendar
{
    /**
     * @param Config $config
     * @param TimezoneInterface $timezone
     */
    public function __construct(
        private readonly Config $config,
        private readonly TimezoneInterface $timezone
    ) {
    }

    /**
     * Every selectable date (YYYY-MM-DD), ascending.
     *
     * @param int $storeId
     * @return string[]
     */
    public function getAvailableDates(int $storeId): array
    {
        $now = \DateTimeImmutable::createFromMutable($this->timezone->scopeDate($storeId, null, true));
        $today = $now->setTime(0, 0);

        $lead = $this->config->getLeadDays($storeId);
        $cutoff = $this->config->getCutoffTime($storeId);
        if ($cutoff !== null && $now >= $today->setTime($cutoff[0], $cutoff[1])) {
            $lead++;
        }

        $maxDays = $this->config->getMaxDays($storeId);
        $excluded = array_flip($this->config->getExcludedWeekdays($storeId));
        $blackout = $this->config->getBlackoutDates($storeId);

        $dates = [];
        for ($offset = $lead; $offset <= $maxDays; $offset++) {
            $day = $today->modify('+' . $offset . ' days');
            $iso = $day->format('Y-m-d');
            if (isset($excluded[(int) $day->format('w')]) || isset($blackout[$iso])) {
                continue;
            }
            $dates[] = $iso;
        }

        return $dates;
    }

    /**
     * Whether the given YYYY-MM-DD date is currently selectable.
     *
     * @param string $date
     * @param int $storeId
     * @return bool
     */
    public function isAvailable(string $date, int $storeId): bool
    {
        return $this->config->isIsoDate($date) && in_array($date, $this->getAvailableDates($storeId), true);
    }
}

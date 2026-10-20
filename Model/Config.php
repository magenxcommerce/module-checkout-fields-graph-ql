<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\CheckoutFieldsGraphQl\Model;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\ObjectManager\ResetAfterRequestInterface;
use Magento\Framework\Serialize\Serializer\Json;
use Magento\Store\Model\ScopeInterface;

/**
 * Typed access to Stores → Configuration → Magenx → Checkout Fields.
 */
class Config implements ResetAfterRequestInterface
{
    private const XML_PATH_NOTE_ENABLED = 'magenx_checkout_fields/order_note/enabled';
    private const XML_PATH_NOTE_MAX_LENGTH = 'magenx_checkout_fields/order_note/max_length';
    private const XML_PATH_DATE_ENABLED = 'magenx_checkout_fields/delivery_date/enabled';
    private const XML_PATH_DATE_REQUIRED = 'magenx_checkout_fields/delivery_date/required';
    private const XML_PATH_LEAD_DAYS = 'magenx_checkout_fields/delivery_date/lead_days';
    private const XML_PATH_MAX_DAYS = 'magenx_checkout_fields/delivery_date/max_days';
    private const XML_PATH_CUTOFF = 'magenx_checkout_fields/delivery_date/cutoff_time';
    private const XML_PATH_EXCLUDED_WEEKDAYS = 'magenx_checkout_fields/delivery_date/excluded_weekdays';
    private const XML_PATH_BLACKOUT = 'magenx_checkout_fields/delivery_date/blackout_dates';
    private const XML_PATH_SLOTS = 'magenx_checkout_fields/delivery_date/slots';

    /** Hard ceiling on the calendar window, whatever the admin enters. */
    public const MAX_DAYS_CEILING = 365;

    /** Fallback when the configured maximum length is blank or zero. */
    private const DEFAULT_NOTE_MAX_LENGTH = 500;

    /**
     * Decoded slots, memoized per store for the lifetime of this request.
     *
     * @var array<string, array<int, array{code: string, label: string}>>
     */
    private array $slotsCache = [];

    /**
     * @param ScopeConfigInterface $scopeConfig
     * @param Json $serializer
     */
    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly Json $serializer
    ) {
    }

    /**
     * @inheritDoc
     */
    public function _resetState(): void
    {
        $this->slotsCache = [];
    }

    /**
     * Whether the order comment box is offered.
     *
     * @param int|null $storeId
     * @return bool
     */
    public function isOrderNoteEnabled(?int $storeId = null): bool
    {
        return $this->scopeConfig->isSetFlag(self::XML_PATH_NOTE_ENABLED, ScopeInterface::SCOPE_STORE, $storeId);
    }

    /**
     * Longest order comment accepted, in characters.
     *
     * @param int|null $storeId
     * @return int
     */
    public function getOrderNoteMaxLength(?int $storeId = null): int
    {
        $value = (int) $this->scopeConfig->getValue(
            self::XML_PATH_NOTE_MAX_LENGTH,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );

        return $value > 0 ? $value : self::DEFAULT_NOTE_MAX_LENGTH;
    }

    /**
     * Whether delivery dates are offered at all.
     *
     * @param int|null $storeId
     * @return bool
     */
    public function isDeliveryDateEnabled(?int $storeId = null): bool
    {
        return $this->scopeConfig->isSetFlag(self::XML_PATH_DATE_ENABLED, ScopeInterface::SCOPE_STORE, $storeId);
    }

    /**
     * Whether an order cannot be placed without a delivery date.
     *
     * @param int|null $storeId
     * @return bool
     */
    public function isDeliveryDateRequired(?int $storeId = null): bool
    {
        return $this->isDeliveryDateEnabled($storeId)
            && $this->scopeConfig->isSetFlag(self::XML_PATH_DATE_REQUIRED, ScopeInterface::SCOPE_STORE, $storeId);
    }

    /**
     * Earliest selectable date, in days from today.
     *
     * @param int|null $storeId
     * @return int
     */
    public function getLeadDays(?int $storeId = null): int
    {
        $value = (int) $this->scopeConfig->getValue(self::XML_PATH_LEAD_DAYS, ScopeInterface::SCOPE_STORE, $storeId);

        return min(max($value, 0), self::MAX_DAYS_CEILING);
    }

    /**
     * Latest selectable date, in days from today.
     *
     * @param int|null $storeId
     * @return int
     */
    public function getMaxDays(?int $storeId = null): int
    {
        $value = (int) $this->scopeConfig->getValue(self::XML_PATH_MAX_DAYS, ScopeInterface::SCOPE_STORE, $storeId);

        return min(max($value, 1), self::MAX_DAYS_CEILING);
    }

    /**
     * The daily cut-off as [hour, minute], or null when none (or malformed).
     *
     * @param int|null $storeId
     * @return array{0: int, 1: int}|null
     */
    public function getCutoffTime(?int $storeId = null): ?array
    {
        $raw = trim((string) $this->scopeConfig->getValue(self::XML_PATH_CUTOFF, ScopeInterface::SCOPE_STORE, $storeId));
        if (!preg_match('/^([01]?\d|2[0-3]):([0-5]\d)$/', $raw, $matches)) {
            return null;
        }

        return [(int) $matches[1], (int) $matches[2]];
    }

    /**
     * Weekdays without delivery, as PHP `w` numbers (0 = Sunday … 6 = Saturday).
     *
     * @param int|null $storeId
     * @return int[]
     */
    public function getExcludedWeekdays(?int $storeId = null): array
    {
        $raw = (string) $this->scopeConfig->getValue(
            self::XML_PATH_EXCLUDED_WEEKDAYS,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
        $days = [];
        foreach (explode(',', $raw) as $part) {
            $part = trim($part);
            if ($part !== '' && ctype_digit($part) && (int) $part <= 6) {
                $days[] = (int) $part;
            }
        }

        return array_values(array_unique($days));
    }

    /**
     * Closed dates as a YYYY-MM-DD set. Malformed lines are ignored.
     *
     * @param int|null $storeId
     * @return array<string, true>
     */
    public function getBlackoutDates(?int $storeId = null): array
    {
        $raw = (string) $this->scopeConfig->getValue(self::XML_PATH_BLACKOUT, ScopeInterface::SCOPE_STORE, $storeId);
        $dates = [];
        foreach (preg_split('/[\r\n,]+/', $raw) ?: [] as $line) {
            $line = trim($line);
            if ($this->isIsoDate($line)) {
                $dates[$line] = true;
            }
        }

        return $dates;
    }

    /**
     * The configured time slots, in admin order. Rows without a code or label
     * are skipped; duplicate codes keep the first row.
     *
     * @param int|null $storeId
     * @return array<int, array{code: string, label: string}>
     */
    public function getSlots(?int $storeId = null): array
    {
        $cacheKey = (string) $storeId;
        if (isset($this->slotsCache[$cacheKey])) {
            return $this->slotsCache[$cacheKey];
        }

        $raw = (string) $this->scopeConfig->getValue(self::XML_PATH_SLOTS, ScopeInterface::SCOPE_STORE, $storeId);
        if ($raw === '') {
            return $this->slotsCache[$cacheKey] = [];
        }

        try {
            $decoded = $this->serializer->unserialize($raw);
        } catch (\InvalidArgumentException $e) {
            return $this->slotsCache[$cacheKey] = [];
        }

        if (!is_array($decoded)) {
            return $this->slotsCache[$cacheKey] = [];
        }

        $slots = [];
        foreach ($decoded as $row) {
            if (!is_array($row)) {
                continue;
            }
            $code = isset($row['code']) ? trim((string) $row['code']) : '';
            $label = isset($row['label']) ? trim((string) $row['label']) : '';
            if ($code === '' || $label === '' || isset($slots[$code]) || strlen($code) > 32) {
                continue;
            }
            $slots[$code] = ['code' => $code, 'label' => $label];
        }

        return $this->slotsCache[$cacheKey] = array_values($slots);
    }

    /**
     * Resolve a stored slot code for display. A slot removed from config since
     * the order was placed falls back to its code as the label.
     *
     * @param string|null $code
     * @param int|null $storeId
     * @return array{code: string, label: string}|null
     */
    public function describeSlot(?string $code, ?int $storeId = null): ?array
    {
        $code = trim((string) $code);
        if ($code === '') {
            return null;
        }
        foreach ($this->getSlots($storeId) as $slot) {
            if ($slot['code'] === $code) {
                return $slot;
            }
        }

        return ['code' => $code, 'label' => $code];
    }

    /**
     * Whether the string is a real calendar date in YYYY-MM-DD form.
     *
     * @param string $value
     * @return bool
     */
    public function isIsoDate(string $value): bool
    {
        if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m)) {
            return false;
        }

        return checkdate((int) $m[2], (int) $m[3], (int) $m[1]);
    }
}

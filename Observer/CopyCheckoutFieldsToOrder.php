<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\CheckoutFieldsGraphQl\Observer;

use Magenx\CheckoutFieldsGraphQl\Model\Config;
use Magenx\CheckoutFieldsGraphQl\Model\DateFormatter;
use Magenx\CheckoutFieldsGraphQl\Model\DeliveryApplicability;
use Magenx\CheckoutFieldsGraphQl\Model\DeliveryCalendar;
use Magento\Framework\App\Area;
use Magento\Framework\App\State;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Quote\Model\Quote;

/**
 * At order placement (event: sales_model_service_quote_submit_before):
 *
 *  1. Copies the order comment from the quote onto the order.
 *  2. When a delivery date applies ({@see DeliveryApplicability}), re-checks the
 *     stored date and slot against the calendar — the cut-off may have passed
 *     since the shopper picked them, e.g. during a payment redirect — and
 *     copies them. A stale date for In-Store Pickup is dropped, not copied.
 *
 * "Required" is only enforced for storefront (GraphQL) orders: an admin
 * creating an order in the backend has no delivery date field, and blocking
 * them would make phone orders impossible.
 */
class CopyCheckoutFieldsToOrder implements ObserverInterface
{
    /**
     * @param Config $config
     * @param DeliveryApplicability $applicability
     * @param DeliveryCalendar $calendar
     * @param State $appState
     */
    public function __construct(
        private readonly Config $config,
        private readonly DeliveryApplicability $applicability,
        private readonly DeliveryCalendar $calendar,
        private readonly State $appState
    ) {
    }

    /**
     * @param Observer $observer
     * @return void
     * @throws LocalizedException
     */
    public function execute(Observer $observer): void
    {
        $order = $observer->getEvent()->getData('order');
        $quote = $observer->getEvent()->getData('quote');
        if ($order === null || !$quote instanceof Quote) {
            return;
        }

        $storeId = (int) $quote->getStoreId();

        $note = (string) $quote->getData('magenx_order_note');
        if ($note !== '' && $this->config->isOrderNoteEnabled($storeId)) {
            $order->setData('magenx_order_note', $note);
        }

        if (!$this->applicability->isOffered($quote)) {
            return;
        }

        $date = DateFormatter::toIso($quote->getData('magenx_delivery_date'));
        if ($date === '') {
            if ($this->config->isDeliveryDateRequired($storeId) && $this->isGraphQlRequest()) {
                throw new LocalizedException(__('Please choose a delivery date.'));
            }
            return;
        }

        if (!$this->calendar->isAvailable($date, $storeId)) {
            throw new LocalizedException(
                __('The delivery date %1 is no longer available. Please choose another date.', $date)
            );
        }

        $slot = trim((string) $quote->getData('magenx_delivery_slot'));
        $slots = $this->config->getSlots($storeId);
        if ($slots !== [] && !in_array($slot, array_column($slots, 'code'), true)) {
            throw new LocalizedException(__('Please choose a delivery time slot.'));
        }

        $order->setData('magenx_delivery_date', $date);
        $order->setData('magenx_delivery_slot', $slots !== [] ? $slot : null);
    }

    /**
     * @return bool
     */
    private function isGraphQlRequest(): bool
    {
        try {
            return $this->appState->getAreaCode() === Area::AREA_GRAPHQL;
        } catch (LocalizedException $e) {
            return false;
        }
    }
}

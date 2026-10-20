<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\CheckoutFieldsGraphQl\Model\Resolver\Cart;

use Magenx\CheckoutFieldsGraphQl\Model\Config;
use Magenx\CheckoutFieldsGraphQl\Model\DateFormatter;
use Magenx\CheckoutFieldsGraphQl\Model\DeliveryApplicability;
use Magenx\CheckoutFieldsGraphQl\Model\DeliveryCalendar;
use Magento\Framework\GraphQl\Config\Element\Field;
use Magento\Framework\GraphQl\Exception\GraphQlInputException;
use Magento\Framework\GraphQl\Query\ResolverInterface;
use Magento\Framework\GraphQl\Schema\Type\ResolveInfo;
use Magento\Quote\Model\Quote;

/**
 * Resolves `Cart.checkout_fields`.
 *
 * Reads the values persisted on the quote and, when a delivery date applies,
 * the calendar computed by {@see DeliveryCalendar}. A single cart per request,
 * so no batching is needed; the calendar is only computed when the query
 * selects `delivery_options`.
 */
class CheckoutFields implements ResolverInterface
{
    /**
     * @param Config $config
     * @param DeliveryApplicability $applicability
     * @param DeliveryCalendar $calendar
     */
    public function __construct(
        private readonly Config $config,
        private readonly DeliveryApplicability $applicability,
        private readonly DeliveryCalendar $calendar
    ) {
    }

    /**
     * @inheritDoc
     */
    public function resolve(Field $field, $context, ResolveInfo $info, ?array $value = null, ?array $args = null)
    {
        if (!isset($value['model']) || !$value['model'] instanceof Quote) {
            throw new GraphQlInputException(__('"model" value should be specified.'));
        }

        /** @var Quote $quote */
        $quote = $value['model'];
        $storeId = (int) $quote->getStoreId();
        $offered = $this->applicability->isOffered($quote);
        $selected = $info->getFieldSelection(1);

        $note = (string) $quote->getData('magenx_order_note');
        $date = DateFormatter::toIso($quote->getData('magenx_delivery_date'));

        $options = null;
        if ($offered && isset($selected['delivery_options'])) {
            $options = [
                'required' => $this->config->isDeliveryDateRequired($storeId),
                'available_dates' => $this->calendar->getAvailableDates($storeId),
                'slots' => $this->config->getSlots($storeId),
            ];
        }

        return [
            'order_note_enabled' => $this->config->isOrderNoteEnabled($storeId),
            'order_note_max_length' => $this->config->getOrderNoteMaxLength($storeId),
            'order_note' => $note !== '' ? $note : null,
            'delivery_date' => $offered && $date !== '' ? $date : null,
            'delivery_slot' => $offered && $date !== ''
                ? $this->config->describeSlot((string) $quote->getData('magenx_delivery_slot'), $storeId)
                : null,
            'delivery_options' => $options,
        ];
    }
}

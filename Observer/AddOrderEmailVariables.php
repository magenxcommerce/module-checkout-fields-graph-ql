<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\CheckoutFieldsGraphQl\Observer;

use Magenx\CheckoutFieldsGraphQl\Model\Config;
use Magenx\CheckoutFieldsGraphQl\Model\DateFormatter;
use Magento\Framework\DataObject;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Sales\Api\Data\OrderInterface;

/**
 * Adds `magenx_order_note`, `magenx_delivery_date` (formatted in the store
 * locale) and `magenx_delivery_slot` (the slot label) to the order email
 * template variables (event: email_order_set_template_vars_before).
 *
 * Empty strings when unset, so a template can wrap them in {{depend}}.
 * `{{var}}` escapes HTML, so the shopper's comment is safe to print.
 */
class AddOrderEmailVariables implements ObserverInterface
{
    /**
     * @param Config $config
     * @param DateFormatter $dateFormatter
     */
    public function __construct(
        private readonly Config $config,
        private readonly DateFormatter $dateFormatter
    ) {
    }

    /**
     * @param Observer $observer
     * @return void
     */
    public function execute(Observer $observer): void
    {
        $transport = $observer->getEvent()->getData('transportObject');
        if (!$transport instanceof DataObject) {
            return;
        }

        $order = $transport->getData('order');
        if (!$order instanceof OrderInterface) {
            return;
        }

        $slot = $this->config->describeSlot(
            (string) $order->getData('magenx_delivery_slot'),
            (int) $order->getStoreId()
        );

        $transport->setData('magenx_order_note', (string) $order->getData('magenx_order_note'));
        $transport->setData('magenx_delivery_date', $this->dateFormatter->format($order->getData('magenx_delivery_date')));
        $transport->setData('magenx_delivery_slot', $slot['label'] ?? '');
    }
}

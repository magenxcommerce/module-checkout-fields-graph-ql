<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\CheckoutFieldsGraphQl\ViewModel;

use Magenx\CheckoutFieldsGraphQl\Model\Config;
use Magenx\CheckoutFieldsGraphQl\Model\DateFormatter;
use Magento\Framework\View\Element\Block\ArgumentInterface;
use Magento\Sales\Api\Data\OrderInterface;

/**
 * Display values for the admin order view's "Comment & Delivery" box.
 */
class OrderCheckoutFields implements ArgumentInterface
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
     * @param OrderInterface $order
     * @return string
     */
    public function getOrderNote(OrderInterface $order): string
    {
        return (string) $order->getData('magenx_order_note');
    }

    /**
     * @param OrderInterface $order
     * @return string
     */
    public function getDeliveryDate(OrderInterface $order): string
    {
        return $this->dateFormatter->format($order->getData('magenx_delivery_date'));
    }

    /**
     * @param OrderInterface $order
     * @return string
     */
    public function getDeliverySlot(OrderInterface $order): string
    {
        $slot = $this->config->describeSlot(
            (string) $order->getData('magenx_delivery_slot'),
            (int) $order->getStoreId()
        );

        return $slot['label'] ?? '';
    }
}

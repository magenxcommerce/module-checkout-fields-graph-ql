<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\CheckoutFieldsGraphQl\Model\Resolver\Order;

use Magenx\CheckoutFieldsGraphQl\Model\Config;
use Magenx\CheckoutFieldsGraphQl\Model\DateFormatter;
use Magento\Framework\GraphQl\Config\Element\Field;
use Magento\Framework\GraphQl\Exception\GraphQlInputException;
use Magento\Framework\GraphQl\Query\ResolverInterface;
use Magento\Framework\GraphQl\Schema\Type\ResolveInfo;
use Magento\Sales\Api\Data\OrderInterface;

/**
 * Resolves `CustomerOrder.checkout_fields`.
 *
 * Reads columns already on the loaded order model, so an order list costs no
 * extra queries. Null when the order carries neither a comment nor a date.
 */
class CheckoutFields implements ResolverInterface
{
    /**
     * @param Config $config
     */
    public function __construct(
        private readonly Config $config
    ) {
    }

    /**
     * @inheritDoc
     */
    public function resolve(Field $field, $context, ResolveInfo $info, ?array $value = null, ?array $args = null)
    {
        if (!isset($value['model']) || !$value['model'] instanceof OrderInterface) {
            throw new GraphQlInputException(__('"model" value should be specified.'));
        }

        /** @var OrderInterface $order */
        $order = $value['model'];
        $note = (string) $order->getData('magenx_order_note');
        $date = DateFormatter::toIso($order->getData('magenx_delivery_date'));
        if ($note === '' && $date === '') {
            return null;
        }

        return [
            'order_note' => $note !== '' ? $note : null,
            'delivery_date' => $date !== '' ? $date : null,
            'delivery_slot' => $date !== ''
                ? $this->config->describeSlot((string) $order->getData('magenx_delivery_slot'), (int) $order->getStoreId())
                : null,
        ];
    }
}

<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\CheckoutFieldsGraphQl\Model\Resolver;

use Magenx\CheckoutFieldsGraphQl\Model\Config;
use Magenx\CheckoutFieldsGraphQl\Model\DateFormatter;
use Magenx\CheckoutFieldsGraphQl\Model\DeliveryApplicability;
use Magenx\CheckoutFieldsGraphQl\Model\DeliveryCalendar;
use Magenx\CheckoutFieldsGraphQl\Model\NoteSanitizer;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\GraphQl\Config\Element\Field;
use Magento\Framework\GraphQl\Exception\GraphQlInputException;
use Magento\Framework\GraphQl\Query\ResolverInterface;
use Magento\Framework\GraphQl\Schema\Type\ResolveInfo;
use Magento\Quote\Api\CartRepositoryInterface;
use Magento\Quote\Model\Quote;
use Magento\QuoteGraphQl\Model\Cart\GetCartForUser;

/**
 * Resolves the `setCheckoutFieldsOnCart` mutation.
 *
 * Cart ownership is enforced by GetCartForUser (a guest cart by its masked id,
 * a customer cart only for its owner). Each field is optional: a key absent
 * from the input leaves the stored value alone, null/'' clears it. The date
 * and slot are validated together against the store's delivery calendar.
 */
class SetCheckoutFieldsOnCart implements ResolverInterface
{
    /**
     * @param GetCartForUser $getCartForUser
     * @param CartRepositoryInterface $cartRepository
     * @param Config $config
     * @param DeliveryApplicability $applicability
     * @param DeliveryCalendar $calendar
     * @param NoteSanitizer $noteSanitizer
     * @param DateFormatter $dateFormatter
     */
    public function __construct(
        private readonly GetCartForUser $getCartForUser,
        private readonly CartRepositoryInterface $cartRepository,
        private readonly Config $config,
        private readonly DeliveryApplicability $applicability,
        private readonly DeliveryCalendar $calendar,
        private readonly NoteSanitizer $noteSanitizer,
        private readonly DateFormatter $dateFormatter
    ) {
    }

    /**
     * @inheritDoc
     */
    public function resolve(Field $field, $context, ResolveInfo $info, ?array $value = null, ?array $args = null)
    {
        $input = $args['input'] ?? [];
        if (empty($input['cart_id'])) {
            throw new GraphQlInputException(__('Required parameter "cart_id" is missing.'));
        }

        $storeId = (int) $context->getExtensionAttributes()->getStore()->getId();
        $cart = $this->getCartForUser->execute((string) $input['cart_id'], $context->getUserId(), $storeId);

        if (array_key_exists('order_note', $input)) {
            $this->applyNote($cart, $input['order_note'], $storeId);
        }

        if (array_key_exists('delivery_date', $input) || array_key_exists('delivery_slot', $input)) {
            $this->applyDelivery($cart, $input, $storeId);
        }

        try {
            $this->cartRepository->save($cart);
        } catch (NoSuchEntityException $e) {
            throw new GraphQlInputException(__('Could not save the checkout fields on the cart.'), $e);
        }

        return ['cart' => ['model' => $cart]];
    }

    /**
     * @param Quote $cart
     * @param mixed $raw
     * @param int $storeId
     * @return void
     * @throws GraphQlInputException
     */
    private function applyNote(Quote $cart, mixed $raw, int $storeId): void
    {
        $note = $this->noteSanitizer->sanitize($raw);
        if ($note === '') {
            $cart->setData('magenx_order_note', null);
            return;
        }

        if (!$this->config->isOrderNoteEnabled($storeId)) {
            throw new GraphQlInputException(__('Order comments are not enabled.'));
        }

        $max = $this->config->getOrderNoteMaxLength($storeId);
        if (mb_strlen($note) > $max) {
            throw new GraphQlInputException(__('The order comment cannot be longer than %1 characters.', $max));
        }

        $cart->setData('magenx_order_note', $note);
    }

    /**
     * @param Quote $cart
     * @param array<string, mixed> $input
     * @param int $storeId
     * @return void
     * @throws GraphQlInputException
     */
    private function applyDelivery(Quote $cart, array $input, int $storeId): void
    {
        $date = array_key_exists('delivery_date', $input)
            ? trim((string) $input['delivery_date'])
            : $this->dateFormatter->toIso($cart->getData('magenx_delivery_date'));
        $slot = array_key_exists('delivery_slot', $input)
            ? trim((string) $input['delivery_slot'])
            : trim((string) $cart->getData('magenx_delivery_slot'));

        if ($date === '') {
            $cart->setData('magenx_delivery_date', null);
            $cart->setData('magenx_delivery_slot', null);
            return;
        }

        if (!$this->applicability->isOffered($cart)) {
            throw new GraphQlInputException(__('A delivery date cannot be chosen for this order.'));
        }

        if (!$this->calendar->isAvailable($date, $storeId)) {
            throw new GraphQlInputException(__('The delivery date "%1" is not available.', $date));
        }

        $slots = $this->config->getSlots($storeId);
        if ($slots === []) {
            $slot = '';
        } elseif (!in_array($slot, array_column($slots, 'code'), true)) {
            throw new GraphQlInputException(__('Please choose a delivery time slot.'));
        }

        $cart->setData('magenx_delivery_date', $date);
        $cart->setData('magenx_delivery_slot', $slot !== '' ? $slot : null);
    }
}

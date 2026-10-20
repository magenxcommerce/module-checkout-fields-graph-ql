<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\CheckoutFieldsGraphQl\Model;

use Magento\Quote\Model\Quote;

/**
 * Decides whether a delivery date applies to a quote at all.
 */
class DeliveryApplicability
{
    /**
     * Carrier code of Magenx_StorePickupGraphQl's In-Store Pickup. Matched as a
     * string so this module does not depend on that one: the order is collected,
     * so there is nothing to deliver.
     */
    private const PICKUP_CARRIER_CODE = 'magenx_storepickup';

    /**
     * @param Config $config
     */
    public function __construct(
        private readonly Config $config
    ) {
    }

    /**
     * Whether a delivery date may be chosen for this quote: the feature is on,
     * the quote ships something, and In-Store Pickup is not the selected
     * method. A quote with no shipping method yet still qualifies, so the
     * storefront can show the calendar as soon as a carrier is picked.
     *
     * @param Quote $quote
     * @return bool
     */
    public function isOffered(Quote $quote): bool
    {
        if (!$this->config->isDeliveryDateEnabled((int) $quote->getStoreId()) || $quote->isVirtual()) {
            return false;
        }

        $method = (string) $quote->getShippingAddress()?->getShippingMethod();

        return !str_starts_with($method, self::PICKUP_CARRIER_CODE . '_');
    }
}

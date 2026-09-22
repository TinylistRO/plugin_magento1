<?php
/**
 * "Tinylist Dropshipping" zero-cost shipping carrier.
 *
 * The shipping-side counterpart to the Tinylist_Dropshipping payment method,
 * and it exists for the same reason: order placement needs a method that is
 * guaranteed to resolve, and none of the store's real carriers is. A table
 * rate declines whenever the destination has no matching row or the subtotal
 * sits below its lowest threshold, and the Magento SOAP API reports that as a
 * bare "Shipping method is not available" (fault 1062) with nothing to say
 * which of the two it was.
 *
 * Disabled by default (default/carriers/tinylist/active = 0).
 *
 * **Never visible on the storefront.** Magento has no carrier equivalent of
 * the payment method's $_canUseCheckout flag, so the visibility rule lives in
 * collectRates(): a rate is returned only for SOAP/XML-RPC API calls and for
 * the admin order-create screen. A storefront quote gets nothing, which is
 * what keeps every buyer from selecting free delivery at checkout.
 *
 * The price is a hard-coded 0.00 rather than a config field, deliberately.
 * Tinylist charges the buyer no shipping, and Magento reprices every placed
 * order from its own catalogue — so a non-zero placement rate would have the
 * courier collect items + shipping as cash on delivery from a buyer who
 * agreed to neither. A configurable price is exactly how that reappears.
 *
 * The resulting Magento rate code is "tinylist_freeshipping" (carrier
 * "tinylist" + method "freeshipping"). Both halves are single alphanumeric
 * tokens on purpose: Tinylist validates a merchant's configured shipping code
 * against /^[a-z0-9]+_[a-z0-9]+$/, so a third segment would be rejected.
 */
class Tinylist_Dropshipping_Model_Carrier_Freeshipping
    extends Mage_Shipping_Model_Carrier_Abstract
    implements Mage_Shipping_Model_Carrier_Interface
{
    protected $_code = 'tinylist';

    /** The rate never varies, so Magento may cache/treat it as fixed. */
    protected $_isFixed = true;

    /** Method code; the full rate code is "tinylist_freeshipping". */
    const METHOD_CODE = 'freeshipping';

    /**
     * Magento front name of the SOAP/XML-RPC API. A store that serves the API
     * under a rewritten front name has to adjust this, or the carrier will go
     * silent for placement.
     */
    const API_MODULE_NAME = 'api';

    /**
     * @return array
     */
    public function getAllowedMethods()
    {
        return array(self::METHOD_CODE => $this->getConfigData('name'));
    }

    /**
     * @param Mage_Shipping_Model_Rate_Request $request
     * @return Mage_Shipping_Model_Rate_Result|false
     */
    public function collectRates(Mage_Shipping_Model_Rate_Request $request)
    {
        if (!$this->getConfigFlag('active') || !$this->_isPlacementContext()) {
            return false;
        }

        /** @var Mage_Shipping_Model_Rate_Result $result */
        $result = Mage::getModel('shipping/rate_result');

        /** @var Mage_Shipping_Model_Rate_Result_Method $method */
        $method = Mage::getModel('shipping/rate_result_method');
        $method->setCarrier($this->_code);
        $method->setCarrierTitle($this->getConfigData('title'));
        $method->setMethod(self::METHOD_CODE);
        $method->setMethodTitle($this->getConfigData('name'));
        $method->setPrice(0.00);
        $method->setCost(0.00);

        $result->append($method);

        return $result;
    }

    /**
     * Whether the current request may see this carrier.
     *
     * True for the SOAP/XML-RPC API (how Tinylist places orders) and for the
     * admin panel (so an admin reconciling a failed placement by hand has the
     * same method available, matching the payment method's posture). Anything
     * else — storefront checkout above all — is false.
     *
     * The standard router sets the module name to the route's front name, so a
     * request to /api/v2_soap/ reports "api" while a storefront checkout
     * reports "checkout".
     *
     * @return bool
     */
    protected function _isPlacementContext()
    {
        if (Mage::app()->getStore()->isAdmin()) {
            return true;
        }

        $request = Mage::app()->getRequest();

        return $request && $request->getModuleName() === self::API_MODULE_NAME;
    }
}

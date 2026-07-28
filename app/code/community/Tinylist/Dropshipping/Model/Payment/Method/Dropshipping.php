<?php
/**
 * "Tinylist Dropshipping" offline payment method.
 *
 * Disabled by default (default/payment/tinylist_dropshipping/active = 0).
 * When enabled it is available to admins creating orders but never rendered
 * on the storefront, and it places the order directly into the configured
 * processing status.
 */
class Tinylist_Dropshipping_Model_Payment_Method_Dropshipping extends Mage_Payment_Model_Method_Abstract
{
    protected $_code = 'tinylist_dropshipping';

    /** Let initialize() drive the resulting order state/status. */
    protected $_isInitializeNeeded = true;

    /** Hidden on the storefront, offered in the admin order create screen. */
    protected $_canUseCheckout         = false;
    protected $_canUseInternal         = true;
    protected $_canUseForMultishipping = false;

    protected $_formBlockType = 'payment/form';
    protected $_infoBlockType = 'payment/info';

    /**
     * Returning a non-empty action makes Mage_Sales_Model_Order_Payment::place()
     * route through initialize() (because $_isInitializeNeeded is true) instead
     * of leaving the order in the default "new" state.
     *
     * @return string
     */
    public function getConfigPaymentAction()
    {
        return self::ACTION_ORDER;
    }

    /**
     * Place the order into the configured processing status.
     *
     * @param string $paymentAction
     * @param Varien_Object $stateObject
     * @return $this
     */
    public function initialize($paymentAction, $stateObject)
    {
        $state  = Mage_Sales_Model_Order::STATE_PROCESSING;
        $status = $this->getConfigData('order_status');
        if (!$status) {
            $status = Mage::getSingleton('sales/order_config')->getStateDefaultStatus($state);
        }

        $stateObject->setState($state);
        $stateObject->setStatus($status);
        $stateObject->setIsNotified(false);

        return $this;
    }
}

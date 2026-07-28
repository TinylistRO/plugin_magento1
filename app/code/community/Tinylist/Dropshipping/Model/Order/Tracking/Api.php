<?php
/**
 * SOAP API resource: return the live carrier status for an (order number,
 * tracking number) pair.
 *
 * The result is a JSON string so the contract stays stable as more carriers
 * (with different native payloads) are added. Expected outcomes — unknown
 * order/tracking, a carrier that is disabled or whose module is missing/
 * unconfigured — come back as { "success": false, "error": "...", ... } rather
 * than a SOAP fault.
 */
class Tinylist_Dropshipping_Model_Order_Tracking_Api extends Mage_Api_Model_Resource_Abstract
{
    /**
     * @param string $orderIncrementId
     * @param string $trackNumber
     * @return string JSON envelope
     */
    public function status($orderIncrementId, $trackNumber)
    {
        $result = Mage::getModel('tinylist_dropshipping/carrier_status')
            ->getStatus($orderIncrementId, $trackNumber);

        return json_encode($result);
    }
}

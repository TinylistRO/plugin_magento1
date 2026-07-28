<?php
/**
 * Carrier tracking-status dispatcher.
 *
 * Given an (order number, tracking number) pair it:
 *   1. loads the order,
 *   2. asks each *enabled* provider whether the pair belongs to it
 *      (standard Magento shipment tracks, or carrier-specific storage such as
 *      DPD's own table),
 *   3. asks the matching provider for the live status,
 *   4. returns a normalized envelope (never throws for expected outcomes —
 *      unknown order/tracking, disabled carrier, unconfigured module, etc. all
 *      come back as { success:false, error, error_code }).
 *
 * Which carriers are queried is controlled by the
 * tinylist_dropshipping/carrier_status/enabled_carriers switch, over the fixed
 * provider set declared in global/tinylist_dropshipping/carrier_status_providers.
 */
class Tinylist_Dropshipping_Model_Carrier_Status extends Mage_Core_Model_Abstract
{
    const ENABLED_CARRIERS_PATH = 'tinylist_dropshipping/carrier_status/enabled_carriers';
    const PROVIDERS_NODE        = 'global/tinylist_dropshipping/carrier_status_providers';

    /**
     * @param string $orderIncrementId
     * @param string $trackNumber
     * @return array envelope (see class doc)
     */
    public function getStatus($orderIncrementId, $trackNumber)
    {
        $orderIncrementId = trim((string) $orderIncrementId);
        $trackNumber      = trim((string) $trackNumber);

        try {
            if ($orderIncrementId === '' || $trackNumber === '') {
                $this->_throw('Both an order number and a tracking number are required.', 'missing_parameters');
            }

            /** @var Mage_Sales_Model_Order $order */
            $order = Mage::getModel('sales/order')->loadByIncrementId($orderIncrementId);
            if (!$order->getId()) {
                $this->_throw(sprintf("Order '%s' was not found.", $orderIncrementId), 'order_not_found');
            }

            $provider = $this->_resolveProvider($order, $trackNumber);
            if (!$provider) {
                $this->_throw(
                    sprintf("Tracking number '%s' was not found on order '%s'.", $trackNumber, $orderIncrementId),
                    'tracking_not_found'
                );
            }

            $status = $provider->fetch($trackNumber);

            return $this->_successEnvelope(
                $orderIncrementId,
                $trackNumber,
                $provider->getResolvedCarrierCode(),
                $provider->getCarrierKey(),
                $status
            );
        } catch (Tinylist_Dropshipping_Model_Carrier_Status_Exception $e) {
            return $this->_errorEnvelope($orderIncrementId, $trackNumber, $e->getMessage(), $e->getFriendlyCode());
        } catch (Exception $e) {
            Mage::logException($e);
            return $this->_errorEnvelope(
                $orderIncrementId,
                $trackNumber,
                'An unexpected error occurred while fetching the tracking status.',
                'unexpected_error'
            );
        }
    }

    /**
     * First enabled provider that claims the (order, trackNumber) pair.
     *
     * @param Mage_Sales_Model_Order $order
     * @param string $trackNumber
     * @return Tinylist_Dropshipping_Model_Carrier_Status_Abstract|null
     */
    protected function _resolveProvider(Mage_Sales_Model_Order $order, $trackNumber)
    {
        foreach ($this->_getEnabledProviders() as $provider) {
            if ($provider->matches($order, $trackNumber)) {
                return $provider;
            }
        }

        return null;
    }

    /**
     * Instantiate the enabled providers, injecting their registry metadata.
     *
     * @return Tinylist_Dropshipping_Model_Carrier_Status_Abstract[]
     */
    protected function _getEnabledProviders()
    {
        $enabled   = $this->_getEnabledCarrierKeys();
        $node      = Mage::getConfig()->getNode(self::PROVIDERS_NODE);
        $providers = array();

        if ($node) {
            foreach ($node->children() as $key => $child) {
                if (!in_array((string) $key, $enabled, true)) {
                    continue;
                }
                $model = trim((string) $child->model);
                if ($model === '') {
                    continue;
                }
                $provider = Mage::getModel($model);
                if (!$provider instanceof Tinylist_Dropshipping_Model_Carrier_Status_Abstract) {
                    continue;
                }
                $codes = preg_split('/\s*,\s*/', (string) $child->carrier_codes, -1, PREG_SPLIT_NO_EMPTY);
                $provider->setCarrierKey((string) $key)->setCarrierCodes($codes);
                $providers[] = $provider;
            }
        }

        return $providers;
    }

    /**
     * @return array enabled provider keys
     */
    protected function _getEnabledCarrierKeys()
    {
        $raw = (string) Mage::getStoreConfig(self::ENABLED_CARRIERS_PATH);

        return array_values(array_filter(array_map('trim', explode(',', $raw)), 'strlen'));
    }

    /**
     * @throws Tinylist_Dropshipping_Model_Carrier_Status_Exception
     */
    protected function _throw($message, $code = 'error')
    {
        throw new Tinylist_Dropshipping_Model_Carrier_Status_Exception($message, $code);
    }

    /**
     * @return array
     */
    protected function _successEnvelope($orderIncrementId, $trackNumber, $carrierCode, $carrierKey, array $status)
    {
        return array(
            'success'            => true,
            'order_increment_id' => $orderIncrementId,
            'track_number'       => $trackNumber,
            'carrier_code'       => $carrierCode,
            'carrier'            => $carrierKey,
            'status'             => isset($status['status']) ? $status['status'] : null,
            'delivered'          => !empty($status['delivered']),
            'canceled'           => !empty($status['canceled']),
            'delivery_attempts'  => isset($status['delivery_attempts']) ? $status['delivery_attempts'] : null,
            'delivered_at'       => isset($status['delivered_at']) ? $status['delivered_at'] : null,
            'history'            => isset($status['history']) ? $status['history'] : array(),
        );
    }

    /**
     * @return array
     */
    protected function _errorEnvelope($orderIncrementId, $trackNumber, $message, $code)
    {
        return array(
            'success'            => false,
            'order_increment_id' => $orderIncrementId,
            'track_number'       => $trackNumber,
            'error'              => $message,
            'error_code'         => $code,
        );
    }
}

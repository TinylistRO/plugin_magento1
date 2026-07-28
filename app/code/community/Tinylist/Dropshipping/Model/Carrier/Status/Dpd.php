<?php
/**
 * DPD Romania tracking-status provider.
 *
 * DPD stores its shipment id in its own `dpd_order_shipment` table (keyed by
 * Magento order entity id), NOT in sales_flat_shipment_track — so matches() is
 * overridden to look there. Credentials live in the DPD module's `dpd_settings`
 * table and are read through the module's own Ajax model. The DPD API takes the
 * username/password in the request body (no token):
 *   POST https://api.dpd.ro/v1/track/   { userName, password, parcels:[{id}], ... }
 *
 * The DPD module itself is left untouched.
 */
class Tinylist_Dropshipping_Model_Carrier_Status_Dpd extends Tinylist_Dropshipping_Model_Carrier_Status_Abstract
{
    const CARRIER_KEY     = 'dpd';
    const CARRIER_CODE    = 'dpdro_shipping';
    const MODULE_NAME     = 'DpdRo_Settings';
    const SHIPMENT_TABLE  = 'dpd_order_shipment';

    protected $_apiUrl = 'https://api.dpd.ro/v1/';

    /** @var array|null cached {username,password} */
    protected $_credentials;

    /**
     * DPD keeps its shipment id in dpd_order_shipment, not Magento tracks.
     *
     * @param Mage_Sales_Model_Order $order
     * @param string $trackNumber
     * @return bool
     */
    public function matches(Mage_Sales_Model_Order $order, $trackNumber)
    {
        $shipmentId = $this->_getStoredShipmentId($order->getId());

        if ($shipmentId !== null && trim((string) $shipmentId) === trim((string) $trackNumber)) {
            $this->_resolvedCarrierCode = self::CARRIER_CODE;
            return true;
        }

        return false;
    }

    /**
     * @param int $orderEntityId
     * @return string|null
     */
    protected function _getStoredShipmentId($orderEntityId)
    {
        $orderEntityId = (int) $orderEntityId;
        if (!$orderEntityId) {
            return null;
        }

        /** @var Mage_Core_Model_Resource $resource */
        $resource = Mage::getSingleton('core/resource');
        $read     = $resource->getConnection('core_read');
        $table    = $resource->getTableName(self::SHIPMENT_TABLE);

        $select = $read->select()
            ->from($table, array('shipment_id'))
            ->where('order_id = ?', $orderEntityId)
            ->order('id DESC')
            ->limit(1);

        $value = $read->fetchOne($select);

        return $value === false ? null : $value;
    }

    /**
     * @param string $trackNumber
     * @return array
     */
    public function fetch($trackNumber)
    {
        $credentials = $this->_getCredentials();

        $payload = json_encode(array(
            'userName'          => $credentials['username'],
            'password'          => $credentials['password'],
            'language'          => 'EN',
            'parcels'           => array(array('id' => (string) $trackNumber)),
            'lastOperationOnly' => false,
        ));

        $response = json_decode($this->_httpRequest(
            'POST',
            $this->_apiUrl . 'track/',
            array('content-type: application/json', 'cache-control: no-cache'),
            $payload,
            array(CURLOPT_SSL_VERIFYHOST => false, CURLOPT_SSL_VERIFYPEER => false)
        ), true);

        if (!is_array($response)) {
            $this->_throw('DPD returned an unexpected response.', 'carrier_bad_response');
        }

        if (isset($response['error'])) {
            $message = is_array($response['error'])
                ? (isset($response['error']['message']) ? $response['error']['message'] : json_encode($response['error']))
                : $response['error'];
            $this->_throw('DPD could not return a status: ' . $message, 'carrier_status_unavailable');
        }

        return $this->_normalize($trackNumber, $response);
    }

    /**
     * @param string $trackNumber
     * @param array $response
     * @return array
     */
    protected function _normalize($trackNumber, array $response)
    {
        $parcels = isset($response['parcels']) && is_array($response['parcels']) ? $response['parcels'] : array();

        $parcel = null;
        foreach ($parcels as $candidate) {
            if (isset($candidate['id']) && (string) $candidate['id'] === (string) $trackNumber) {
                $parcel = $candidate;
                break;
            }
        }
        if ($parcel === null && $parcels) {
            $parcel = reset($parcels);
        }
        if ($parcel === null) {
            $this->_throw(sprintf("DPD has no tracking data for '%s' yet.", $trackNumber), 'carrier_status_unavailable');
        }

        $operations = isset($parcel['operations']) && is_array($parcel['operations']) ? $parcel['operations'] : array();
        $history    = array();
        foreach ($operations as $operation) {
            if (is_array($operation)) {
                $history[] = $this->_mapOperation($operation);
            }
        }

        $current  = $history ? $history[count($history) - 1] : $this->_mapOperation(array());
        $lastCode = (string) $current['code'];

        return array(
            'carrier_key'       => self::CARRIER_KEY,
            'status'            => $current,
            // -14 = Delivered per the DPD/Speedy operation codes.
            'delivered'         => ($lastCode === '-14'),
            // 124 back to sender, 125 destroyed, 127 theft, 128 canceled, 129 admin closure.
            'canceled'          => in_array($lastCode, array('124', '125', '127', '128', '129'), true),
            'delivery_attempts' => null,
            'delivered_at'      => ($lastCode === '-14') ? $current['date'] : null,
            'history'           => $history,
        );
    }

    /**
     * @param array $operation
     * @return array
     */
    protected function _mapOperation(array $operation)
    {
        $code = isset($operation['operationCode']) ? $operation['operationCode']
            : (isset($operation['code']) ? $operation['code'] : null);
        $description = isset($operation['description']) ? $operation['description']
            : (isset($operation['name']) ? $operation['name'] : null);

        return array(
            'code'             => $code,
            'status'           => $description,
            'label'            => $description,
            'state'            => null,
            'date'             => isset($operation['date']) ? $operation['date'] : null,
            'county'           => null,
            'reason'           => null,
            'transit_location' => null,
        );
    }

    /**
     * Read the DPD credentials via the module's own settings model.
     *
     * @return array {username, password}
     * @throws Tinylist_Dropshipping_Model_Carrier_Status_Exception
     */
    protected function _getCredentials()
    {
        if ($this->_credentials !== null) {
            return $this->_credentials;
        }

        if (!Mage::helper('core')->isModuleEnabled(self::MODULE_NAME)) {
            $this->_throw(
                'The DPD Romania module is not installed or is disabled on this store.',
                'carrier_module_unavailable'
            );
        }

        $settings = Mage::getModel('dpdro_settings/ajax')->Settings();
        $username = is_array($settings) && !empty($settings['username']) ? $settings['username'] : '';
        $password = is_array($settings) && !empty($settings['password']) ? $settings['password'] : '';

        if (!$username || !$password) {
            $this->_throw(
                'DPD Romania is not configured yet — the API username or password is missing.',
                'carrier_not_configured'
            );
        }

        return $this->_credentials = array('username' => $username, 'password' => $password);
    }
}

<?php
/**
 * Base class for a carrier tracking-status provider.
 *
 * A provider knows how to (a) decide whether a given (order, trackNumber) pair
 * belongs to it and (b) fetch + normalize that shipment's status from the
 * carrier API. Add a new carrier by extending this class and registering it
 * under global/tinylist_dropshipping/carrier_status_providers in config.xml.
 *
 * The dispatcher injects the provider's registry metadata (key + the Magento
 * carrier codes it owns) before use.
 */
abstract class Tinylist_Dropshipping_Model_Carrier_Status_Abstract
{
    /** @var string registry key, e.g. "sameday" */
    protected $_carrierKey;

    /** @var string[] Magento carrier codes this provider owns */
    protected $_carrierCodes = array();

    /** @var string|null carrier code resolved during matches(), for the envelope */
    protected $_resolvedCarrierCode;

    /**
     * Fetch and normalize the status for a tracking number.
     *
     * Must throw Tinylist_Dropshipping_Model_Carrier_Status_Exception with a
     * friendly message when the carrier module is missing/disabled/unconfigured
     * or the API cannot answer.
     *
     * Expected normalized shape:
     *   array(
     *     'carrier_key'       => 'sameday',
     *     'status'            => array('code','status','label','state','date','county','reason','transit_location'),
     *     'delivered'         => bool,
     *     'canceled'          => bool,
     *     'delivery_attempts' => int|null,
     *     'delivered_at'      => string|null,
     *     'history'           => array(<status>, ...),
     *   )
     *
     * @param string $trackNumber
     * @return array
     */
    abstract public function fetch($trackNumber);

    /**
     * Does this (order, trackNumber) pair belong to this carrier?
     *
     * Default implementation matches a Magento shipment track whose carrier_code
     * is one this provider owns. Carriers that store their AWB elsewhere (e.g.
     * DPD) override this.
     *
     * @param Mage_Sales_Model_Order $order
     * @param string $trackNumber
     * @return bool
     */
    public function matches(Mage_Sales_Model_Order $order, $trackNumber)
    {
        return (bool) $this->_findTrack($order, $trackNumber);
    }

    /**
     * @param Mage_Sales_Model_Order $order
     * @param string $trackNumber
     * @return Mage_Sales_Model_Order_Shipment_Track|null
     */
    protected function _findTrack(Mage_Sales_Model_Order $order, $trackNumber)
    {
        $trackNumber = trim((string) $trackNumber);
        $codes = array_map('strtolower', $this->_carrierCodes);

        foreach ($order->getTracksCollection() as $track) {
            if (in_array(strtolower((string) $track->getCarrierCode()), $codes, true)
                && trim((string) $track->getTrackNumber()) === $trackNumber
            ) {
                $this->_resolvedCarrierCode = $track->getCarrierCode();
                return $track;
            }
        }

        return null;
    }

    /**
     * Shared cURL helper. Returns the raw response body.
     *
     * @param string $method  GET|POST
     * @param string $url
     * @param array  $headers raw header lines
     * @param string|null $body POST body
     * @param array  $extraOpts additional curl_setopt options (e.g. SSL flags)
     * @return string
     * @throws Tinylist_Dropshipping_Model_Carrier_Status_Exception
     */
    protected function _httpRequest($method, $url, array $headers = array(), $body = null, array $extraOpts = array())
    {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 15);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        if ($headers) {
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        }
        if (strtoupper($method) === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            if ($body !== null) {
                curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
            }
        } else {
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'GET');
        }
        foreach ($extraOpts as $opt => $value) {
            curl_setopt($ch, $opt, $value);
        }

        $response = curl_exec($ch);
        if ($response === false) {
            $error = curl_error($ch);
            curl_close($ch);
            $this->_throw('Could not reach the carrier API: ' . $error, 'carrier_unreachable');
        }
        curl_close($ch);

        return $response;
    }

    /**
     * Raise a friendly, caller-facing error.
     *
     * @param string $message
     * @param string $code
     * @throws Tinylist_Dropshipping_Model_Carrier_Status_Exception
     */
    protected function _throw($message, $code = 'error')
    {
        throw new Tinylist_Dropshipping_Model_Carrier_Status_Exception($message, $code);
    }

    // -- registry metadata (set by the dispatcher) --------------------------

    public function setCarrierKey($key)
    {
        $this->_carrierKey = $key;
        return $this;
    }

    public function getCarrierKey()
    {
        return $this->_carrierKey;
    }

    public function setCarrierCodes(array $codes)
    {
        $this->_carrierCodes = $codes;
        return $this;
    }

    public function getCarrierCodes()
    {
        return $this->_carrierCodes;
    }

    /**
     * @return string|null the carrier code resolved during matches()
     */
    public function getResolvedCarrierCode()
    {
        if ($this->_resolvedCarrierCode) {
            return $this->_resolvedCarrierCode;
        }
        return isset($this->_carrierCodes[0]) ? $this->_carrierCodes[0] : null;
    }
}

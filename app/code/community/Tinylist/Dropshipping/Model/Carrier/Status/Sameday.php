<?php
/**
 * Sameday Courier tracking-status provider.
 *
 * The Blugento_SamedayCourier module does not expose a status method, so this
 * provider reuses that module's stored credentials (carriers/bgsamedaycourier/*)
 * and calls the Sameday eAWB status endpoint directly, per the Sameday API:
 *   POST /api/authenticate                  (X-AUTH-USERNAME / X-AUTH-PASSWORD)
 *   GET  /api/client/awb/{awb}/status        (X-AUTH-TOKEN)
 *
 * The Sameday module itself is left untouched.
 */
class Tinylist_Dropshipping_Model_Carrier_Status_Sameday extends Tinylist_Dropshipping_Model_Carrier_Status_Abstract
{
    const CARRIER_KEY  = 'sameday';
    const MODULE_NAME  = 'Blugento_SamedayCourier';
    const CONFIG_GROUP = 'carriers/bgsamedaycourier/';

    protected $_prodUrl = 'https://api.sameday.ro/api';
    protected $_testUrl = 'https://sameday-api.demo.zitec.com/api';

    /**
     * @param string $trackNumber
     * @return array
     */
    public function fetch($trackNumber)
    {
        $this->_assertAvailable();

        $token    = $this->_authenticate();
        $response = $this->_httpCall(
            'GET',
            'client/awb/' . rawurlencode($trackNumber) . '/status',
            array(),
            array('X-AUTH-TOKEN: ' . $token)
        );

        if (!is_array($response)) {
            $this->_throw('Sameday Courier returned an unexpected response.', 'carrier_bad_response');
        }

        if (isset($response['error']) || (isset($response['status']) && (int) $response['status'] >= 400)) {
            $this->_throw(
                sprintf("Sameday Courier has no status for AWB '%s' yet.", $trackNumber),
                'carrier_status_unavailable'
            );
        }

        return $this->_normalize($response);
    }

    /**
     * Guard: module present + enabled + credentials configured.
     *
     * @throws Tinylist_Dropshipping_Model_Carrier_Status_Exception
     */
    protected function _assertAvailable()
    {
        if (!Mage::helper('core')->isModuleEnabled(self::MODULE_NAME)) {
            $this->_throw(
                'The Sameday Courier shipping module is not installed or is disabled on this store.',
                'carrier_module_unavailable'
            );
        }

        if (!$this->_getConfig('username') || !$this->_getConfig('password')) {
            $this->_throw(
                'Sameday Courier is not configured yet — the API username or password is missing.',
                'carrier_not_configured'
            );
        }
    }

    /**
     * @return string auth token
     * @throws Tinylist_Dropshipping_Model_Carrier_Status_Exception
     */
    protected function _authenticate()
    {
        $response = $this->_httpCall('POST', 'authenticate', array(), array(
            'X-AUTH-USERNAME: ' . $this->_getConfig('username'),
            'X-AUTH-PASSWORD: ' . $this->_getConfig('password'),
        ));

        if (!is_array($response) || empty($response['token'])) {
            $detail = '';
            if (is_array($response) && isset($response['error'])) {
                $detail = ' (' . (is_array($response['error']) ? implode('; ', $response['error']) : $response['error']) . ')';
            }
            $this->_throw('Could not authenticate with Sameday Courier.' . $detail, 'carrier_auth_failed');
        }

        return $response['token'];
    }

    /**
     * Map the Sameday status payload to the normalized shape.
     *
     * @param array $response
     * @return array
     */
    protected function _normalize(array $response)
    {
        $expedition = isset($response['expeditionStatus']) && is_array($response['expeditionStatus'])
            ? $response['expeditionStatus'] : array();
        $summary = isset($response['expeditionSummary']) && is_array($response['expeditionSummary'])
            ? $response['expeditionSummary'] : array();

        $history = array();
        if (!empty($response['expeditionHistory']) && is_array($response['expeditionHistory'])) {
            foreach ($response['expeditionHistory'] as $entry) {
                if (is_array($entry)) {
                    $history[] = $this->_mapStatus($entry);
                }
            }
        }

        return array(
            'carrier_key'       => self::CARRIER_KEY,
            'status'            => $this->_mapStatus($expedition),
            'delivered'         => !empty($summary['delivered']),
            'canceled'          => !empty($summary['canceled']),
            'delivery_attempts' => isset($summary['deliveryAttempts']) ? (int) $summary['deliveryAttempts'] : null,
            'delivered_at'      => isset($summary['deliveredAt']) ? $summary['deliveredAt'] : null,
            'history'           => $history,
        );
    }

    /**
     * @param array $status
     * @return array
     */
    protected function _mapStatus(array $status)
    {
        return array(
            'code'             => isset($status['statusId']) ? $status['statusId'] : null,
            'status'           => isset($status['status']) ? $status['status'] : null,
            'label'            => isset($status['statusLabel']) ? $status['statusLabel'] : null,
            'state'            => isset($status['statusState']) ? $status['statusState'] : null,
            'date'             => isset($status['statusDate']) ? $status['statusDate'] : null,
            'county'           => isset($status['county']) ? $status['county'] : null,
            'reason'           => isset($status['reason']) ? $status['reason'] : null,
            'transit_location' => isset($status['transitLocation']) ? $status['transitLocation'] : null,
        );
    }

    /**
     * @param string $method  POST|GET
     * @param string $path    relative to the API base (which already ends in /api)
     * @param array  $params  form body for POST
     * @param array  $headers raw header lines
     * @return mixed decoded JSON (array) or raw body string
     * @throws Tinylist_Dropshipping_Model_Carrier_Status_Exception
     */
    protected function _httpCall($method, $path, array $params = array(), array $headers = array())
    {
        $base = $this->_getConfig('test_mode') ? $this->_testUrl : $this->_prodUrl;
        $url  = $base . '/' . $path;

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
        curl_setopt($ch, CURLOPT_TIMEOUT, 15);

        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($params));
        } else {
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'GET');
        }

        $body = curl_exec($ch);
        if ($body === false) {
            $error = curl_error($ch);
            curl_close($ch);
            $this->_throw('Could not reach Sameday Courier: ' . $error, 'carrier_unreachable');
        }
        curl_close($ch);

        $decoded = json_decode($body, true);

        return $decoded === null ? $body : $decoded;
    }

    /**
     * @param string $field
     * @return mixed
     */
    protected function _getConfig($field)
    {
        return Mage::getStoreConfig(self::CONFIG_GROUP . $field);
    }
}

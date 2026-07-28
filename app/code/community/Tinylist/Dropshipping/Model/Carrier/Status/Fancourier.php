<?php
/**
 * FAN Courier tracking-status provider.
 *
 * The Blugento_FanCourier module exposes no status method, so this provider
 * reuses that module's credentials and cached bearer token
 * (carriers/bgfancourier/{client_id,username,password} + the module's 20h token
 * cache) and calls the FAN Courier v2 API directly:
 *   POST /login?username=..&password=..     -> data.token   (cached by the module)
 *   GET  /reports/awb/tracking?clientId=..&awb[]=..   Authorization: Bearer <token>
 *
 * The FAN Courier module itself is left untouched.
 */
class Tinylist_Dropshipping_Model_Carrier_Status_Fancourier extends Tinylist_Dropshipping_Model_Carrier_Status_Abstract
{
    const CARRIER_KEY  = 'fancourier';
    const MODULE_NAME  = 'Blugento_FanCourier';
    const HELPER_ALIAS = 'blugento_fancourier';
    const CONFIG_GROUP = 'carriers/bgfancourier/';

    protected $_apiUrl = 'https://api.fancourier.ro';

    /**
     * @param string $trackNumber
     * @return array
     */
    public function fetch($trackNumber)
    {
        $this->_assertAvailable();

        $token = $this->_getToken();
        $url   = $this->_apiUrl . '/reports/awb/tracking'
            . '?clientId=' . rawurlencode($this->_getConfig('client_id'))
            . '&awb[]=' . rawurlencode($trackNumber);

        $response = json_decode($this->_httpRequest('GET', $url, array(
            'Content-Type: application/json',
            'Authorization: Bearer ' . $token,
        )), true);

        if (!is_array($response)) {
            $this->_throw('FAN Courier returned an unexpected response.', 'carrier_bad_response');
        }

        if (!isset($response['status']) || $response['status'] !== 'success') {
            $message = isset($response['message']) ? $response['message'] : 'no status available';
            $this->_throw('FAN Courier could not return a status: ' . $message, 'carrier_status_unavailable');
        }

        $entry = $this->_pickAwb(isset($response['data']) ? $response['data'] : array(), $trackNumber);
        if ($entry === null) {
            $this->_throw(sprintf("FAN Courier has no status for AWB '%s' yet.", $trackNumber), 'carrier_status_unavailable');
        }

        return $this->_normalize($entry);
    }

    /**
     * @param array $data list of per-AWB tracking objects
     * @param string $trackNumber
     * @return array|null
     */
    protected function _pickAwb(array $data, $trackNumber)
    {
        foreach ($data as $entry) {
            if (isset($entry['awbNumber']) && (string) $entry['awbNumber'] === (string) $trackNumber) {
                return $entry;
            }
        }

        return count($data) ? reset($data) : null;
    }

    /**
     * @param array $entry
     * @return array
     */
    protected function _normalize(array $entry)
    {
        $events  = isset($entry['events']) && is_array($entry['events']) ? $entry['events'] : array();
        $history = array();
        foreach ($events as $event) {
            if (is_array($event)) {
                $history[] = $this->_mapEvent($event);
            }
        }

        // Current status = last event, or the top-level message when no scans yet.
        if ($history) {
            $current = $history[count($history) - 1];
        } else {
            $current = $this->_mapEvent(array('name' => isset($entry['message']) ? $entry['message'] : null));
        }

        $name = (string) $current['status'];

        return array(
            'carrier_key'       => self::CARRIER_KEY,
            'status'            => $current,
            'delivered'         => (bool) preg_match('/livrat/iu', $name),
            'canceled'          => (bool) preg_match('/retur|refuz|anulat/iu', $name),
            'delivery_attempts' => null,
            'delivered_at'      => preg_match('/livrat/iu', $name) ? $current['date'] : null,
            'history'           => $history,
        );
    }

    /**
     * @param array $event
     * @return array
     */
    protected function _mapEvent(array $event)
    {
        return array(
            'code'             => isset($event['id']) ? $event['id'] : null,
            'status'           => isset($event['name']) ? $event['name'] : null,
            'label'            => isset($event['name']) ? $event['name'] : null,
            'state'            => null,
            'date'             => isset($event['date']) ? $event['date'] : null,
            'county'           => null,
            'reason'           => null,
            'transit_location' => isset($event['location']) ? $event['location'] : null,
        );
    }

    /**
     * Reuse the FAN Courier module's cached bearer token; log in if absent.
     *
     * @return string
     * @throws Tinylist_Dropshipping_Model_Carrier_Status_Exception
     */
    protected function _getToken()
    {
        $helper = Mage::helper(self::HELPER_ALIAS);

        $token = $helper->getToken();
        if (!$token) {
            $url  = $this->_apiUrl . '/login?username=' . rawurlencode($this->_getConfig('username'))
                . '&password=' . rawurlencode($this->_getConfig('password'));
            $body = json_decode($this->_httpRequest('POST', $url, array('Content-Type: application/json')), true);

            if (is_array($body) && isset($body['data']['token']) && $body['data']['token']) {
                $token = $body['data']['token'];
                $helper->saveToken($token);
            }
        }

        if (!$token) {
            $this->_throw('Could not authenticate with FAN Courier.', 'carrier_auth_failed');
        }

        return $token;
    }

    /**
     * @throws Tinylist_Dropshipping_Model_Carrier_Status_Exception
     */
    protected function _assertAvailable()
    {
        if (!Mage::helper('core')->isModuleEnabled(self::MODULE_NAME)) {
            $this->_throw(
                'The FAN Courier shipping module is not installed or is disabled on this store.',
                'carrier_module_unavailable'
            );
        }

        if (!$this->_getConfig('client_id') || !$this->_getConfig('username') || !$this->_getConfig('password')) {
            $this->_throw(
                'FAN Courier is not configured yet — the client id, username or password is missing.',
                'carrier_not_configured'
            );
        }
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

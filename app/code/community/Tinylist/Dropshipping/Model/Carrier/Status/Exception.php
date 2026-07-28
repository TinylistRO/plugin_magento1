<?php
/**
 * Friendly, caller-facing error raised while resolving a carrier tracking
 * status. The dispatcher turns it into a { success:false, error, error_code }
 * envelope instead of a raw SOAP fault.
 */
class Tinylist_Dropshipping_Model_Carrier_Status_Exception extends Mage_Core_Exception
{
    /** @var string machine-readable code, e.g. carrier_not_configured */
    protected $_friendlyCode = 'error';

    public function __construct($message, $friendlyCode = 'error')
    {
        parent::__construct($message);
        $this->_friendlyCode = $friendlyCode;
    }

    /**
     * @return string
     */
    public function getFriendlyCode()
    {
        return $this->_friendlyCode;
    }
}

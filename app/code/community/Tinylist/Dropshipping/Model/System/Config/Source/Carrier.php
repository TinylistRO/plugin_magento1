<?php
/**
 * Options for the "Enabled Carriers" multiselect — the fixed set of tracking
 * providers declared in global/tinylist_dropshipping/carrier_status_providers.
 */
class Tinylist_Dropshipping_Model_System_Config_Source_Carrier
{
    /**
     * @return array
     */
    public function toOptionArray()
    {
        $options   = array();
        $providers = Mage::getConfig()->getNode('global/tinylist_dropshipping/carrier_status_providers');

        if ($providers) {
            $helper = Mage::helper('tinylist_dropshipping');
            foreach ($providers->children() as $key => $node) {
                $label = trim((string) $node->label) ?: (string) $key;
                $options[] = array(
                    'value' => (string) $key,
                    'label' => $helper->__($label),
                );
            }
        }

        return $options;
    }
}

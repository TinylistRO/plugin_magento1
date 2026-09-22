<?php
/**
 * SOAP API resource: a page of the catalogue with price and stock together.
 *
 * Exposed as the v2 method tinylistDropshippingCatalogDigest. Uses the existing
 * catalog/product/info ACL resource rather than introducing its own, so the
 * "Tinylist Connector" role created at install already grants it — including on
 * stores that installed an earlier version, where the setup script skips a role
 * that already exists and would never add a new resource.
 *
 * This class owns the transport concerns (argument validation, store-view
 * resolution, faults); the query itself lives in
 * {@see Tinylist_Dropshipping_Model_Catalog_Digest}.
 */
class Tinylist_Dropshipping_Model_Catalog_Digest_Api extends Mage_Api_Model_Resource_Abstract
{
    /**
     * @param int         $lastId       entity_id cursor, exclusive
     * @param int         $pageSize     clamped server-side to 1000
     * @param string|null $updatedSince 'Y-m-d H:i:s'
     * @param string|null $storeView    store code or id; defaults to admin scope
     * @return array list of rows; empty array when there are no more
     */
    public function digest($lastId = 0, $pageSize = null, $updatedSince = null, $storeView = null)
    {
        $updatedSince = $this->_normaliseUpdatedSince($updatedSince);
        $storeId      = $this->_resolveStoreId($storeView);

        return Mage::getModel('tinylist_dropshipping/catalog_digest')
            ->getRows($lastId, $pageSize, $updatedSince, $storeId);
    }

    /**
     * An unparseable timestamp faults rather than being dropped. Ignoring it
     * would silently return the whole catalogue as though it had all changed —
     * the caller would see plausible data and never learn its filter was
     * discarded.
     *
     * @param string|null $updatedSince
     * @return string|null
     */
    protected function _normaliseUpdatedSince($updatedSince)
    {
        if ($updatedSince === null || trim((string) $updatedSince) === '') {
            return null;
        }

        $updatedSince = trim((string) $updatedSince);
        $timestamp    = strtotime($updatedSince);

        if ($timestamp === false) {
            $this->_fault('invalid_updated_since', sprintf("'%s' is not a parseable date.", $updatedSince));
        }

        return date('Y-m-d H:i:s', $timestamp);
    }

    /**
     * @param string|null $storeView store code or numeric id
     * @return int
     */
    protected function _resolveStoreId($storeView)
    {
        if ($storeView === null || trim((string) $storeView) === '') {
            return Mage_Core_Model_App::ADMIN_STORE_ID;
        }

        try {
            return (int) Mage::app()->getStore(trim((string) $storeView))->getId();
        } catch (Mage_Core_Model_Store_Exception $e) {
            // Faulting beats falling back to the admin scope: a typo'd store
            // code would otherwise return default-scope prices that look
            // perfectly valid and are wrong for every store-scoped product.
            $this->_fault('store_view_not_found', sprintf("Store view '%s' was not found.", $storeView));
        }

        return Mage_Core_Model_App::ADMIN_STORE_ID;
    }
}

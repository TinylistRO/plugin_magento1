<?php
/**
 * Price + stock for a page of products, in one query.
 *
 * Magento 1's V2 `catalogProductList` returns a fixed shape (product_id, sku,
 * name, set, type, category_ids, website_ids) and has no attributes parameter,
 * so price cannot be requested in bulk natively. Stock can
 * (`catalogInventoryStockItemList` takes an array of ids); price cannot. That
 * one gap forces a `catalogProductInfo` per product, and at Tinylist's
 * self-imposed 1 req/s a 3,000-product catalogue becomes ~2.5 hours of wall
 * clock against a five-minute worker — it never finishes.
 *
 * This model answers the same question with one collection query per page.
 *
 * Deliberately NOT a loop of Mage::getModel('catalog/product')->load(): the
 * per-product model load is what makes the merchant's own Magento suffer, and
 * avoiding it is the entire point of the endpoint. Everything here comes from a
 * product collection with the needed attributes selected, a left join on
 * cataloginventory_stock_item, and at most one extra query per page to resolve
 * parent SKUs.
 *
 * Rows are sorted by entity_id ascending and paged by cursor: the caller passes
 * the last product_id it saw as $lastId and stops when a page comes back
 * shorter than it asked for. Same contract as catalogProductList, on purpose,
 * so the two page identically.
 *
 * Status and visibility are returned, never filtered on. The caller wants to
 * see a product that has just been disabled — that is how it learns to stop
 * selling it.
 */
class Tinylist_Dropshipping_Model_Catalog_Digest
{
    const DEFAULT_PAGE_SIZE = 500;
    const MAX_PAGE_SIZE     = 1000;

    /** Attributes the digest reports. Nothing else is selected — no
     *  description, no media, no options. */
    protected $_attributes = array(
        'price',
        'special_price',
        'special_from_date',
        'special_to_date',
        'status',
        'visibility',
        'updated_at',
    );

    /**
     * @param int         $lastId       entity_id cursor, exclusive
     * @param int|null    $pageSize     clamped to MAX_PAGE_SIZE
     * @param string|null $updatedSince 'Y-m-d H:i:s'; see README for what it does NOT catch
     * @param int         $storeId      resolved store id (0 = admin/default scope)
     * @return array list of flat rows, ascending by product_id; empty when exhausted
     */
    public function getRows($lastId = 0, $pageSize = null, $updatedSince = null, $storeId = 0)
    {
        $lastId   = max(0, (int) $lastId);
        $pageSize = $this->_clampPageSize($pageSize);

        $collection = Mage::getResourceModel('catalog/product_collection')
            ->setStoreId((int) $storeId)
            ->addAttributeToSelect($this->_attributes)
            ->addAttributeToFilter('entity_id', array('gt' => $lastId));

        if ($updatedSince !== null && $updatedSince !== '') {
            $collection->addAttributeToFilter('updated_at', array('gt' => $updatedSince));
        }

        $collection->joinTable(
            'cataloginventory/stock_item',
            'product_id=entity_id',
            array('qty' => 'qty', 'is_in_stock' => 'is_in_stock'),
            null,
            'left'
        );

        // Ordered on the select rather than through setOrder(): the cursor
        // contract depends on a strict entity_id ordering, and the product
        // collection's setOrder() special-cases several attributes on its way
        // to the same place. This leaves nothing to interpret.
        $collection->getSelect()->order('e.entity_id ASC');

        $collection->setPageSize($pageSize)->setCurPage(1);

        $rows = array();
        $ids  = array();

        foreach ($collection as $product) {
            $ids[] = (int) $product->getId();
            $rows[(int) $product->getId()] = array(
                'product_id'        => (string) $product->getId(),
                'sku'               => (string) $product->getSku(),
                'type'              => (string) $product->getTypeId(),
                'status'            => $this->_str($product->getStatus()),
                'visibility'        => $this->_str($product->getVisibility()),
                'parent_sku'        => null,
                'price'             => $this->_str($product->getPrice()),
                'special_price'     => $this->_str($product->getSpecialPrice()),
                'special_from_date' => $this->_str($product->getSpecialFromDate()),
                'special_to_date'   => $this->_str($product->getSpecialToDate()),
                'qty'               => $this->_str($product->getQty()),
                'is_in_stock'       => $this->_str($product->getIsInStock()),
                'updated_at'        => $this->_str($product->getUpdatedAt()),
            );
        }

        foreach ($this->_parentSkus($ids) as $childId => $parentSku) {
            if (isset($rows[$childId])) {
                $rows[$childId]['parent_sku'] = $parentSku;
            }
        }

        // Re-index: the SOAP layer must serialise a list, not a map keyed by id.
        return array_values($rows);
    }

    /**
     * Configurable parent SKU per child, in one query for the whole page.
     *
     * A child can technically belong to several configurables; Tinylist models
     * one parent per child, so the lowest parent entity_id wins and the rest
     * are ignored. Ordering by parent_id ascending and keeping the first hit
     * gives that without a GROUP BY.
     *
     * @param array $childIds
     * @return array childId => parent sku
     */
    protected function _parentSkus(array $childIds)
    {
        if (!$childIds) {
            return array();
        }

        /** @var Mage_Core_Model_Resource $resource */
        $resource = Mage::getSingleton('core/resource');
        $read     = $resource->getConnection('core_read');

        $select = $read->select()
            ->from(array('l' => $resource->getTableName('catalog/product_super_link')), array('product_id'))
            ->join(
                array('e' => $resource->getTableName('catalog/product')),
                'e.entity_id = l.parent_id',
                array('sku')
            )
            ->where('l.product_id IN (?)', $childIds)
            ->order('l.parent_id ASC');

        $map = array();
        foreach ($read->fetchAll($select) as $row) {
            $childId = (int) $row['product_id'];
            if (!isset($map[$childId])) {
                $map[$childId] = (string) $row['sku'];
            }
        }

        return $map;
    }

    /**
     * @param mixed $pageSize
     * @return int
     */
    protected function _clampPageSize($pageSize)
    {
        $pageSize = (int) $pageSize;
        if ($pageSize <= 0) {
            $pageSize = self::DEFAULT_PAGE_SIZE;
        }

        return min($pageSize, self::MAX_PAGE_SIZE);
    }

    /**
     * Null stays null so the SOAP layer omits the element and the caller's
     * "?? null" sees absence rather than the string "0" — which for
     * special_price is the difference between "no promotion" and "free".
     *
     * @param mixed $value
     * @return string|null
     */
    protected function _str($value)
    {
        if ($value === null || $value === '') {
            return null;
        }

        return (string) $value;
    }
}

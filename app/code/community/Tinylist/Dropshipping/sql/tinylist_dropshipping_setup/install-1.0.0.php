<?php
/**
 * Creates the "Tinylist Connector" SOAP/XML-RPC API role, granting exactly the
 * resources checked in the reference tree. Idempotent: skips creation if a role
 * with the same name already exists.
 *
 * @var Mage_Core_Model_Resource_Setup $this
 */
$this->startSetup();

$roleName = 'Tinylist Connector';

/**
 * Resource IDs are the slash-joined ACL paths from the core api.xml files.
 * Only the checked nodes from the reference tree are listed; parent containers
 * are included as shown so the created role mirrors the reference role exactly.
 * Prune to leaf resources only if you want strict least-privilege.
 */
$resources = array(
    // Shopping cart (Mage_Checkout)
    'cart',
    'cart/create',
    'cart/order',
    'cart/info',
    'cart/totals',
    'cart/license',
    'cart/product',
    'cart/product/add',
    'cart/product/update',
    'cart/product/remove',
    'cart/product/list',
    'cart/customer',
    'cart/customer/set',
    'cart/customer/addresses',
    'cart/shipping',
    'cart/shipping/method',
    'cart/shipping/list',
    'cart/payment',
    'cart/payment/method',
    'cart/payment/list',

    // Core (store list + Magento info)
    'core',
    'core/store',
    'core/store/list',
    'core/magento',
    'core/magento/info',

    // Catalog
    'catalog',
    'catalog/category',
    'catalog/category/tree',
    'catalog/category/info',
    'catalog/product',
    'catalog/product/info',
    'catalog/product/attribute',
    'catalog/product/attribute/read',
    'catalog/product/attribute/types',
    'catalog/product/attribute/info',
    'catalog/product/attribute/option',
    'catalog/product/attribute/set',
    'catalog/product/attribute/set/list',
    'catalog/product/link',
    'catalog/product/media',
    'catalog/product/tag',        // Magento 1.9 CE product tags API
    'catalog/product/tag/list',
    'catalog/product/tag/info',
    'catalog/product/option',
    'catalog/product/option/types',
    'catalog/product/option/info',
    'catalog/product/option/list',
    'catalog/product/option/value',
    'catalog/product/option/value/list',
    'catalog/product/option/value/info',

    // Sales (read only)
    'sales',
    'sales/order',
    'sales/order/info',
    'sales/order/shipment',
    'sales/order/shipment/info',
    'sales/order/invoice',
    'sales/order/invoice/info',
    'sales/order/creditmemo',
    'sales/order/creditmemo/info',
    'sales/order/creditmemo/list',

    // Catalog inventory (stock read)
    'cataloginventory',
    'cataloginventory/info',

    // Directory
    'directory',
    'directory/country',
    'directory/region',
);

/** @var Mage_Api_Model_Roles $existing */
$existing = Mage::getModel('api/roles')->getCollection()
    ->addFieldToFilter('role_type', 'G')
    ->addFieldToFilter('role_name', $roleName)
    ->getFirstItem();

if (!$existing->getId()) {
    /** @var Mage_Api_Model_Roles $role */
    $role = Mage::getModel('api/roles')
        ->setName($roleName)
        ->setRoleName($roleName)
        ->setPid(0)
        ->setRoleType('G')
        ->save();

    Mage::getModel('api/rules')
        ->setRoleId($role->getId())
        ->setResources($resources)
        ->saveRel();
}

$this->endSetup();

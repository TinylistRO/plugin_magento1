<?php
/**
 * SOAP API resource: return an order's invoice as a base64-encoded PDF.
 *
 * Exposed as the v2 method tinylistDropshippingOrderInvoicePdf. Uses the
 * existing sales/order/invoice/info ACL resource so the connector role can
 * call it without an extra grant.
 */
class Tinylist_Dropshipping_Model_Order_Invoice_Api extends Mage_Api_Model_Resource_Abstract
{
    /**
     * @param string $orderIncrementId
     * @return string base64-encoded PDF, or '' when the order has no invoice
     */
    public function pdf($orderIncrementId)
    {
        /** @var Mage_Sales_Model_Order $order */
        $order = Mage::getModel('sales/order')->loadByIncrementId($orderIncrementId);
        if (!$order->getId()) {
            $this->_fault('order_not_exists');
        }

        $invoices = $order->getInvoiceCollection();
        if (!$invoices || !count($invoices)) {
            // No invoice generated for this order yet — nothing to send.
            return '';
        }

        try {
            $pdf = Mage::getModel('sales/order_pdf_invoice')->getPdf($invoices);
            $content = $pdf->render();
        } catch (Exception $e) {
            Mage::logException($e);
            $this->_fault('invoice_pdf_failed', $e->getMessage());
            return '';
        }

        return base64_encode($content);
    }
}

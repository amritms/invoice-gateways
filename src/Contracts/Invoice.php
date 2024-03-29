<?php

namespace Amritms\InvoiceGateways\Contracts;

interface Invoice
{
     /**
     * Create invoice and send.
     */
    public function create($input = []);

    /**
     * Create invoice and save it in draft.
     */
    public function createAndSend($input = []);

    /**
     * Update draft invoice.
     */
    public function update($input = []);

    /**
     * Send invoice to the customer via email.
     */
    public function send($input = []);

    /**
     * Delete invoice
     */
    public function delete($input = []);

    /**
     * Create Customer
     */
    public function createCustomer($input = []);

    /**
     * Create Item or Product
     */
    public function createProduct($input = [], $invoice_number);
   
   /**
    * Get products/services/items
    */
     
    public function getItems($page_limit = 20);
    public function syncCustomers();
    
}

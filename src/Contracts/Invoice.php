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

    public function createCustomer($input = []);

    public function createProduct($input = []);
}

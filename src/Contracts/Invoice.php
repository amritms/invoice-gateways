<?php

namespace Amritms\InvoiceGateways\Contracts;

interface Invoice
{
     /**
     * Create invoice and send.
     */
    public function create();
    
    /**
     * Create invoice and save it in draft.
     */
    public function saveInDraft();

    /**
     * Update draft invoice.
     */
    public function update();

    /**
     * Send invoice to the customer via email.
     */
    public function send();
}
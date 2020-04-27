<?php

namespace Amritms\InvoiceGateways;

use Amritms\InvoiceGateway\Contracts\Invoice;


class InvoiceGateways
{
    // protected $invoice;

    // public function __construct(Invoice $invoice)
    // {
    //     $this->invoice = $invoice;    
    // }
    /**
     * Create invoice and send.
     */
    public function create()
    {
        // get config form laravel
// config()->set('payments.payment_type', 'freshbooks');
        // get invoice method from laravel
        // execute the method (provided by laravel)
        return app(Invoice::class)->create();
        // return $this->invoice->create();
        // return the value
        return 'hello from invoice gateways create method';
    }
    
    /**
     * Create invoice and save it in draft.
     */
    public function saveInDraft()
    {

    }

    /**
     * Get Draft Invoice for edit.
     */
    public function getDraft($invoice_id)
    {

    }

    /**
     * Update draft invoice.
     */
    public function update()
    {

    }

    /**
     * Send invoice to the customer via email.
     */
    public function saveAndSend()
    {

    }
}

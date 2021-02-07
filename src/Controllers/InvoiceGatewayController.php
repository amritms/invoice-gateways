<?php

namespace Amritms\InvoiceGateways\Controllers;

use Illuminate\Http\Request;
use Amritms\InvoiceGateways\Contracts\Invoice;
use Illuminate\Support\Facades\Auth;

class InvoiceGatewayController implements Invoice
{
    protected $invoice;
    public function __construct(Invoice $invoice)
    {
        $this->invoice = $invoice;
    }

    public function create($input = [])
    {
        return $this->invoice->create($input);
    }

    public function createAndSend($input = [])
    {
        return $this->invoice->createAndSend($input);
    }

    public function update($input = [])
    {
        return $this->invoice->update($input);
    }

    public function send($input = [])
    {
        return $this->invoice->send($input);
    }

    public function delete($input = [])
    {
        return $this->invoice->delete($input);
    }

    public function createCustomer($input = []){}

    public function createProduct($input = [],$invoice_number){}

    public function syncContacts()
    {
        return $this->invoice->syncContacts();
    }
}

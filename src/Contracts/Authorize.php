<?php
namespace Amritms\InvoiceGateways\Contracts;

interface Authorize
{
    public function authorize();

    public function callback();
}

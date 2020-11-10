<?php
namespace Amritms\InvoiceGateways\Contracts;

use Illuminate\Http\Request;

interface Authorize
{
    public function authorize();

    public function callback(Request $request);
}

<?php

namespace Amritms\InvoiceGateways\Controllers;

use Illuminate\Http\Request;
use Amritms\InvoiceGateways\Contracts\Authorize;

class AuthorizeController implements Authorize
{
    protected $authorize;

    public function __construct(Authorize $authorize)
    {
        $this->authorize = $authorize;
    }

    public function authorize()
    {
        return $this->authorize->authorize();
    }

    public function callback(Request $request)
    {
        return $this->authorize->callback($request);
    }

    public function refreshToken()
    {
        return $this->authorize->refreshToken();
    }
}

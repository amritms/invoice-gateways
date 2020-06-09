<?php

namespace Amritms\InvoiceGateways\Controllers;

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

    public function callback()
    {
        return $this->authorize->callback();
    }

    public function refreshToken()
    {
        return $this->authorize->refreshToken();
    }
}

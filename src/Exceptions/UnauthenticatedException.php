<?php

namespace Amritms\InvoiceGateways\Exceptions;

use Symfony\Component\HttpKernel\Exception\HttpException;

class UnauthenticatedException extends HttpException
{
    public static function forInvoiceCreate($message = null, $status_code = 401)
    {
        return new static($status_code, $message ?? 'Something went wrong, Couldn\'t create invoice. Try again',null, [], $status_code);
    }

    public static function forCustomercreate($message = null, $status_code = 401)
    {
        return new static($status_code, $message ?? 'Something went wrong, Couldn\'t create customer', null, [], $status_code);
    }

    public static function forInvoiceSend($message = null, $status_code = 401)
    {
        return new static(401, $message ?? 'Something went wrong, Couldn\'t send invoice. Try again',null, [], $status_code);
    }
}

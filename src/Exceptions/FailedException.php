<?php

namespace Amritms\InvoiceGateways\Exceptions;

use Symfony\Component\HttpKernel\Exception\HttpException;

class FailedException extends HttpException
{
    public static function forProductCreate($message = null, $status_code = 400)
    {
        return new static($status_code, $message ?? 'couldn\'t create product.');
    }

    public static function forCustomerCreate($message = null, $status_code = 400)
    {
        return new static($status_code, $message ?? 'couldn\'t create customer.');
    }

    public static function forCustomerAll($message = null, $status_code = 400)
    {
        return new static($status_code, $message ?? 'couldn\'t list customer.');
    }

    public static function forCustomerSync($message = null, $status_code = 400)
    {
        return new static($status_code, $message ?? 'couldn\'t sync customers.');
    }

    public static function forInvoiceCreate($message = null, $status_code = 400)
    {
        return new static($status_code, $message ?? 'Something went wrong, Couldn\'t create invoice.');
    }

    public static function forInvoiceSend($message = null, $status_code = 400)
    {
        return new static($status_code, $message ?? 'Something went wrong, Couldn\'t send invoice.');
    }

    public static function forInvoiceDelete($message = null, $status_code = 400)
    {
        return new static($status_code, $message ?? 'Something went wrong, Couldn\'t delete invoice.');
    }
}

<?php

namespace Amritms\InvoiceGateways\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @see \Amritms\InvoiceGateways\Skeleton\SkeletonClass
 */
class InvoiceGateways extends Facade
{
    /**
     * Get the registered name of the component.
     *
     * @return string
     */
    protected static function getFacadeAccessor()
    {
        return 'invoice-gateways';
    }
}

<?php

namespace Amritms\InvoiceGateways;

use Illuminate\Support\Facades\Facade;

/**
 * @see \Amritms\InvoiceGateways\Skeleton\SkeletonClass
 */
class InvoiceGatewaysFacade extends Facade
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

<?php

namespace Amritms\InvoiceGateways\Tests;

use Orchestra\Testbench\TestCase;
use Amritms\InvoiceGateways\InvoiceGatewaysServiceProvider;

class ExampleTest extends TestCase
{

    protected function getPackageProviders($app)
    {
        return [InvoiceGatewaysServiceProvider::class];
    }
    
    /** @test */
    public function true_is_true()
    {
        $this->assertTrue(true);
    }
}

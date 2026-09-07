<?php

namespace Amritms\InvoiceGateways\Tests;

use Orchestra\Testbench\TestCase;
use PHPUnit\Framework\Attributes\Test;
use Amritms\InvoiceGateways\InvoiceGatewaysServiceProvider;

class ExampleTest extends TestCase
{

    protected function getPackageProviders($app)
    {
        return [InvoiceGatewaysServiceProvider::class];
    }
    
    #[Test]
    public function true_is_true()
    {
        $this->assertTrue(true);
    }
}

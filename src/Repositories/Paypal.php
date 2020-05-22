<?php

namespace Amritms\InvoiceGateways\Repositories;

use PayPal\Api\Cost;
use PayPal\Api\Phone;
use PayPal\Api\Address;
use PayPal\Api\Invoice;
use PayPal\Api\Currency;
use PayPal\Api\BillingInfo;
use PayPal\Api\InvoiceItem;
use PayPal\Api\PaymentTerm;
use PayPal\Rest\ApiContext;
use PayPal\Api\MerchantInfo;
use PayPal\Api\ShippingInfo;
use PayPal\Api\InvoiceAddress;
use PayPal\Auth\OAuthTokenCredential;
use Amritms\InvoiceGateways\Contracts\Invoice as InvoiceContract;

class Paypal implements InvoiceContract
{
    function __construct()
    {

    }

    /**
     * Create invoice and send.
     */
    public function create()
    {
        // require __DIR__.'/../bootstrap.php';
        // Replace these values by entering your own ClientId and Secret by visiting https://developer.paypal.com/developer/applications/
        $clientId = 'AQd3X2Y2LadGNIGSqHQLtY5usuDctqrHvMiLZC9B-AvFe_3hF7xHY32ah2J3FoKowTAQQClv4SaKB_dt';
        $clientSecret = 'EOZBGcv62-HA2EXuRrLyxlhu0Qsc8Xs72XhJzc10QWGsfVHRXUf-fhdkCZE-MDATcX-MuviBWlfnRuOQ';
        $user_secret_code = 'test'; // from config -> from database.

        $apiContext = $this->getApiContext($clientId, $clientSecret);

        $invoice = new Invoice();

        $invoice
            ->setMerchantInfo(new MerchantInfo())
            ->setBillingInfo(array(new BillingInfo()))
            ->setNote('Medical Invoice 16 Jul, 2013 PST')
            ->setPaymentTerm(new PaymentTerm())
            ->setShippingInfo(new ShippingInfo());

        $invoice->getMerchantInfo()
            ->setEmail('jaypatel512-facilitator@hotmail.com')
            ->setFirstName('Dennis')
            ->setLastName('Doctor')
            ->setbusinessName('Medical Professionals, LLC')
            ->setPhone(new Phone())
            ->setAddress(new Address());

        $invoice->getMerchantInfo()->getPhone()
            ->setCountryCode('001')
            ->setNationalNumber('5032141716');

        $invoice->getMerchantInfo()->getAddress()
            ->setLine1('1234 Main St.')
            ->setCity('Portland')
            ->setState('OR')
            ->setPostalCode('97217')
            ->setCountryCode('US');

        $billing = $invoice->getBillingInfo();
        $billing[0]->setEmail('example@example.com');

        $billing[0]->setBusinessName('Jay Inc')
            ->setAdditionalInfo('This is the billing Info')
            ->setAddress(new InvoiceAddress());

        $billing[0]->getAddress()
            ->setLine1('1234 Main St.')
            ->setCity('Portland')
            ->setState('OR')
            ->setPostalCode('97217')
            ->setCountryCode('US');

        $items = array();
        $items[0] = new InvoiceItem();
        $items[0]
            ->setName('Sutures')
            ->setQuantity(100)
            ->setUnitPrice(new Currency());

        $items[0]->getUnitPrice()
            ->setCurrency('USD')
            ->setValue(5);

        $tax = new \PayPal\Api\Tax();
        $tax->setPercent(1)->setName('Local Tax on Sutures');
        $items[0]->setTax($tax);

        $items[1] = new InvoiceItem();

        $item1discount = new Cost();
        $item1discount->setPercent('3');
        $items[1]
            ->setName('Injection')
            ->setQuantity(5)
            ->setDiscount($item1discount)
            ->setUnitPrice(new Currency());

        $items[1]->getUnitPrice()
            ->setCurrency('USD')
            ->setValue(5);

        $tax2 = new \PayPal\Api\Tax();
        $tax2->setPercent(3)->setName('Local Tax on Injection');
        $items[1]->setTax($tax2);

        $invoice->setItems($items);

        $cost = new Cost();
        $cost->setPercent('2');
        $invoice->setDiscount($cost);

        $invoice->getPaymentTerm()
            ->setTermType('NET_45');

        $invoice->getShippingInfo()
            ->setFirstName('Sally')
            ->setLastName('Patient')
            ->setBusinessName('Not applicable')
            ->setPhone(new Phone())
            ->setAddress(new InvoiceAddress());

        $invoice->getShippingInfo()->getPhone()
            ->setCountryCode('001')
            ->setNationalNumber('5039871234');

        $invoice->getShippingInfo()->getAddress()
            ->setLine1('1234 Main St.')
            ->setCity('Portland')
            ->setState('OR')
            ->setPostalCode('97217')
            ->setCountryCode('US');

        $invoice->setLogoUrl('https://www.paypalobjects.com/webstatic/i/logo/rebrand/ppcom.svg');

        $request = clone $invoice;

        try {
            $invoice->create($apiContext);
        } catch (Exception $ex) {
            dd($ex->getMessage());
            // ResultPrinter::printError('Create Invoice', 'Invoice', null, $request, $ex);
            exit(1);
        }
        // ResultPrinter::printResult('Create Invoice', 'Invoice', $invoice->getId(), $request, $invoice);

        return $invoice;
    }

    /**
     * Get Draft Invoice for edit.
     */
    public function getDraft()
    {
    }

    /**
     * Create invoice and save it in draft.
     */
    public function saveInDraft()
    {
    }

    /**
     * Update draft invoice.
     */
    public function update()
    {
    }

    /**
     * Send invoice to the customer via email.
     */
    public function send()
    {
    }

    /**
 * Helper method for getting an APIContext for all calls
 * @param string $clientId Client ID
 * @param string $clientSecret Client Secret
 * @return PayPal\Rest\ApiContext
 */
private function getApiContext($clientId, $clientSecret)
{

    // #### SDK configuration
    // Register the sdk_config.ini file in current directory
    // as the configuration source.

    if(!defined("PP_CONFIG_PATH")) {
        define("PP_CONFIG_PATH", __DIR__);
    }



    // ### Api context
    // Use an ApiContext object to authenticate
    // API calls. The clientId and clientSecret for the
    // OAuthTokenCredential class can be retrieved from
    // developer.paypal.com

    $apiContext = new ApiContext(
        new OAuthTokenCredential(
            $clientId,
            $clientSecret
        )
    );

    // Comment this line out and uncomment the PP_CONFIG_PATH
    // 'define' block if you want to use static file
    // based configuration

    $apiContext->setConfig(
        array(
            'mode' => 'sandbox',
            'log.LogEnabled' => false,
            'log.FileName' => '../PayPal.log',
            'log.LogLevel' => 'DEBUG', // PLEASE USE `INFO` LEVEL FOR LOGGING IN LIVE ENVIRONMENTS
            'cache.enabled' => false,
            //'cache.FileName' => '/PaypalCache' // for determining paypal cache directory
            // 'http.CURLOPT_CONNECTTIMEOUT' => 30
            // 'http.headers.PayPal-Partner-Attribution-Id' => '123123123'
            //'log.AdapterFactory' => '\PayPal\Log\DefaultLogFactory' // Factory class implementing \PayPal\Log\PayPalLogFactory
        )
    );

    // Partner Attribution Id
    // Use this header if you are a PayPal partner. Specify a unique BN Code to receive revenue attribution.
    // To learn more or to request a BN Code, contact your Partner Manager or visit the PayPal Partner Portal
    // $apiContext->addRequestHeader('PayPal-Partner-Attribution-Id', '123123123');

    return $apiContext;
}
}

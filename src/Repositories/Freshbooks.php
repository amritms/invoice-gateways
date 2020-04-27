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

class Freshbooks implements InvoiceContract
{
    function __construct()
    {

    }

    /**
     * Create invoice and send.
     */
    public function create()
    {
        return 'holla from freshbooks create.';
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

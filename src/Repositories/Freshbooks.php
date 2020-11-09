<?php

namespace Amritms\InvoiceGateways\Repositories;

use Carbon\Carbon;
use Amritms\InvoiceGateways\Models\Contact;


use Sabinks\FreshbooksClientPhp\FreshbooksClientPhp;
use Amritms\InvoiceGateways\Exceptions\FailedException;
use Amritms\InvoiceGateways\Exceptions\InvalidConfiguration;
use Amritms\InvoiceGateways\Repositories\AuthorizeFreshbooks;
use Amritms\InvoiceGateways\Contracts\Invoice as InvoiceContract;
use Amritms\InvoiceGateways\Models\InvoiceGateway as InvoiceGatewayModel;

class Freshbooks implements InvoiceContract
{
    public $client_id;
    public $client_secret;
    public $state;
    public $redirect_uri = '';

    protected $user_id;
    // customer's business id
    public $businessId;
    public $auth_token;
    public $refresh_token;
    public $incomeAccountId;
    public $invoice_id;
    private $freshbooks;

    function __construct($token = null, $businessId = null, array $config = []){   
        $this->client_id = $config['client_id'];
        if (empty($this->client_id)) {
            throw InvalidConfiguration::ClientIdNotSpecified();
        }
        $this->client_secret = $config['client_secret'];
        if (empty($this->client_secret)) {
            throw InvalidConfiguration::ClientSecretNotSpecified();
        }
        $this->user_id = \Auth::id();
        $this->populateConfigFromDb();
        // $this->redirect_uri = url('freshbooks/redirect-back');
        $this->state = 'csrf_protection_' . $this->user_id;
        $config = config('invoice-gateways.freshbooks');
        $expires_time = Carbon::parse($config['expires_in']);
        if ($config['access_token'] == null || (! empty($config['expires_in']) && now()->greaterThanOrEqualTo($expires_time))){
            (new AuthorizeFreshbooks($config))->refreshToken();
        }
        $this->freshbooks = new FreshbooksClientPhp( null, null, config('invoice-gateways.freshbooks'));
        return $this->freshbooks;
    }

    /**
     * Create invoice and send.
     */
    public function create($input = []){  
        $variables = [
            "businessId" => $this->businessId,
            "customerId" => $input['customer_id'],
            "customerEmail" => $input['billing_address'],
            "status" => $input['invoice_create_status'] ?? 'SAVED',
            'product' => $input['product'],
            "invoiceNumber" => $input['invoice_number'] ?? "",
        ];
        $response = $this->freshbooks->invoiceCreate($variables);
        if(isset($response['invoice'])){
            // \Log::debug('Freshbooks invoice created successfully for user_id: ' . $this->user_id, ['_trace' => $response]);
            $invoice = $response['invoice'];
            return ['success' => true, 'message' => 'Invoice created successfully', 
                    'data' => [
                        'id' => $invoice['invoiceid'],
                        'invoice_number' => $invoice['invoice_number'],
                        'pdfUrl' => '',
                        'viewUrl' => '',
                        'status' => 'SAVED',
                    ]
                ];
        }

        \Log::error('Could\'t create freshbooks invoice. user_id:' . $this->user_id, ['data' => $response]);
        if(isset($response[0]['errno'])){
            $code = $response[0]['errno'] ?? $response[0]['errno'] == 'INVALID' ? 422 : 400;
            throw FailedException::forInvoiceCreate($response['data']['invoiceCreate']['inputErrors'][0]['message'], $code);
        }
        throw FailedException::forInvoiceCreate();
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
    public function update($input = [])
    {
    }

    /**
     * Send invoice to the customer via email.
     */
    public function send($input = []){
        $input = [
            "invoiceId" => $input['invoice_id'],
            "to" => $input['email'],
            "subject" => $input['subject'] ?? '',
            "message" => $input['message'] ?? '',
            "attachPDF" => true
        ];
        
        $response = $this->freshbooks->invoiceSend($input);
       
        if(isset($response['invoice'])){
            \Log::debug('Freshbooks Invoice sent successfully for user_id:' . $this->user_id);
            request()->session()->flash('message', 'Invoice sent successfully.');
            $invoice = $response['invoice'];
            
            $share_data = $this->freshbooks->getShareLink($invoice['id']);
            if($share_data['share_link']){
                return ['success' => true, 'data' => [
                    'share_link' => $share_data['share_link'],
                    'share_pdf'  => ''
                ]];
            }  
        }

        if(isset($response[0]['errno']) && $response[0]['errno'] == '1003'){
            (new AuthorizeFreshbooks( config('invoice-gateways.freshbooks')))->refreshToken();
            request()->session()->flash('message', 'Something went wrong, Invoice couldn\'t be sent. Try again');
            \Log::error('Try again. Something went wrong while sending freshbooks invoice for user_id: ' . $this->user_id, ['_trace' => $response]);

            throw UnauthenticatedException::forInvoiceSend();
        }

        request()->session()->flash('message', 'Something went wrong, Invoice couldn\'t be sent');
        \Log::error('Something went wrong while sending freshbooks invoice for user_id: ' . $this->user_id, ['_trace' => $response]);

        throw FailedException::forInvoiceSend();
        
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
    /**
     * Sync remote customers with your contact comparing email address.
     * @return array
     */
    public function syncCustomers(){
        try{
            $customers                   = $this->getAllCustomers();
            $customers                   = collect($customers['data'])->unique('email');
            $emails                      = $customers->pluck('email');
            $customers_email_id_key_pair = $customers->mapWithKeys(function ($customer) {
                return [$customer['email'] => $customer['id']];
            });
            $contacts = Contact::whereIn('email', $emails)->where('user_id', \Auth::id())->get();
    
            \Log::debug('Freshbooks customer import start for user_id:' . $this->user_id);
    
            $contacts->map(function ($contact) use ($customers_email_id_key_pair) {
                if(isset($customers_email_id_key_pair[$contact->email])){
                    $contact->customer_id = $customers_email_id_key_pair[$contact->email];
                    $contact->save();
                    \Log::debug($contact->email);
                }
                return true;
            });
            (new InvoiceGatewayModel)->where(['user_id' => \Auth::id()])->update(['contact_sync_at' => now()]);
    
            \Log::debug('Freshbooks customer import completed for user_id:' . $this->user_id);
    
            return ['success' => true, 'data' => $contacts];
        }catch (\Exception $exception){
                throw FailedException::forCustomerSync($exception->getMessage());
            }
    }
    public function createAndSend($input = []){
        
    }
    public function delete($input = []){
        return $this->freshbooks->deleteInvoice($input);
    }


    public function createProduct($product = []){
        $response = $this->freshbooks->createProduct($product);
        if(isset($response[0]['errno']) && $response[0]['errno'] ==  1003){
            \Log::debug('Couldn\'t create product for creating invoice for user_id:' . $this->user_id, ['_trace' => $response]);
            (new AuthorizeFreshbooks( config('invoice-gateways.freshbooks')))->refreshToken();

            throw UnauthenticatedException::forCustomerAll();
        }elseif(isset($response[0]['errno']) && $response[0]['errno'] ==  12002){
            \Log::error('Product already created' . $this->user_id, ['_trace' => $response]);
            throw FailedException::forProductCreate('Product already created on Freshbooks Item List!',422);
        }elseif(isset($response[0])){
            \Log::error('couldn\'t create freshboooks product for user_id: ' . $this->user_id, ['_trace' => $response]);
            throw FailedException::forProductCreate();
        }

        // \Log::debug('Freshbooks Product created successfully for user_id:' . $this->user_id, ['_trace' => $response]);
       
        return [
            'id' => $response['item']['id'], 
            'name' => $response['item']['name'],
            'product' => $response['item']
        ];
    }

    private function populateConfigFromDb(){
        $config = InvoiceGatewayModel::where('user_id', $this->user_id)->select('config')->firstOrFail();

        $this->businessId      = $config['config']['businessId'] ?? null;
        $this->refresh_token   = $config['config']['refresh_token'] ?? null;
        $this->incomeAccountId = $config['config']['incomeAccountId'] ?? null;
        $this->access_token    = $config['config']['access_token'] ?? null;
        $this->expires_in      = $config['config']['expires_in'] ?? null;

        config(['invoice-gateways.freshbooks.businessId' => $this->businessId]);
        config(['invoice-gateways.freshbooks.refresh_token' => $this->refresh_token]);
        config(['invoice-gateways.freshbooks.incomeAccountId' => $this->incomeAccountId]);
        config(['invoice-gateways.freshbooks.access_token' => $this->access_token]);
        config(['invoice-gateways.freshbooks.expires_in' => $this->expires_in]);
    }

    public function getAllCustomers(){
        $variables = [
            "businessId" => $this->businessId
        ];
        $response = $this->freshbooks->customers($variables);
        \Log::info($response);
        if(isset($response[0]['errno']) && $response[0]['errno'] ==  1003){
            \Log::debug('Couldn\'t list freshbooks customers for user_id:' . $this->user_id, ['_trace' => $response]);
            (new AuthorizeFreshbooks( config('invoice-gateways.freshbooks')))->refreshToken();
            throw UnauthenticatedException::forCustomerAll();
        }
        if(isset($response['clients']) && $response['clients'] !== null){
            \Log::debug('Customer listed freshbooks successfully for user_id:' . $this->user_id, ['_trace' => $response]);
            $return_result = [];
            foreach($response['clients'] as $customer){
                $return_result[] = [
                    'id' => $customer['id'],
                    'name' => $customer['fname'] . ' ' . $customer['lname'],
                    'email' => $customer['email']
                ];
            }
            return ['success' => true, 'data' => $return_result];
        }

        \Log::debug('Couldn\'t list freshbooks customers for user_id:' . $this->user_id, ['_trace' => $response]);
        throw FailedException::forCustomerAll();
    }
    public function createCustomer($input = []){
        return $this->freshbooks->createCustomer($input);
    }

    public function downloadInvoice($invoice_id){
        return $this->freshbooks->downloadInvoice($invoice_id);
    }
}
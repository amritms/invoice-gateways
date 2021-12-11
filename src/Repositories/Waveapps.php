<?php

namespace Amritms\InvoiceGateways\Repositories;

use Amritms\InvoiceGateways\Exceptions\FailedException;
use Amritms\InvoiceGateways\Exceptions\InvalidConfiguration;
use Amritms\InvoiceGateways\Exceptions\UnauthenticatedException;
use Amritms\InvoiceGateways\Models\Contact;
use Amritms\InvoiceGateways\Models\InvoiceGateway as InvoiceGatewayModel;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Amritms\InvoiceGateways\Contracts\Invoice as InvoiceContract;

class Waveapps implements InvoiceContract
{
    public $client_id;
    public $client_secret;
    public $state;
    public $redirect_uri = '';

    protected $graphql_url;
    protected $user_id;
    // customer's business id
    public $businessId;
    public $auth_token;
    public $refresh_token;
    public $incomeAccountId;
    public $invoice_id;
    private $waveapps;

    public function __construct($graphqlUrl = null, $token = null, $businessId = null, array $config = [])
    {
        $this->client_id = $config['client_id'];
        if (empty($this->client_id)) {
            throw InvalidConfiguration::ClientIdNotSpecified();
        }

        $this->client_secret = $config['client_secret'];
        if (empty($this->client_secret)) {
            throw InvalidConfiguration::ClientSecretNotSpecified();
        }

        $this->user_id = Auth::id();
        $this->populateConfigFromDb();

        $this->redirect_uri = url('waveapps/redirect-back');
        $this->state = 'csrf_protection_' . $this->user_id;
        $config = config('invoice-gateways.waveapps');

        if ($config['access_token'] == null || (! empty($config['expires_in']) && now()->greaterThanOrEqualTo($config['expires_in']))){
            (new AuthorizeWaveapps($config))->refreshToken();
        }

        $this->waveapps = new \Amritms\WaveappsClientPhp\Waveapps(null, null, null, config('invoice-gateways.waveapps'));
    }

    /**
     * 3. Using access tokens
     * Your application should POST to: https://api.waveapps.com/oauth2/token/
     * fetch all invoices of a business (customer)
     */
    public function getAllInvoices()
    {
        $url = $this->graphql_url;
        $variables = [
            "businessId" => $this->businessId,
            "page" => 1,
            "pageSize" => 20
        ];

        $response = $this->waveapps->invoices($variables);
        if(isset($response['errors'])){
            \Log::error('couldn\'t fetch all waveapps invoices for user_id:' . $this->user_id, ['_trace' => $response]);
            return ['success' => false, 'data' => $response];
        }

        return ['success' => true, 'data' => $response];
    }

    /**
     * create invoice
     * https://developer.waveapps.com/hc/en-us/articles/360038817812-Mutation-Create-invoice
     * @param $input array
     * @return array
     */
    public function create($input = [])
    {
        $product_id = $input['product_id'];
        $customer_id = $input['customer_id'];

        $new_input = [
            "businessId" => $this->businessId,
            "customerId" => $customer_id,
            "status" => $input['invoice_create_status'] ?? 'SAVED',
            "items" => [
                'productId' => $product_id,
                'description' => $input['description'],
                'unitPrice' => (float ) $input['price']
            ],
            "invoiceNumber" => $input['invoice_number'] ?? "",
        ];

        $variables = [
            'input' => $new_input,
        ];

        $response = $this->waveapps->invoiceCreate($variables, 'InvoiceCreateInput');

        if(isset($response['data']['invoiceCreate']['didSucceed']) && $response['data']['invoiceCreate']['didSucceed'] == true){
            \Log::debug('waveapps invoice created successfully for user_id: ' . $this->user_id, ['_trace' => $response]);

            return ['success' => true, 'message' => 'Invoice created successfully', 'data' => $response['data']['invoiceCreate']['invoice']];
        }

        \Log::error('couldn\'t create waveapps invoice. user_id:' . $this->user_id, ['data' => $response]);
        if(isset($response['data']['invoiceCreate']['inputErrors'][0]['message'])){
            $code = $response['data']['invoiceCreate']['inputErrors'][0]['code'] ?? $response['data']['invoiceCreate']['inputErrors'][0]['code'] == 'INVALID' ? 422 : 400;
            throw FailedException::forInvoiceCreate($response['data']['invoiceCreate']['inputErrors'][0]['message'], $code);
        }
        throw FailedException::forInvoiceCreate();
    }

    /**
     * Get all customers
     * https://developer.waveapps.com/hc/en-us/articles/360032908311-Query-List-and-sort-customers
     * @return array|string
     */
    private function getAllCustomers()
    {
        $variables = [
            "businessId" => $this->businessId
        ];

        $response = $this->waveapps->customers($variables);

        if(isset($response['errors'][0]['extensions']['code']) && $response['errors'][0]['extensions']['code'] ==  "UNAUTHENTICATED"){
            \Log::debug('Couldn\'t list waveapps customers for user_id:' . $this->user_id, ['_trace' => $response]);
            (new AuthorizeWaveapps( config('invoice-gateways.waveapps')))->refreshToken();

            throw UnauthenticatedException::forCustomerAll();
        }

        if(isset($response['data']['business']['customers']) && $response['data']['business']['customers'] !== null){
            \Log::debug('Customer listed waveapps successfully for user_id:' . $this->user_id, ['_trace' => $response]);

            $return_result = [];
            foreach($response['data']['business']['customers']['edges'] as $customer){
                $return_result[] = [
                    'id' => $customer['node']['id'],
                    'name' => $customer['node']['name'],
                    'email' => $customer['node']['email']
                ];
            }

            return ['success' => true, 'data' => $return_result];
        }

        \Log::debug('Couldn\'t list waveapps customers for user_id:' . $this->user_id, ['_trace' => $response]);

        throw FailedException::forCustomerAll();
    }

    /**
     * Sync remote customers with your contact comparing email address.
     * @return array
     */
    public function syncCustomers()
    {
        try{
        $customers                   = $this->getAllCustomers();
        $customers                   = collect($customers['data'])->unique('email');
        $emails                      = $customers->pluck('email');
        $customers_email_id_key_pair = $customers->mapWithKeys(function ($customer) {
            return [$customer['email'] => $customer['id']];
        });

        $contacts = Contact::whereIn('email', $emails)->where('user_id', \Auth::id())->get();

        \Log::debug('waveapps customer import start for user_id:' . $this->user_id);

        $contacts->map(function ($contact) use ($customers_email_id_key_pair) {
            if(isset($customers_email_id_key_pair[$contact->email])){
                $contact->customer_id = $customers_email_id_key_pair[$contact->email];
                $contact->save();
                \Log::debug($contact->email);
            }

            return true;
        });

        (new InvoiceGatewayModel)->where(['user_id' => \Auth::id()])->update(['contact_sync_at' => now()]);

        \Log::debug('waveapps customer import completed for user_id:' . $this->user_id);

        return ['success' => true, 'data' => $contacts];
    }catch (\Exception $exception){
            throw FailedException::forCustomerSync($exception->getMessage());
        }
    }
    /**
     * Get all products
     * https://developer.waveapps.com/hc/en-us/articles/360032572872-Query-Paginate-list-of-products
     * @return array|string
     */
    public function getAllProducts()
    {
        $url = 'https://gql.waveapps.com/graphql/public';
        $variables = [
            "businessId" => $this->businessId,
            'page'=> 1,
            'pageSize' => 50
        ];

        $response = $this->waveapps->products($variables);

        if(isset($response['errors'][0]['extensions']['code']) && $response['errors'][0]['extensions']['code'] ==  "UNAUTHENTICATED"){
            \Log::debug('Couldn\'t list waveapps products for user_id:' . $this->user_id, ['_trace' => $response]);

            return ['success' => false, 'message' => 'UNAUTHENTICATED', 'data' => $response];
        }

        if(isset($response['data']['business']['products']) && $response['data']['business']['products'] !== null){
            request()->session()->flash('message', 'product created successfully.');
            \Log::debug('waveapps Products listed successfully for user_id:' . $this->user_id, ['_trace' => $response]);

            $return_result = [];
            foreach($response['data']['business']['products']['edges'] as $product){
                $return_result[] = ['id' => $product['node']['id'], 'name' => $product['node']['name']];
            }

            return ['success' => true, 'data' => $return_result];
        }

        return ['success' => false, 'data' => $response];
    }

    /**
     * Get Account Id for invoice
     * https://developer.waveapps.com/hc/en-us/articles/360032572872-Query-Paginate-list-of-products
     * @return array|string
     */
    public function getAccountId()
    {
        $url = 'https://gql.waveapps.com/graphql/public';
        $post_data = [ "query" => 'query ($businessId: ID!) {
          business(id: $businessId) {
            id
            name
            accounts (types:INCOME, subtypes:INCOME){
              edges{
                node{
                  id
                  name
                  description
                  type{
                    name
                    normalBalanceType
                    value
                  }
                }
              }
            }
          }
        }',
            'variables' => [
                "businessId" => $this->businessId,
                'page'=> 1,
                'pageSize' => 500
            ]
        ];

        $response = HTTP::withHeaders([
            'Authorization' => 'Bearer ' . config('invoice-gateways.waveapps.access_token')
        ])->post($url, $post_data);

        if($response->status() !== 200){
            \Log::error('something went wrong. couldn\'t fetch account ID  for user_id: ' . $this->user_id, ['_trace' => $response->json()]);
            if(isset($response['errors'])){
                if($this->isTokenExpired($response)) {
                    \Log::error('waveapps token expired for user_id : ' . $this->user_id);
                    (new AuthorizeWaveapps( config('invoice-gateways.waveapps')))->refreshToken();
                }
                throw FailedException::forInvoiceCreate();
            }   
        }
        $return_result = [];

        foreach($response['data']['business']['accounts']['edges'] as $account){
            $return_result[] = ['id' => $account['node']['id']];
        }

        $invoice_config = InvoiceGatewayModel::firstOrNew(['user_id' => \Auth::user()->id]);
        $invoice_config->config = array_merge($invoice_config->config, ['incomeAccountId' => $response['data']['business']['accounts']['edges'][0]['node']['id']]);;
        $invoice_config->save();

        return ['success' => true, 'data' => $return_result];
    }

    /**
     * create customer from contact of job
     * @param array $customer['full_name' => '', 'first_name' => '', 'last_name' => '', 'email' => '', 'address1' => '', 'city' => '', 'country_code' => '', 'zone_code' => '']
     * @return array|bool
     */
    public function createCustomer($customer = [])
    {
        $currency_code = 'USD';
        $address = [];
        if(isset($customer['city'])) $address['city'] = $customer['city'];
        if(isset($customer['address1'])) $address['addressLine1'] = $customer['address1'];
        if(isset($customer['address2'])) $address['addressLine2'] = $customer['address2'];
        if(isset($customer['country_code'])) $address['countryCode'] = $customer['country_code'];
        if(isset($customer['country_code']) && isset($customer['zone_code'])) $address['provinceCode'] = $customer['country_code'] . '-' . $customer['zone_code'];

        $full_name = isset($customer['full_name']) ? $customer['full_name'] : 'Full Name1';
        $first_name = isset($customer['first_name']) ? $customer['first_name'] : 'First Name1';
        $last_name = isset($customer['last_name']) ? $customer['last_name'] : 'Last Name1';
        $email = isset($customer['email']) ? $customer['email'] : 'test1@test.com';

        $currency_code = $customer['currency_code'] ?? 'USD';

        $variables = ['input' => [
            "businessId" => $this->businessId,
            "name" => $full_name,
            "firstName" => $first_name,
            "lastName" => $last_name,
            "email" => $email,
            "address" => $address,
            "currency" => $currency_code
        ]];

        $response = $this->waveapps->customerCreate($variables, "CustomerCreateInput");

        if(isset($response['errors'][0]['extensions']['code']) && $response['errors'][0]['extensions']['code'] ==  "UNAUTHENTICATED"){
            \Log::debug('Couldn\'t create customer for creating invoice for user_id:' . $this->user_id, ['_trace' => $response]);
            (new AuthorizeWaveapps( config('invoice-gateways.waveapps')))->refreshToken();

            throw UnauthenticatedException::forCustomerCreate();
        }

        if(isset($response['data']['customerCreate']['didSucceed']) && $response['data']['customerCreate']['didSucceed'] == true){
            request()->session()->flash('message', 'product created successfully.');
            \Log::debug('Waveapps Customer created successfully for user_id:' . $this->user_id, ['_trace' => $response]);

            return ['id' => $response['data']['customerCreate']['customer']['id'], 'name' => $response['data']['customerCreate']['customer']['name']];
        }

        \Log::error('Couldn\'t create waveapps customer for creating invoice for user_id:' . $this->user_id, ['_trace' => $response]);

        throw FailedException::forCustomerCreate();
       }

    /**
     * create Product - job
     */
    public function createProduct($product = [], $invoice_number)
    {
        if(!$this->incomeAccountId){
            $accountId = $this->getAccountId();
            config(['invoice-gateways.waveapps.incomeAccountId' => $accountId['data'][0]['id']]);
        }

        $variables = [
            "input" => [
                "businessId" => $this->businessId,
                "name" => $product['description'] ?? 'untitled job',
                "unitPrice" => $product['amount'] ?? 1,
                "incomeAccountId" => config('invoice-gateways.waveapps.incomeAccountId'),
            ]
        ];

        $response = $this->waveapps->productCreate($variables, 'ProductCreateInput');

        if(isset($response['errors'][0]['extensions']['code']) && $response['errors'][0]['extensions']['code'] ==  "UNAUTHENTICATED"){
            \Log::debug('Couldn\'t create product for creating invoice for user_id:' . $this->user_id, ['_trace' => $response]);
            (new AuthorizeWaveapps( config('invoice-gateways.waveapps')))->refreshToken();
        }
        elseif(isset($response['errors'])){
            \Log::error('couldn\'t create waveapps product for user_id: ' . $this->user_id, ['_trace' => $response]);

            throw FailedException::forProductCreate();
        }

        \Log::debug('waveapps Product created successfully for user_id:' . $this->user_id, ['_trace' => $response]);

        return ['id' => $response['data']['productCreate']['product']['id'], 'name' => $response['data']['productCreate']['product']['name']];
    }

    /**
     * send invoice via email to user
     *
     * @param array $input
     * @return array|string
     */
    public function send($input = [])
    {
        $input = [
            "invoiceId" => $input['invoice_id'],
            "to" => $input['email'],
            "subject" => $input['subject'] ?? '',
            "message" => $input['message'] ?? '',
            "attachPDF" => true
        ];

        $variables = ['input' => $input];
        $response = $this->waveapps->invoiceSend($variables, 'InvoiceSendInput');

        if(isset($response['data']['invoiceSend']['didSucceed']) && $response['data']['invoiceSend']['didSucceed'] == true){
            \Log::debug('Waveapps Invoice sent successfully for user_id:' . $this->user_id);
            request()->session()->flash('message', 'Invoice sent successfully.');

            return response( ['success' => true, 'data' => $response], 200);
        }

        if(isset($response['errors'][0]['extensions']['code']) && $response['errors'][0]['extensions']['code'] == 'UNAUTHENTICATED'){
            (new AuthorizeWaveapps( config('invoice-gateways.waveapps')))->refreshToken();
            request()->session()->flash('message', 'Something went wrong, Invoice couldn\'t be sent. Try again');
            \Log::error('Try again. Something went wrong while sending waveapps invoice for user_id: ' . $this->user_id, ['_trace' => $response]);

            throw UnauthenticatedException::forInvoiceSend();
        }

        request()->session()->flash('message', 'Something went wrong, Invoice couldn\'t be sent');
        \Log::error('Something went wrong while sending waveapps invoice for user_id: ' . $this->user_id, ['_trace' => $response]);

        throw FailedException::forInvoiceSend();
    }

    public function delete($input = [])
    {
        if(!isset($input['invoice_id'])){
            throw InvalidConfiguration::InvoiceIdNotSpecified("Please provide Invoice ID");
        }
        $variables = ['input' => ['invoiceId' => $input['invoice_id']]];

        $response = $this->waveapps->invoiceDelete($variables, 'InvoiceDeleteInput');

        if(isset($response['data']['invoiceDelete']['didSucceed']) && $response['data']['invoiceDelete']['didSucceed'] == true){
            \Log::debug('Waveapps Invoice deleted successfully for user_id:' . $this->user_id);
            request()->session()->flash('message', 'Invoice deleted successfully.');

            return ['success' => true, 'data' => $response];
        }

        request()->session()->flash('message', 'Something went wrong, Couldn\'t delete invoice.');
        \Log::error('Something went wrong while deleting waveapps invoice for user_id: ' . $this->user_id, ['_trace' => $response]);

        throw FailedException::forInvoiceDelete();
    }

    public function createAndSend($input = [])
    {

    }

    public function update($input = [])
    {
        // TODO: Implement update() method.
    }

    /**
     * fetch waveapps config from table and populate config.
     */
    private function populateConfigFromDb()
    {
        $config = InvoiceGatewayModel::where('user_id', $this->user_id)->select('config')->firstOrFail();

        $this->businessId      = $config['config']['businessId'] ?? null;
        $this->refresh_token   = $config['config']['refresh_token'] ?? null;
        $this->incomeAccountId = $config['config']['incomeAccountId'] ?? null;
        $this->access_token    = $config['config']['access_token'] ?? null;
        $this->expires_in      = $config['config']['expires_in'] ?? null;

        config(['invoice-gateways.waveapps.businessId' => $this->businessId]);
        config(['invoice-gateways.waveapps.refresh_token' => $this->refresh_token]);
        config(['invoice-gateways.waveapps.incomeAccountId' => $this->incomeAccountId]);
        config(['invoice-gateways.waveapps.access_token' => $this->access_token]);
        config(['invoice-gateways.waveapps.expires_in' => $this->expires_in]);
    }

    public function getProductDetail($item_id) {

    }

    /**
     * fetch products/service from waveapps
     */

    public function getItems($page_limit = 20) {
        $variables = [
            "businessId" => $this->businessId,
            "page" => 1,
            "pageSize" => $page_limit
        ];

        $response = $this->waveapps->products($variables);
        if(isset($response['errors'])) {
            // \Log::error('Something went wrong while fetching waveapps items for user_id: ' . $this->user_id, ['_trace' => $response]);
            if($this->isTokenExpired($response)) {
                (new AuthorizeWaveapps( config('invoice-gateways.waveapps')))->refreshToken();
                return $this->getItems($page_limit);
            }
            throw FailedException::forInvoiceCreate("failed to get items");
            
        }

        $products = $response['data']['business']['products']['edges'];
        return array_map(function($product) {
            return $product['node'];
        },$products);
    }

    public function isTokenExpired($response){
        return (isset($response['errors'][0]['extensions']['code']) && $response['errors'][0]['extensions']['code'] == 'UNAUTHENTICATED');
    }
}

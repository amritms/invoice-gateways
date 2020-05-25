<?php

namespace Amritms\InvoiceGateways\Repositories;

use Amritms\InvoiceGateways\Models\Contact;
use Amritms\InvoiceGateways\Models\InvoiceGateway as InvoiceGatewayModel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\Http;
use Amritms\InvoiceGateways\Contracts\Invoice as InvoiceContract;
use Amritms\InvoiceGateways\Models\InvoiceGateway as InvoicesGatewayModel;

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
    public $income_account_id;
    public $invoice_id;
    private $waveapps;

    public function __construct($graphqlUrl = null, $token = null, $businessId = null, array $config = [])
    {
        $this->client_id = $config['client_id'];
        if (empty($this->client_id)) {
            throw new Exception("Please provide wave app's client id", 400);
        }

        $this->client_secret = $config['client_secret'];
        if (empty($this->client_secret)) {
            throw new Exception("Please provide wave app's client secret", 400);
        }
        $this->user_id = Auth::id();
        $this->populateConfigFromDb();

        $this->redirect_uri = url('waveapps/redirect-back');
        $this->state = 'csrf_protection_' . $this->user_id;
        $config = config('invoice-gateways.waveapps');

        if (session()->has('access_token') &&  ! session()->has('expires_in') && now()->lessThan(session('expires_in'))){
            config(['access_token' => session('access_token')]);
        }else{
            (new AuthorizeWaveapps($config))->refreshToken();
        }

        $this->waveapps = new \Amritms\WaveappsClientPhp\Waveapps(null, null, null, config('invoice-gateways.waveapps'));
    }

    public function index()
    {
        return view('welcome');
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
     * Let user select product and customer to create invoice
     * @return \Illuminate\Contracts\Foundation\Application|\Illuminate\Contracts\View\Factory|\Illuminate\View\View|string
     */
    public function createInvoiceForm(){
        $customers_result = $this->getAllCustomers();
        $products_result = $this->getAllProducts();

        if(! $customers_result['success'] || !$products_result['success']){
            return ('Make sure you have products and customers');
        }

        $customers = $customers_result['body'];
        $products = $products_result['body'];

        return view('invoices.waveapps.create_invoice', compact('customers', 'products'));
    }

    /**
     * Create invoice from data of current job.
     * @return array
     */
    public function createDraftInvoice(){
        $customer = $this->createCustomer();
        $product = $this->createProduct();

        $output['customer_id'] = $customer['id'];
        $output['product_id'] = $product['id'];

        return $this->createInvoice($output);
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

        $input = [
            "businessId" => $this->businessId,
            "customerId" => $customer_id,
            "items" => [
                'productId' => $product_id
            ]
        ];

        $variables = [
            'input' => $input
        ];

        $response = $this->waveapps->invoiceCreate($variables, 'InvoiceCreateInput');

        if(isset($response['data']['invoiceCreate']['didSucceed']) && $response['data']['invoiceCreate']['didSucceed'] == true){
            Cache::put($this->user_id . '_invoice_id', $response['data']['invoiceCreate']['invoice']['id'], now()->addMinutes(200));
            \Log::debug('waveapps invoice created successfully for user_id: ' . $this->user_id, ['_trace' => $response]);

            return ['success' => true, 'message' => 'Invoice created successfully', 'data' => $response['data']['invoiceCreate']['invoice']];
        }

        \Log::error('couldn\'t create waveapps invoice. user_id:' . $this->user_id, ['data' => $response]);

        return ['success' => false, 'message' => 'Couldn\'t create invoice', 'data' => $response];
    }

    /**
     * Get all customers
     * https://developer.waveapps.com/hc/en-us/articles/360032908311-Query-List-and-sort-customers
     * @return array|string
     */
    public function getAllCustomers()
    {
        $variables = [
            "businessId" => $this->businessId
        ];

        $response = $this->waveapps->customers($variables);

        if(isset($response['errors'][0]['extensions']['code']) && $response['errors'][0]['extensions']['code'] ==  "UNAUTHENTICATED"){
            \Log::debug('Couldn\'t list waveapps customers for user_id:' . $this->user_id, ['_trace' => $response]);

            return ['success' => false, 'message' => 'UNAUTHENTICATED', 'data' => $response];
        }

        if(isset($response['data']['business']['customers']) && $response['data']['business']['customers'] !== null){
            request()->session()->flash('message', 'product created successfully.');
            \Log::debug('Customer listed waveapps successfully for user_id:' . $this->user_id, ['_trace' => $response]);

            $return_result = [];
            foreach($response['data']['business']['customers']['edges'] as $product){
                $return_result[] = ['id' => $product['node']['id'], 'name' => $product['node']['name']];
            }

            return ['success' => true, 'data' => $return_result];
        }

        return ['success' => false, 'data' => $response];
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
            \Log::debug('waveapps Customer listed successfully for user_id:' . $this->user_id, ['_trace' => $response]);

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
            'Authorization' => 'Bearer ' . $this->auth_token
        ])->post($url, $post_data);

        if($response->status() !== 200){
            \Log::error('something went wrong. couldn\'t fetch waveapps account ID  for user_id: ' . $this->user_id, ['_trace' => $response->json()]);
            return ['success' => false, 'data' => $response];
        }

        if(isset($response['errors'])){
            \Log::error('something went wrong. couldn\'t fetch account ID  for user_id: ' . $this->user_id, ['_trace' => $response->json()]);
            return ['success' => false, 'data' => $response];
        }

        Cache::forever($this->user_id . '_income_account_id', $response['data']['business']['accounts']['edges'][0]['node']['id']);

        $return_result = [];
        foreach($response['data']['business']['accounts']['edges'] as $account){
            $return_result[] = ['id' => $account['node']['id']];
        }

        $model = new InvoicesGatewayModel;
        $model = $model->where(['user_id', $this->user_id])->firstOrNew();

        $model->invoice_type = 'waveapps';
        $model->config = json_encode(array_replace(json_decode($model->config), ["income_account_id" => $response['data']['business']['accounts']['edges'][0]['node']['id']]));
        $model->user_id =$this->user_id;

        $model->save();

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

            return ['success' => false, 'message' => 'UNAUTHENTICATED', 'data' => $response];
        }

        if(isset($response['data']['customerCreate']['didSucceed']) && $response['data']['customerCreate']['didSucceed'] == true){
            request()->session()->flash('message', 'product created successfully.');
            \Log::debug('Waveapps Customer created successfully for user_id:' . $this->user_id, ['_trace' => $response]);

            return ['id' => $response['data']['customerCreate']['customer']['id'], 'name' => $response['data']['customerCreate']['customer']['name']];
        }

        \Log::error('Couldn\'t create waveapps customer for creating invoice for user_id:' . $this->user_id, ['_trace' => $response]);

        return ['success' => false, 'data' => $response];
       }

    /**
     * create Product - job
     */
    public function createProduct($job = [])
    {
        $job = ['name' => 'Test Test may 141', 'amount' => '101.02'];
        $product['name'] = $job['name'];
        $product['unitPrice'] = $job['amount'];
        if(!$this->income_account_id){
            $this->getAccountId();
        }

        $variables = [
            "input" => [
                "businessId" => $this->businessId,
                "name" => $product['name'],
                "unitPrice" => $product['unitPrice'],
                "incomeAccountId" => $this->income_account_id,
            ]
        ];


        $response = $this->waveapps->productCreate($variables, 'ProductCreateInput');

        if(isset($response['errors'])){
            \Log::error('couldn\'t create waveapps product for user_id: ' . $this->user_id, ['_trace' => $response]);

            return response()->json(['success' => false, 'data' => $response])->status(400);
        }

        \Log::debug('waveapps Product created successfully for user_id:' . $this->user_id, ['_trace' => $response]);

        return ['id' => $response['data']['productCreate']['product']['id'], 'name' => $response['data']['productCreate']['product']['name']];
    }

    /**
     * Draft invoices can not be sent as emails. So need to approve it first.
     * It can be fired multiple times, don't throw error even if the invoice is already active.
     */
    public function approveInvoice()
    {
        $variables = [
            'input' => [
                "invoiceId" => $this->invoice_id
            ]
        ];

        $response = $this->waveapps->invoiceApprove($variables, 'InvoiceApproveInput');

        if(isset($response['data']['invoiceApprove']['didSucceed']) && $response['data']['invoiceApprove']['didSucceed']){
            request()->session()->flash('message', 'Invoice approved successfully.');
            \Log::debug('Waveapps Invoice approved successfully for user_id:' . $this->user_id);

            return ['success' => true, 'message' => 'Invoice successfully approved.', 'data' => $response];
        }

        \Log::error('couldn\'t approve waveapps invoice', ['_trace' => $response]);
        return ['success' => false, 'message' => 'couldn\'t approve invoice', 'data' => $response];
    }

    /**
     * send invoice via email to user
     *
     * @return array|string
     */
    public function send($input = [])
    {
        $approved_invoice = $this->approveInvoice();

        if(! isset($approved_invoice['success']) || $approved_invoice['success'] !== true){
            return 'can\'t approve invoice. And unapproved invoice cannot be sent.';
        }

        $input = [
            "invoiceId" => $this->invoice_id,
            "to" => "amritms@vurung.com",
            "subject" => "This is subject of send invoice",
            "message" => "This is message of send invoice",
            "attachPDF" => true
        ];

        $variables = ['input' => $input];
        $response = $this->waveapps->invoiceSend($variables, 'InvoiceSendInput');

        if(isset($response['data']['invoiceSend']['didSucceed']) && $response['data']['invoiceSend']['didSucceed'] == true){
            \Log::debug('Waveapps Invoice sent successfully for user_id:' . $this->user_id);
            request()->session()->flash('message', 'Invoice sent successfully.');

            return ['success' => true, 'data' => $response];
        }

        request()->session()->flash('message', 'Something went wrong, Invoice couldn\'t be sent');
        \Log::error('Something went wrong while sending waveapps invoice for user_id: ' . $this->user_id, ['_trace' => $response]);

        return ['success' => false, 'data' => $response];
    }

    public function delete($new_invoice = [])
    {
        $variables = ['input' => ['invoiceId' => $this->invoice_id]];

        $response = $this->waveapps->invoiceDelete($variables, 'InvoiceDeleteInput');

        if(isset($response['data']['invoiceDelete']['didSucceed']) && $response['data']['invoiceDelete']['didSucceed'] == true){
            \Log::debug('Waveapps Invoice deleted successfully for user_id:' . $this->user_id);
            request()->session()->flash('message', 'Invoice deleted successfully.');
            Cache::put($this->user_id . '_invoice_id', '');

            return ['success' => true, 'data' => $response];
        }

        request()->session()->flash('message', 'Something went wrong, Invoice couldn\'t be deleted');
        \Log::error('Something went wrong while deleting waveapps invoice for user_id: ' . $this->user_id, ['_trace' => $response]);

        return ['success' => false, 'data' => $response];
    }

    public function createAndSend($input = [])
    {

    }
    public function update($input = [])
    {
        // TODO: Implement update() method.
    }

    private function populateConfigFromDb()
    {
        $config = InvoiceGatewayModel::where('user_id', $this->user_id)->select('config')->firstOrFail();

        $this->businessId = $config['config']['businessId'] ?? '';
        $this->refresh_token = $config['config']['refresh_token'] ?? '';

        config(['invoice-gateways.waveapps.businessId' => $this->businessId]);
        config(['invoice-gateways.waveapps.refresh_token' => $this->refresh_token]);
    }
}

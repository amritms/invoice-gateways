<?php

namespace Amritms\InvoiceGateways\Repositories;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\Http;
use Amritms\InvoiceGateways\Contracts\Invoice as InvoiceContract;

class WaveController extends InvoiceContract
{
    public $client_id = '5SzhyRyqeRL49.pdc9.gNQM1JJK_i6pgOXcu6yUP';
    public $client_secret = 'wN9EaWzQ7cz05xFzdWvJAlCXMDOWyfFN5En4o5KW3eDOQGTFUL4aQX92R8YPam5escn3wAs6np5wcgSYmP4Nc1eVW9kH2DlgobJ543IdCqwq3tSqLxBUH0VXHmp7IHZq';
    public $state;
    public $redirect_uri = '';

    protected $graphql_url;
    protected $user_id = 9;
    // customer's business id
    public $business_id;
    public $auth_token;
    public $refresh_token;
    public $merchant_id;
    public $income_account_id;
    public $invoice_id;
    private $waveapps;

    public function __construct()
    {
        $this->graphql_url = 'https://gql.waveapps.com/graphql/public';
        $this->auth_token = Cache::get($this->user_id . '_auth_token') ?? '';
        $this->business_id = Cache::get($this->user_id . '_business_id') ?? '';
        $this->merchant_id = Cache::get($this->user_id . '_merchant_id') ?? '';
        $this->refresh_token = Cache::get($this->user_id . '_refresh_token') ?? '';
        $this->income_account_id = Cache::get($this->user_id . '_income_account_id') ?? '';
        $this->invoice_id = Cache::get($this->user_id . '_invoice_id') ?? '';
        config(['waveapps.access_token' => $this->auth_token]);
        config(['waveapps.business_id' => $this->business_id]);

        $this->redirect_uri = url('waveapps/redirect-back');
        $this->state = 'csrf_protection_' . $this->user_id;

        $this->waveapps = new \Amritms\WaveappsClientPhp\Waveapps();
    }

    public function index()
    {
        return view('welcome');
    }

    /**
     * 1. Redirect to Wave to request authorization
     * https://developer.waveapps.com/hc/en-us/articles/360019493652
     */
    public function getAccessToken()
    {
        $url = 'https://api.waveapps.com/oauth2/authorize?' .
            'client_id='. $this->client_id.
            '&response_type=code'.
            '&scope=account:* business:read customer:* invoice:* product:* user:read'.
//            '&scope=basic user.read user.write business.read business.write account.read account.write invoice.read invoice.write'.
            '&state='. $this->state;

        return redirect($url);
    }

    /**
     * 2. User redirected back to your site by Wave
     * code - The server returns the authorization code in the query string. May only be exchanged * once and expire 10 minutes after issuance.
     * state - The server returns the same state value that you passed (if you provided one). If the states don't match, the request may have been created by a third party and you should abort the process.
     */
    public function redirectBack()
    {
        if(! request('code')){
            \Log::error('something went wrong, waveapps didn\'t return code', ['_trace' => request()->json()]);

            return 'something went wrong, waveapps didn\'t return code.';
        }

        $this->code = request('code');

        // 2.1 Exchange auth code for tokens
        // Your application should POST to: https://api.waveapps.com/oauth2/token/
        $response = HTTP::asForm()->post('https://api.waveapps.com/oauth2/token/', [
           'client_id' => $this->client_id,
           'client_secret' => $this->client_secret,
           'code' => $this->code,
           'grant_type' => 'authorization_code',
           'redirect_uri' => $this->redirect_uri,
        ]);

        if( $response->status() != 200){
            \Log::error('something went wrong, could\'t verify application', ['_trace' => request()->json()]);

            return 'something went wrong, could\'t verify application';
        }

        $response = $response->json();

        Cache::forever($this->user_id . '_auth_token', $response['access_token']);
        Cache::forever($this->user_id . '_refresh_token', $response['refresh_token']);
        Cache::forever($this->user_id . '_business_id', $response['businessId']);
        Cache::forever($this->user_id . '_user_id', $response['userId']);

        \Log::debug('Application verified successfully for user::' . $this->user_id, ['_trace' => $response]);
        request()->session()->flash('message', 'Application verified successfully, you can proceed with wave invoice creation.');
        return redirect('/');
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
            "businessId" => $this->business_id,
            "page" => 1,
            "pageSize" => 20
        ];

        $response = $this->waveapps->invoices($variables);
        if(isset($response['errors'])){
            \Log::error('couldn\'t fetch all invoices for user_id:' . $this->user_id, ['_trace' => $response]);
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
    public function createInvoice($input)
    {
        $product_id = $input['product_id'];
        $customer_id = $input['customer_id'];

        $input = [
            "businessId" => $this->business_id,
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
            \Log::debug('invoice created successfully for user_id: ' . $this->user_id, ['_trace' => $response]);

            return ['success' => true, 'message' => 'Invoice created successfully', 'data' => $response['data']['invoiceCreate']['invoice']];
        }

        \Log::error('couldn\'t create invoice. user_id:' . $this->user_id, ['data' => $response]);

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
            "businessId" => $this->business_id
        ];

        $response = $this->waveapps->customers($variables);

        if(isset($response['errors'][0]['extensions']['code']) && $response['errors'][0]['extensions']['code'] ==  "UNAUTHENTICATED"){
            \Log::debug('Couldn\'t list customers for user_id:' . $this->user_id, ['_trace' => $response]);

            return ['success' => false, 'message' => 'UNAUTHENTICATED', 'data' => $response];
        }

        if(isset($response['data']['business']['customers']) && $response['data']['business']['customers'] !== null){
            request()->session()->flash('message', 'product created successfully.');
            \Log::debug('Customer listed successfully for user_id:' . $this->user_id, ['_trace' => $response]);

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
            "businessId" => $this->business_id,
            'page'=> 1,
            'pageSize' => 50
        ];

        $response = $this->waveapps->products($variables);

        if(isset($response['errors'][0]['extensions']['code']) && $response['errors'][0]['extensions']['code'] ==  "UNAUTHENTICATED"){
            \Log::debug('Couldn\'t list products for user_id:' . $this->user_id, ['_trace' => $response]);

            return ['success' => false, 'message' => 'UNAUTHENTICATED', 'data' => $response];
        }

        if(isset($response['data']['business']['products']) && $response['data']['business']['products'] !== null){
            request()->session()->flash('message', 'product created successfully.');
            \Log::debug('Customer listed successfully for user_id:' . $this->user_id, ['_trace' => $response]);

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
                "businessId" => $this->business_id,
                'page'=> 1,
                'pageSize' => 500
            ]
        ];
        $response = HTTP::withHeaders([
            'Authorization' => 'Bearer ' . $this->auth_token
        ])->post($url, $post_data);

        if($response->status() !== 200){
            \Log::error('something went wrong. couldn\'t fetch account ID  for user_id: ' . $this->user_id, ['_trace' => $response->json()]);
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

        return ['success' => true, 'data' => $return_result];
    }

    /**
     * create customer from contact of job
     * @param array $customer['full_name' => '', 'first_name' => '', 'last_name' => '', 'email' => '', 'address' => ['city' => '', 'postalCode' => '', 'country_code' => '', 'zone_code' => '']]
     * @return array|bool
     */
    public function createCustomer($customer = [])
    {
        $currency_code = 'USD';
        $address = [];
        if(isset($customer['address']['city'])) $address['city'] = $customer['address']['city'];
        if(isset($customer['address']['postalCode'])) $address['postalCode'] = $customer['address']['postalCode'];
        if(isset($customer['address']['countryCode'])) $address['countryCode'] = $customer['address']['countryCode'];
        if(isset($customer['address']['country_code']) && isset($customer['address']['zone_code'])) $address['provinceCode'] = $customer['address']['country_code'] . '-' . $customer['address']['zone_code'];

        $full_name = isset($customer['full_name']) ? $customer['full_name'] : 'Full Name1';
        $first_name = isset($customer['full_name']) ? $customer['full_name'] : 'First Name1';
        $last_name = isset($customer['last_name']) ? $customer['last_name'] : 'Last Name1';
        $email = isset($customer['email']) ? $customer['email'] : 'test1@test.com';

        $currency_code = 'USD';

        $variables = ['input' => [
            "businessId" => $this->business_id,
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
            \Log::debug('Customer created successfully for user_id:' . $this->user_id, ['_trace' => $response]);

            return ['id' => $response['data']['customerCreate']['customer']['id'], 'name' => $response['data']['customerCreate']['customer']['name']];
        }

            \Log::error('Couldn\'t create customer for creating invoice for user_id:' . $this->user_id, ['_trace' => $response]);

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
                "businessId" => $this->business_id,
                "name" => $product['name'],
                "unitPrice" => $product['unitPrice'],
                "incomeAccountId" => $this->income_account_id,
            ]
        ];


        $response = $this->waveapps->productCreate($variables, 'ProductCreateInput');

        if(isset($response['errors'])){
            \Log::error('couldn\'t create product for user_id: ' . $this->user_id, ['_trace' => $response]);

            return response()->json(['success' => false, 'data' => $response])->status(400);
        }

        \Log::debug('Product created successfully for user_id:' . $this->user_id, ['_trace' => $response]);

        return ['id' => $response['data']['productCreate']['product']['id'], 'name' => $response['data']['productCreate']['product']['name']];
    }

    /**
     * Expired tokens and refreshing
     * Your application should POST to: https://api.waveapps.com/oauth2/token/
     */
    public function refreshToken()
    {
        $response = HTTP::asForm()->post('https://api.waveapps.com/oauth2/token/', [
            'client_id' => $this->client_id,
            'client_secret' => $this->client_secret,
            'refresh_token' => $this->refresh_token,
            'grant_type' => 'refresh_token',
            'redirect_uri' => $this->redirect_uri,
         ]);

        // refresh token is stored indefinitely, if waveaps returns unauthorized(401) status code, then redirect user to get access token.
        if($response->status() == 401){
            return redirect('/waveapps/get-access-token');
        }

        $response = $response->json();

        Cache::forever($this->user_id . '_auth_token', $response['token_type'] . ' ' . $response['access_token']);
        Cache::forever($this->user_id . '_refresh_token', $response['refresh_token']);

        request()->session()->flash('message', 'refresh token fetched successfully.');
        \Log::debug('Token refreshed successfully for user_id:' . $this->user_id);

        return redirect()->back();
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
            \Log::debug('Invoice approved successfully for user_id:' . $this->user_id);

            return ['success' => true, 'message' => 'Invoice successfully approved.', 'data' => $response];
        }

        \Log::error('couldn\'t approve invoice', ['_trace' => $response]);
        return ['success' => false, 'message' => 'couldn\'t approve invoice', 'data' => $response];
    }

    /**
     * send invoice via email to user
     *
     * @return array|string
     */
    public function sendInvoice()
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
            \Log::debug('Invoice sent successfully for user_id:' . $this->user_id);
            request()->session()->flash('message', 'Invoice sent successfully.');

            return ['success' => true, 'data' => $response];
        }

        request()->session()->flash('message', 'Something went wrong, Invoice couldn\'t be sent');
        \Log::error('Something went wrong while sending invoice for user_id: ' . $this->user_id, ['_trace' => $response]);

        return ['success' => false, 'data' => $response];
    }

    public function deleteInvoice($new_invoice = [])
    {
        $variables = ['input' => ['invoiceId' => $this->invoice_id]];

        $response = $this->waveapps->invoiceDelete($variables, 'InvoiceDeleteInput');

        if(isset($response['data']['invoiceDelete']['didSucceed']) && $response['data']['invoiceDelete']['didSucceed'] == true){
            \Log::debug('Invoice deleted successfully for user_id:' . $this->user_id);
            request()->session()->flash('message', 'Invoice deleted successfully.');
            Cache::put($this->user_id . '_invoice_id', '');

            return ['success' => true, 'data' => $response];
        }

        request()->session()->flash('message', 'Something went wrong, Invoice couldn\'t be deleted');
        \Log::error('Something went wrong while deleting invoice for user_id: ' . $this->user_id, ['_trace' => $response]);

        return ['success' => false, 'data' => $response];
    }
}

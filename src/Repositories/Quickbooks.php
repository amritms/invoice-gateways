<?php

namespace Amritms\InvoiceGateways\Repositories;
use Carbon\Carbon;
use Braintree\PaymentMethod;
use Illuminate\Support\Facades\Http;
use QuickBooksOnline\API\Facades\Item;
use QuickBooksOnline\API\Facades\Account;
use QuickBooksOnline\API\Facades\Invoice;
use QuickBooksOnline\API\Facades\Customer;
use Amritms\InvoiceGateways\Models\Contact;
use QuickBooksOnline\API\DataService\DataService;
use Amritms\InvoiceGateways\Exceptions\FailedException;
use Amritms\InvoiceGateways\Exceptions\InvalidConfiguration;
use Amritms\InvoiceGateways\Repositories\AuthorizeQuickbooks;
use Amritms\InvoiceGateways\Exceptions\UnauthenticatedException;
use Amritms\InvoiceGateways\Contracts\Invoice as InvoiceContract;
use Amritms\InvoiceGateways\Models\InvoiceGateway as InvoiceGatewayModel;

class Quickbooks implements InvoiceContract {

    protected $dataService;
    protected $user_id;
    protected $config;
    private $user_invoice_config;
    protected $base_url;

    public function __construct(array $config = []) {
        $this->config = $config;
        $this->base_url = $config['base_url'];

        if (empty($config['client_id'])) {
            throw InvalidConfiguration::ClientIdNotSpecified();
        }

        $this->client_secret = $config['client_secret'];
        if (empty($this->client_secret)) {
            throw InvalidConfiguration::ClientSecretNotSpecified();
        }

        $invoice_config = InvoiceGatewayModel::whereUserId(auth()->id())->first();
        $expiration_time = \Carbon\Carbon::parse($invoice_config->config['expires_in'])->subMinutes(2);
        
        if((! empty($invoice_config->config['expires_in']) && now()->greaterThanOrEqualTo($expiration_time))) {
            (new AuthorizeQuickbooks($config))->refreshToken($config['refresh_token']);
        }

        $this->dataService = DataService::configure([
            'auth_mode' => 'oauth2',
            'ClientID' => $config['client_id'],
            'ClientSecret' =>  $config['client_secret'],
            'accessTokenKey'  => $invoice_config->config['access_token'],
            'refreshTokenKey' => $invoice_config->config['refresh_token'],
            'QBORealmID'      => $invoice_config->config['businessId'],
            'baseUrl'         => $config['mode']
        ]);
        $this->user_id = auth()->id();
    }
     /**
     * Create invoice and send.
     */
    public function create($input = []) {
        $variables = [
            'Line' => [
                        [
                            "Amount" => $input['price'],
                            "Description" => $input['description'],
                            'DetailType' => "SalesItemLineDetail",
                            "SalesItemLineDetail" => [
                                "ItemRef" => [
                                "value" => $input['product_id'],
                                ],
                                "UnitPrice" => $input['price'],
                                'Qty' => 1,
                                'ServiceDate' => $input['job']['jobdate']
                            ]
                        ]
                      ],
            
            'CustomerRef' => [
                'value' => $input['customer_id'],
            ],
            'BillEmail' => [
                'Address' => $input['billing_address']
            ]
            ];

        if(isset($input['invoice_number'])) {
            $variables['DocNumber'] = $input['invoice_number'];

        }
        $invoice = Invoice::create($variables);
        $resultingInvoice = $this->dataService->Add($invoice);
        if(!$resultingInvoice) {
            return $this->create($input);
        }
        $error = $this->dataService->getLastError();

        if($error) {
            \Log::error('failed to create invoice for user:'.$this->user_id,['_trace'=>$error]);
            if($error->getHttpStatusCode() == 401) {
                (new AuthorizeQuickbooks(config('invoice-gateways.quickbooks')))->refreshToken();
                throw UnauthenticatedException::forInvoiceCreate();
            }

            throw FailedException::forInvoiceCreate();
        }
        \Log::info('Invoice created successfully for user:'.$this->user_id);
        $invoice_config = $this->populateConfigFromDb();
        $pdf_url = $this->base_url.'/v3/company/'.$invoice_config['businessId'].'/invoice/'.$resultingInvoice->Id.'/pdf';

        return  [
            'success' => true,
            'data' => [
                'invoiceNumber'=> $resultingInvoice->DocNumber,
                'id' => $resultingInvoice->Id,
                'viewUrl' => '',
                'pdfUrl' => $pdf_url,
                'status' => $this->getInvoiceStatus($resultingInvoice->EmailStatus)
            ]
        ];
    }

    /**
     * Create invoice and save it in draft.
     */
    public function createAndSend($input = []) {

    }

    /**
     * Update draft invoice.
     */
    public function update($input = []){

    }

    /**
     * Send invoice to the customer via email.
     */
    public function send($input = []){

        $invoice = $this->dataService->FindById('Invoice',$input['invoice_id']);
        $error = $this->dataService->getLastError();
        if($error) {
            \Log::error('failed to send invoice for user_id:'.$this->user_id,["__trace" => $error]);
            (new AuthorizeQuickbooks(config('invoice-gateways.quickbooks')))->refreshToken();
            request()->session()->flash('message', 'Something went wrong, Invoice couldn\'t be sent. Try again');
            throw FailedException::forInvoiceSend();
        }

        $this->dataService->SendEmail($invoice);
        \Log::info('Quickbooks Invoice sent successfully for user_id:' . $this->user_id);
        request()->session()->flash('message', 'Invoice sent successfully.');

        return response(['success' => true], 200);
    }

    /**
     * Delete invoice
     */
    public function delete($input = []){
        $invoice = $this->dataService->FindById('Invoice',$input['invoice_id']);
        $error = $this->dataService->getLastError();

        if($error) {
            if($error->getHttpStatusCode() == 401) {
                (new AuthorizeQuickbooks(config('invoice-gateways.quickbooks')))->refreshToken();
                throw UnauthenticatedException::forInvoiceDelete();
            }
            throw FailedException::forInvoiceDelete();
        }
        $invoice_config = $this->populateConfigFromDb();        
        $variables= [
            'Id'=>$invoice->Id,
            'SyncToken' => $invoice->SyncToken,
            'Line' => [
                            [
                                "Amount" => $input['price'],
                                'DetailType' => "SalesItemLineDetail",
                                "SalesItemLineDetail" => [
                                    "ItemRef" => [
                                    "value" => $invoice->Line[0]->SalesItemLineDetail->ItemRef
                    
                                    ]
                                ]
                            ]
                        ],
                    ];

        $response = Http::withToken($invoice_config['access_token'])->post($this->base_url.'/v3/company/'.$invoice_config['businessId'].'/invoice?operation=delete',$variables);
        if($response->failed()) {
            \Log::error('failed to delete invoice for user_id:'.$this->user_id,["__trace" => $error]);
            throw FailedException::forInvoiceDelete();
        }

        \Log::info('Quickbooks Invoice deleted successfully for user_id:' . $this->user_id);
        request()->session()->flash('message', 'Invoice deleted successfully.');

        return ['success' => true];
    }

    public function createCustomer($input = []){
        $customer = Customer::create($input);    
        $invoice_config = InvoiceGatewayModel::whereUserId(auth()->id())->first();
        $access_token_expiration_time = Carbon::parse($invoice_config->config['expires_in'])->subSeconds(60);
        $now = Carbon::now();

        $resultingCustomer = $this->dataService->Add($customer);
 
        $error = $this->dataService->getLastError();
        if($error) {
            if($error->getHttpStatusCode() == 401) {
                (new AuthorizeQuickbooks(config('invoice-gateways.quickbooks')))->refreshToken();
            }
            throw FailedException::forInvoiceCreate();
        }

        return [
            'id' => $resultingCustomer->Id
        ];
    }

    public function createProduct($input = [], $invoice_number) {
        $account_id = $invoice_config['incomeAccountId'] ?? '';
        if(empty($account_id)) {
            $account_id = $this->getAccountId();
        }

        $variables = [
            'Name' => $input['description'],
            'Type' => 'Service',
            'UnitPrice' => $input['amount'] ?? 1,
            'IncomeAccountRef' => [
                'value' => $account_id
            ]
        ];

        $item = Item::create($variables);
        $itemObj = $this->dataService->Add($item);
        $error = $this->dataService->getLastError();

        if($error) {
            $message = null;
            \Log::info($error->getHttpStatusCode());
            \Log::error('failed to create product for user_id:' . $this->user_id, ['_trace' => $error->getResponseBody()]);
            \Log::info(['message'=>$message,'input_message'=>$input['message']]);
            if($error->getHttpStatusCode() == 401) {
                (new AuthorizeQuickbooks(config('invoice-gateways.quickbooks')))->refreshToken();
                throw UnauthenticatedException::forInvoiceCreate();
            }
            
            elseif($error->getHttpStatusCode() == 400) {
                $message = $input['message'] ??'QuickBooks (QB) requires a unique job name for every new invoice created via VOICEOVERVIEW. To continue from VOV, please update the Job Title for this invoice. Or you can create a new invoice via QB by selecting the line item already created.';
            }
            
            throw FailedException::forProductCreate($message,422);
        }

        return [
            'id' => $itemObj->Id
        ];
    }


    private function getAccountId() {
        $invoice_config = InvoiceGatewayModel::whereUserId(auth()->id())->first();
        $user_invoice_config = $invoice_config->config;
        
        if((!empty($user_invoice_config['incomeAccountId']))) {

            return $user_invoice_config['incomeAccountId'];
        }
        
        // check if account with name =   'service' and accont type  = 'Income'  exists
        $url = $this->base_url.'/v3/company/'.$invoice_config->config['businessId']."/query?query=SELECT * from Account WHERE Name = 'Services' AND AccountType = 'Income'&minorversion=55 ";
        $response = Http::withToken($invoice_config->config['access_token'])->get($url);

        if($response->failed()) {
            \Log::error('failed to get account id for user_id:'.$this->user_id,["__trace" => $response->json()]);
            if($response->status() == 401) {
               (new AuthorizeQuickbooks(config('invoice-gateways.quickbooks')))->refreshToken();
               throw UnauthenticatedException::forInvoiceCreate();
           }
        }

        if($response->ok()) {
            \Log::info('account id retrived successfully for user_id:'.$this->user_id);
            $xml = simplexml_load_string($response->body());
            $json = json_encode($xml);
            $array = json_decode($json,TRUE);            
            if(!empty($array['QueryResponse'])){
                $user_invoice_config['incomeAccountId'] = $array['QueryResponse']['Account']['Id'];
                $invoice_config->update(['config' => $user_invoice_config ]);

                return $array['QueryResponse']['Account']['Id'];
            }
        }

        $accountResource = Account::create([
            'Name' => 'Services',
            'AccountType' => 'Income'
        ]);
        $accountObj  = $this->dataService->Add($accountResource);
        $error = $this->dataService->getLastError();

        if($error) {
            \Log::error('failed to get account id for user_id:'.$this->user_id,["__trace" => $error]);
            if($error->getHttpStatusCode() == 401) {
                (new AuthorizeQuickbooks(config('invoice-gateways.quickbooks')))->refreshToken();
                throw UnauthenticatedException::forInvoiceCreate();
            }
            throw FailedException::forProductCreate();
        }
        else {
            \Log::info('account id retrived successfully for user_id:'.$this->user_id);
            $new_config = array_merge($invoice_config->config,['incomeAccountId'=> $accountObj->Id]);
            $invoice_config->config = $new_config;
            $invoice_config->save();

            return $accountObj->Id ;
        }
    }

    public function syncCustomers() {
        try {
            $customers = collect($this->allCustomers());
            $emails = $customers->map(function($arr) {
                return $arr->PrimaryEmailAddr->Address ?? '';
            })->filter(function($email) {
                return !!$email;
            })->all();
            $customers_email_id_key_pair = $customers->mapWithKeys(function ($customer) {
                if(!$customer->PrimaryEmailAddr) {
                    return [];
                }
                return [$customer->PrimaryEmailAddr->Address => $customer->Id ];
            });

            $contacts = Contact::whereIn('email', $emails)->where('user_id', \Auth::id())->get();
            \Log::debug('quickbooks customer import start for user_id:' . $this->user_id);
            $contacts->map(function ($contact) use ($customers_email_id_key_pair) {
                if(isset($customers_email_id_key_pair[$contact->email])){
                    $contact->customer_id = $customers_email_id_key_pair[$contact->email];
                    $contact->save();
                    \Log::debug($contact->email);
                }

                return true;
            });
            (new InvoiceGatewayModel)->where(['user_id' => \Auth::id()])->update(['contact_sync_at' => now()]);
            \Log::debug('quickbooks customer import completed for user_id:' . $this->user_id);
    
            return ['success' => true, 'data' => $contacts];
            
        } catch (\Throwable $th) {

            return $th;
        }
        


    }

    public function allCustomers() {
        $i = 1;
        $allCustomers = $this->dataService->FindAll('Customer', $i, 500);
        $error = $this->dataService->getLastError();

        if($error) {
            if($error->getHttpStatusCode() == 401) {
                (new AuthorizeQuickbooks(config('invoice-gateways.quickbooks')))->refreshToken();
                throw UnauthenticatedException::forCustomerAll();
            }
            \Log::info($error->getResponseBody());

            throw FailedException::forCustomerAll();
        }

        return $allCustomers;
    }

    private function getInvoiceStatus($status) {
        switch ($status) {
            case 'EmailSent':
                return 'SENT';
            
            default:
                return 'SAVED';
                
        }
    }

    public function downloadInvoice($invoice_id) {
        $invoice = $this->dataService->FindById('Invoice',$invoice_id);
        $error = $this->dataService->getLastError();

        if($error) {
            \Log::error('Failed to download invoice for user_id:'.$this->user_id,['__trace' => $error]);
            if($error->getHttpStatusCode() == 401) {
                (new AuthorizeQuickbooks(config('invoice-gateways.quickbooks')))->refreshToken();
                throw UnauthenticatedException::forInvoiceDownload();
            }
            throw FailedException::forInvoiceDownload();
        }

        $result = $this->dataService->DownloadPDF($invoice);
        \Log::info('account id retrived successfully for user_id:'.$this->user_id);
        return $result;
    }

    private function populateConfigFromDb()
    {
        $config = InvoiceGatewayModel::where('user_id', $this->user_id)->select('config')->firstOrFail();

        $this->businessId      = $config['config']['businessId'] ?? null;
        $this->refresh_token   = $config['config']['refresh_token'] ?? null;
        $this->incomeAccountId = $config['config']['incomeAccountId'] ?? null;
        $this->access_token    = $config['config']['access_token'] ?? null;
        $this->expires_in      = $config['config']['expires_in'] ?? null;

        config(['invoice-gateways.quickbooks.businessId' => $this->businessId]);
        config(['invoice-gateways.quickbooks.refresh_token' => $this->refresh_token]);
        config(['invoice-gateways.quickbooks.incomeAccountId' => $this->incomeAccountId]);
        config(['invoice-gateways.quickbooks.access_token' => $this->access_token]);
        config(['invoice-gateways.quickbooks.expires_in' => $this->expires_in]);

        return $config->config;
    }

    public function getItems() {
        $invoice_config = $this->populateConfigFromDb();
        $url = $this->base_url.'/v3/company/'.$invoice_config['businessId']."/query?query=SELECT * from Item&minorversion=55 ";
        $response = Http::withToken($invoice_config['access_token'])->get($url);

        if($response->failed()) {
            \Log::error('failed to get items for user_id:'.$this->user_id,["__trace" => $response->json()]);
            if($response->status() == 401) {
               (new AuthorizeQuickbooks(config('invoice-gateways.quickbooks')))->refreshToken();
               $this->getItems();
           }
        }

        if($response->ok()) {
            \Log::info('quickbooks items/products/services retrived successfully for user_id:'.$this->user_id);
            $xml = simplexml_load_string($response->body());
            $json = json_encode($xml);
            $array = json_decode($json,TRUE);            
            if(!empty($array['QueryResponse'])){
                return $array['QueryResponse']['Item'];
            }
        }

    }

    public function getProductDetail($item_id) {

    }
}

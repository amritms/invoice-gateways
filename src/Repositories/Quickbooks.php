<?php

namespace Amritms\InvoiceGateways\Repositories;

use Carbon\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use QuickBooksOnline\API\Facades\Item;
use QuickBooksOnline\API\Facades\Account;
use QuickBooksOnline\API\Facades\Invoice;
use QuickBooksOnline\API\Facades\Payment;
use QuickBooksOnline\API\Facades\Customer;
use Amritms\InvoiceGateways\Models\Contact;
use QuickBooksOnline\API\DataService\DataService;
use Amritms\InvoiceGateways\Exceptions\FailedException;
use Amritms\InvoiceGateways\Exceptions\InvalidConfiguration;
use Amritms\InvoiceGateways\Repositories\AuthorizeQuickbooks;
use Amritms\InvoiceGateways\Exceptions\UnauthenticatedException;
use Amritms\InvoiceGateways\Contracts\Invoice as InvoiceContract;
use Amritms\InvoiceGateways\Models\InvoiceGateway as InvoiceGatewayModel;
use Illuminate\Support\Facades\Storage;

class Quickbooks implements InvoiceContract
{

    protected DataService $dataService;
    protected $user_id;
    protected $config;
    protected $base_url;
    protected string $client_secret;
    protected string $businessId;
    protected string $refresh_token;
    protected string $access_token;
    protected $expires_in;
    protected $incomeAccountId;

    const API_VERSION = 75;

    public function __construct(array $config = [])
    {
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

        if ((!empty($invoice_config->config['expires_in']) && now()->greaterThanOrEqualTo($expiration_time))) {
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

        $this->dataService->throwExceptionOnError(true);
        $this->user_id = auth()->id();
    }
    /**
     * Create invoice and send.
     */
    public function create($input = [])
    {

        try {
            $message = $this->getInvoiceMessage();
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
                        ],

                    ]
                ],

                'CustomerRef' => [
                    'value' => $input['customer_id'],
                ],
                'BillEmail' => [
                    'Address' => $input['billing_address']
                ],
                'CustomerMemo' => $message,
                'AllowOnlineCreditCardPayment' => true,
                'AllowOnlineACHPayment' => true
            ];

            if (isset($input['invoice_number'])) {
                $variables['DocNumber'] = $input['invoice_number'];
            }
            $invoice = Invoice::create($variables);
            $this->dataService->Add($invoice);

            $error = $this->dataService->getLastError();
            if ($error) {

                Log::error('failed to create invoice for job:' . $input['job']['id'], ['_trace' => $error]);
                Log::error('failed to create invoice for user:' . $this->user_id, ['_trace' => $error]);
                if ($error->getHttpStatusCode() == 401) {
                    (new AuthorizeQuickbooks(config('invoice-gateways.quickbooks')))->refreshToken();
                    throw UnauthenticatedException::forInvoiceCreate();
                }

                $err = $this->parseXMLToJSON($error->getResponseBody());
                \Log::error(['error' => $error, 'response' => $error->getResponseBody()]);
                throw FailedException::forInvoiceCreate($err['Fault']['Error']['Detail'], 422);
            }
            $invoice_config = $this->populateConfigFromDb();
            // THIS PIECE OF CODE WAS ADDED SINCE CREATE INVOICE DID NOT RETURNED ANY INVOICE ID
            $url =  "{$this->base_url}/v3/company/{$invoice_config['businessId']}/query";
            $query = "SELECT * FROM Invoice ORDERBY MetaData.CreateTime DESC MAXRESULTS 1";
            $response = Http::withToken($invoice_config['access_token'])->withHeaders([
                'Accept' => 'application/json'
            ])->get($url, [
                'query' => $query,
                'minorversion' => self::API_VERSION
            ]);

            if ($response->failed()) {
                \Log::error('Failed to find invoice ID' . $response);
                throw FailedException::forInvoiceCreate('Unable to fetch invoice Id');
            }

            $invoice = $response->json()['QueryResponse']['Invoice'][0];
            Log::info('Invoice created successfully for user:' . $this->user_id);
            $pdf_url = $this->base_url . '/v3/company/' . $invoice_config['businessId'] . '/invoice/' . $invoice['Id'] . '/pdf';
            return  [
                'success' => true,
                'data' => [
                    'invoiceNumber' => $invoice['DocNumber'],
                    'id' => $invoice['Id'],
                    'viewUrl' => '',
                    'pdfUrl' => $pdf_url,
                    'status' => $this->getInvoiceStatus($invoice['EmailStatus'])
                ]
            ];
        } catch (\QuickBooksOnline\API\Exception\ServiceException $err) {
            $parsed = $this->parseQuickBooksError($err->getMessage());
            throw FailedException::forInvoiceCreate($parsed['message'] ?? $err->getMessage(), 422);
        } catch (\Throwable $th) {
            \Log::error($th);
            throw FailedException::forInvoiceCreate("Something went wrong!", 400);
        }
    }
    private function parseQuickBooksError(string $rawMessage): array
    {
        $result = [
            'http_status' => null,
            'fault_type'  => null,
            'error_code'  => null,
            'message'     => null,
            'detail'      => null,
        ];

        // Pull out HTTP response code, e.g. "Response Code:[400]"
        if (preg_match('/Response Code:\s*\[(\d+)\]/', $rawMessage, $m)) {
            $result['http_status'] = (int) $m[1];
        }

        // Pull out the embedded XML: "with body: [<?xml ... </IntuitResponse>]"
        if (preg_match('/with body:\s*\[(<\?xml.*?<\/IntuitResponse>)\]/s', $rawMessage, $m)) {
            $xmlString = html_entity_decode($m[1]);

            libxml_use_internal_errors(true);
            $xml = simplexml_load_string($xmlString);

            if ($xml !== false && isset($xml->Fault)) {
                $fault = $xml->Fault;
                $result['fault_type'] = (string) $fault['type'];

                if (isset($fault->Error)) {
                    $error = $fault->Error;
                    $result['error_code'] = (string) $error['code'];
                    $result['message']    = (string) $error->Message;
                    $result['detail']     = (string) $error->Detail;
                }
            }
        }

        return $result;
    }

    /**
     * Create invoice and save it in draft.
     */
    public function createAndSend($input = []) {}

    /**
     * Update draft invoice.
     */
    public function update($input = []) {}

    /**
     * Send invoice to the customer via email.
     */
    public function send($input = [])
    {

        
        $invoice_config = $this->populateConfigFromDb();
        $url = "{$this->base_url}/v3/company/{$invoice_config['businessId']}/invoice/{$input['invoice_id']}/send";

        $response = Http::withToken($invoice_config['access_token'])->withHeaders([
            'Content-Type' => 'application/octet-stream'
        ])->post($url);

        if($response->failed()) {
            \Log::error(['failed to send invoice' => $response->json()]);
            throw FailedException::forInvoiceSend('Something went wrong! Failed to send invoice.');
        }
        Log::info('Quickbooks Invoice sent successfully for user_id:' . $this->user_id);
        request()->session()->flash('message', 'Invoice sent successfully.');

        return response(['success' => true], 200);
    }

    /**
     * Delete invoice
     */
    public function delete($input = [])
    {
        try {
            // $invoice = $this->dataService->FindById('Invoice', $input['invoice_id']);
            // $error = $this->dataService->getLastError();

            // \Log::info($invoice);
            // if ($error) {
            //     if ($error->getHttpStatusCode() == 401) {
            //         (new AuthorizeQuickbooks(config('invoice-gateways.quickbooks')))->refreshToken();
            //         throw UnauthenticatedException::forInvoiceDelete();
            //     }

            //     throw FailedException::forInvoiceDelete();
            // }


            $invoice_config = $this->populateConfigFromDb();
            $url =  "{$this->base_url}/v3/company/{$invoice_config['businessId']}/query";
            $invoice_id = $input['invoice_id'];
            $query = "SELECT * FROM Invoice WHERE Id = '{$invoice_id}'";
            $response = Http::withToken($invoice_config['access_token'])->withHeaders([
                'Accept' => 'application/json'
            ])->get($url, [
                'query' => $query,
                'minorversion' => self::API_VERSION
            ]);

            if ($response->failed()) {
                if ($response->getHttpStatusCode() == 401) {
                    (new AuthorizeQuickbooks(config('invoice-gateways.quickbooks')))->refreshToken();
                    throw UnauthenticatedException::forInvoiceDelete();
                }

                throw FailedException::forInvoiceDelete();
            }
            $invoice = $response->json()['QueryResponse']['Invoice'][0];
            $variables = [
                'Id' => $invoice['Id'],
                'SyncToken' => $invoice['SyncToken'],
            ];

            $response = Http::withToken($invoice_config['access_token'])
            ->withHeaders([
                'Accept' => 'application/json'
            ])
            ->post($this->base_url . '/v3/company/' . $invoice_config['businessId'] . '/invoice?operation=delete', $variables);
            if ($response->failed()) {

                Log::error('failed to delete invoice for user_id:' . $this->user_id, ["__trace" => $response->body()]);
                throw FailedException::forInvoiceDelete();
            }

            Log::info('Quickbooks Invoice deleted successfully for user_id:' . $this->user_id);
            request()->session()->flash('message', 'Invoice deleted successfully.');

            return ['success' => true];
        } catch (\Throwable $th) {
            \Log::info($th);
            throw $th;
        }
    }


    public function createCustomer($input = [])
    {
        $display_name = $input['DisplayName'];

        $vendor_query = "SELECT * from Vendor WHERE DisplayName='{$display_name}'";

        $vendor_query_result = $this->dataService->Query($vendor_query);
        $error = $this->dataService->getLastError();
        if ($error) {
            if ($error->getHttpStatusCode() == 401) {
                (new AuthorizeQuickbooks(config('invoice-gateways.quickbooks')))->refreshToken();
            }
            throw FailedException::forInvoiceCreate('Something went wrong when checking for vendor record');
        }

        $employee_query = "SELECT * FROM Employee WHERE DisplayName = '{$display_name}'";
        $employee_query_result = $this->dataService->Query($employee_query);
        $error = $this->dataService->getLastError();
        if ($error) {
            if ($error->getHttpStatusCode() == 401) {
                (new AuthorizeQuickbooks(config('invoice-gateways.quickbooks')))->refreshToken();
            }
            throw FailedException::forInvoiceCreate('Something went wrong when checking for employee record');
        }
        if (!empty($employee_query_result) && is_array($employee_query_result)) {
            $customer_query = "SELECT * FROM Customer WHERE DisplayName = '{$display_name}'";
            $customer_query_result = $this->dataService->Query($customer_query);

            if (!empty($customer_query_result) && is_array($customer_query_result)) {
                $customer_result = $customer_query_result[0];
                return [
                    'id' => $customer_result->Id,
                ];
            } else {
                $input['DisplayName'] .=  '(C)';
            }
        }

        if (!empty($vendor_query_result) && is_array($vendor_query_result)) {
            $customer_query = "SELECT * FROM Customer WHERE DisplayName = '{$display_name}'";
            $customer_query_result = $this->dataService->Query($customer_query);

            if (!empty($customer_query_result) && is_array($customer_query_result)) {
                $customer_result = $customer_query_result[0];
                return [
                    'id' => $customer_result->Id,
                ];
            } else {
                $input['DisplayName'] .=  '(C)';
            }
        }
        $customer = Customer::create($input);
        $resultingCustomer = $this->dataService->Add($customer);
        $error = $this->dataService->getLastError();
        if ($error) {
            if ($error->getHttpStatusCode() == 401) {
                (new AuthorizeQuickbooks(config('invoice-gateways.quickbooks')))->refreshToken();
            }
            throw FailedException::forInvoiceCreate();
        }

        return [
            'id' => $resultingCustomer->Id
        ];
    }

    public function createProduct($input = [], $invoice_number = null)
    {
        try {
            $invoice_config = $this->populateConfigFromDb();
            $account_id = $invoice_config['incomeAccountId'] ?? '';
            if (empty($account_id)) {
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

            if ($error) {
                $message = null;
                Log::info($error->getHttpStatusCode());
                Log::error('failed to create product for user_id:' . $this->user_id, ['_trace' => $error->getResponseBody()]);
                Log::info(['message' => $message, 'input_message' => $input['message']]);
                if ($error->getHttpStatusCode() == 401) {
                    (new AuthorizeQuickbooks(config('invoice-gateways.quickbooks')))->refreshToken();
                    throw UnauthenticatedException::forInvoiceCreate();
                } elseif ($error->getHttpStatusCode() == 400) {
                    $message = $input['message'] ?? 'QuickBooks (QB) requires a unique job name for every new invoice created via VOICEOVERVIEW. To continue from VOV, please update the Job Title for this invoice. Or you can create a new invoice via QB by selecting the line item already created.';
                }

                throw FailedException::forProductCreate($message, 422);
            }

            return [
                'id' => $itemObj->Id
            ];
        } catch (\QuickBooksOnline\API\Exception\ServiceException $err) {
            $parsed = $this->parseQuickBooksError($err->getMessage());
            throw FailedException::forInvoiceCreate($parsed['message'] ?? $err->getMessage(), 422);
        } catch (\Throwable $th) {
            \Log::debug(['file' => __FILE__, 'line' => __LINE__]);
            \Log::error($th);
            throw FailedException::forInvoiceCreate("Something went wrong!", 400);
        }
    }


    private function getAccountId()
    {
        $invoice_config = InvoiceGatewayModel::whereUserId(auth()->id())->first();
        $user_invoice_config = $invoice_config->config;

        if ((!empty($user_invoice_config['incomeAccountId']))) {

            return $user_invoice_config['incomeAccountId'];
        }

        // check if account with name =   'service' and accont type  = 'Income'  exists
        $url =  "{$this->base_url}/v3/company/{$invoice_config->config['businessId']}/query";
        $query = "SELECT * from Account WHERE Name = 'Services' AND AccountType = 'Income'";
        $response = Http::withToken($invoice_config->config['access_token'])->withHeaders([
            'Accept' => 'application/json'
        ])->get($url, [
            'query' => $query,
            'minorversion' => self::API_VERSION
        ]);

        if ($response->failed()) {
            Log::error('failed to get account id for user_id:' . $this->user_id, ["__trace" => $response->json()]);
            if ($response->status() == 401) {
                (new AuthorizeQuickbooks(config('invoice-gateways.quickbooks')))->refreshToken();
                throw UnauthenticatedException::forInvoiceCreate();
            }

            throw FailedException::forAccountId();
        }
        
        if ($response->ok()) {
            Log::info('account id retrived successfully for user_id:' . $this->user_id);
            $result = $response->json();
            if (!empty($result['QueryResponse'])) {
                $user_invoice_config['incomeAccountId'] = $result['QueryResponse']['Account'][0]['Id'];
                $invoice_config->update(['config' => $user_invoice_config]);
                
                return $result['QueryResponse']['Account'][0]['Id'];
            }
        }

        $accountResource = Account::create([
            'Name' => 'Services',
            'AccountType' => 'Income'
        ]);
        $accountObj  = $this->dataService->Add($accountResource);
        $error = $this->dataService->getLastError();

        if ($error) {
            Log::error('failed to get account id for user_id:' . $this->user_id, ["__trace" => $error]);
            if ($error->getHttpStatusCode() == 401) {
                (new AuthorizeQuickbooks(config('invoice-gateways.quickbooks')))->refreshToken();
                throw UnauthenticatedException::forInvoiceCreate();
            }
            throw FailedException::forProductCreate();
        } else {
            Log::info('account id retrived successfully for user_id:' . $this->user_id);
            $new_config = array_merge($invoice_config->config, ['incomeAccountId' => $accountObj->Id]);
            $invoice_config->config = $new_config;
            $invoice_config->save();

            return $accountObj->Id;
        }
    }

    public function syncCustomers()
    {
        try {
            $customers = collect($this->allCustomers());
            $emails = $customers->map(function ($arr) {
                return $arr->PrimaryEmailAddr->Address ?? '';
            })->filter(function ($email) {
                return !!$email;
            })->all();
            $customers_email_id_key_pair = $customers->mapWithKeys(function ($customer) {
                if (!$customer->PrimaryEmailAddr) {
                    return [];
                }
                return [$customer->PrimaryEmailAddr->Address => $customer->Id];
            });

            $contacts = Contact::whereIn('email', $emails)->where('user_id', Auth::id())->get();
            Log::debug('quickbooks customer import start for user_id:' . $this->user_id);
            $contacts->map(function ($contact) use ($customers_email_id_key_pair) {
                if (isset($customers_email_id_key_pair[$contact->email])) {
                    $contact->customer_id = $customers_email_id_key_pair[$contact->email];
                    $contact->save();
                    Log::debug($contact->email);
                }

                return true;
            });
            (new InvoiceGatewayModel)->where(['user_id' => Auth::id()])->update(['contact_sync_at' => now()]);
            Log::debug('quickbooks customer import completed for user_id:' . $this->user_id);

            return ['success' => true, 'data' => $contacts];
        } catch (\Throwable $th) {

            return $th;
        }
    }

    public function allCustomers()
    {
        $i = 1;

        try {
            $allCustomers = $this->dataService->FindAll('Customer', $i, 200);
            $error = $this->dataService->getLastError();

            if ($error) {
                if ($error->getHttpStatusCode() == 401) {
                    (new AuthorizeQuickbooks(config('invoice-gateways.quickbooks')))->refreshToken();
                    throw UnauthenticatedException::forCustomerAll();
                }
                Log::info($error->getResponseBody());

                throw FailedException::forCustomerAll();
            }

            return $allCustomers;
        } catch (\Throwable $th) {
            throw FailedException::forCustomerAll();
        }
    }

    private function getInvoiceStatus($status)
    {
        switch ($status) {
            case 'EmailSent':
                return 'SENT';

            default:
                return 'SAVED';
        }
    }

    private function findInvoice($invoice_id) {
            $invoice_config = $this->populateConfigFromDb();
            // THIS PIECE OF CODE WAS ADDED SINCE CREATE INVOICE DID NOT RETURNED ANY INVOICE ID
            $url =  "{$this->base_url}/v3/company/{$invoice_config['businessId']}/query";
            $query = "SELECT * FROM Invoice WHERE id= '{$invoice_id}'";
            $response = Http::withToken($invoice_config['access_token'])->withHeaders([
                'Accept' => 'application/json'
            ])->get($url, [
                'query' => $query,
                'minorversion' => self::API_VERSION
            ]);
              if ($response->failed()) {
                \Log::error('Failed to find invoice ID' . $response);
                throw FailedException::forInvoiceCreate('Unable to fetch invoice Id');
            }
            $invoice = $response->json()['QueryResponse']['Invoice'][0];
            $invoice['ID'] = $invoice['Id'];
            return ($invoice);
    }

    public function downloadInvoice($invoice_id)
    {
        $invoice_config = $this->populateConfigFromDb();
        $url =  "{$this->base_url}/v3/company/{$invoice_config['businessId']}/invoice/{$invoice_id}/pdf";
        $response = Http::withToken($invoice_config['access_token'])->withHeaders([
            'Content-Type' => 'application/pdf'
        ])->get($url, [
            'minorversion' => 75
        ]);
        Log::info('account id retrived successfully for user_id:' . $this->user_id);
        if($response->failed()) {
            $message = $this->parseQuickBooksError($response->body());
            throw FailedException::forInvoiceDownload($message['message']);
        }

        return $response->body();
        
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

    public function getItems($page_limit = 20)
    {
        $invoice_config = $this->populateConfigFromDb();
        $url = $this->base_url . '/v3/company/' . $invoice_config['businessId'] . "/query?query=SELECT * from Item where type = 'Service' maxresults {$page_limit} ";
        $url = "{$this->base_url}/v3/company/{$invoice_config['businessId']}/query";
        $query = "SELECT * from Item where type = 'Service' maxresults {$page_limit}";
        $response = Http::withToken($invoice_config['access_token'])->withHeaders([
            'Accept' => 'application/json',
        ])->get($url, [
            'query' => $query,
            'minorversion' => self::API_VERSION
        ]);

        if ($response->failed()) {
            Log::error('failed to get items for user_id:' . $this->user_id, ["__trace" => $response->json()]);
            if ($response->status() == 401) {
                (new AuthorizeQuickbooks(config('invoice-gateways.quickbooks')))->refreshToken();
                $this->getItems();
            }
        }

        if ($response->ok()) {
            Log::info('quickbooks items/products/services retrived successfully for user_id:' . $this->user_id);
            $results = $response->json();

            return array_map(function ($product) {
                $product['id'] = $product['Id'];
                $product['name'] = $product['Name'];
                return $product;
            }, $results['QueryResponse']['Item']);
        }
    }

    public function getProductDetail($item_id) {}

    public function createPayment($totalAmt, $customerRef)
    {

        $QBPayment = Payment::create([
            'TotalAmt' => $totalAmt,
            "CustomerRef" => [
                "value" => $customerRef
            ]
        ]);

        $payment = $this->dataService->Add($QBPayment);

        $error = $this->dataService->getLastError();
        if ($error) {
            if ($error->getHttpStatusCode() == 401) {
                (new AuthorizeQuickbooks(config('invoice-gateways.quickbooks')))->refreshToken();
            }
            throw FailedException::forInvoiceCreate();
        }

        return $payment;
    }

    public function paymentMethod()
    {
        $invoice_config = $this->populateConfigFromDb();
        $query = "SELECT * from PaymentMethod";
        $response = Http::withToken($invoice_config['access_token'])->withHeaders([
            'Accept' => 'application/json'
        ])->get($this->base_url . '/v3/company/' . $invoice_config['businessId'] . '/query', [
            'query' => $query,
            'minorversion' => self::API_VERSION
        ]);


        return $response->json();
    }

    public function getInvoiceMessage()
    {
        $invoice_config = $this->populateConfigFromDb();
        $response = Http::withToken($invoice_config['access_token'])->withHeaders(['Accept' => 'application/json'])->get($this->base_url . '/v3/company/' . $invoice_config['businessId'] . '/preferences');

        $body = $response->json();
        $message = '';

        if (!empty($body['Preferences']) && !empty($body['Preferences']['SalesFormsPrefs']) && !empty($body['Preferences']['SalesFormsPrefs']['DefaultCustomerMessage'])) {
            $message = $body['Preferences']['SalesFormsPrefs']['DefaultCustomerMessage'];
        }
        return $message;
    }

    private function parseXMLToJSON($xml)
    {
        $xml = simplexml_load_string($xml, "SimpleXMLElement", LIBXML_NOCDATA);
        $json = json_encode($xml);
        return json_decode($json, TRUE);
    }
}

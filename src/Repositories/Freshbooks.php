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
    public $businessId;
    public $auth_token;
    public $refresh_token;
    public $incomeAccountId;
    public $invoice_id;
    private $freshbooks;

    function __construct(array $config = [])
    {   
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
    public function create($input = [])
    {  
        $variables = [
            "businessId" => $this->businessId,
            "customerId" => $input['customer_id'],
            "customerEmail" => $input['billing_address'],
            "status" => $input['invoice_create_status'] ?? 'SAVED',
            'product' => $input['product'],
            "invoiceNumber" => $input['invoice_number'] ?? "",
        ];
        $data = $this->freshbooks->invoiceCreate($variables);
        if($data->failed()){
            $error = $data['response']['errors'][0];
            \Log::error('Could\'t create freshbooks invoice. user_id:' . $this->user_id, ['data' => $error]);
            throw FailedException::forInvoiceCreate( $error['message'], 422);
        }else{
            $result = $data['response']['result'];
            if(isset($result['invoice'])){
                \Log::debug('Freshbooks invoice created successfully for user_id: ' . $this->user_id, ['_trace' => $result]);
                $invoice = $result['invoice'];
    
                return [
                    'success' => true, 
                        'message' => 'Invoice created successfully', 
                        'data' => [
                            'id' => $invoice['invoiceid'],
                            'invoiceNumber' => $invoice['invoice_number'],
                            'pdfUrl' => '',
                            'viewUrl' => '',
                            'status' => 'SAVED',
                        ]
                    ];
            }
        }
    }

    /**
     * Get Draft Invoice for edit.
     */
    public function getDraft(){}

    /**
     * Create invoice and save it in draft.
     */
    public function saveInDraft(){}

    /**
     * Update draft invoice.
     */
    public function update($input = []){}

    /**
     * Send invoice to the customer via email.
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

    public function createAndSend($input = []){}

    public function delete($input = [])
    {
        return $this->freshbooks->deleteInvoice($input);
    }


    public function createProduct($product = [], $invoice_number)
    {
        $data = $this->freshbooks->getInvoicesList();
        if($data->ok()){
            $invoices = $data->json()['response']['result']['invoices'];
            $invoice_number_list = [];
            foreach($invoices as $invoice){
                $invoice_number_list[] = $invoice['invoice_number'] ? $invoice['invoice_number'] : '';
            }
            if(in_array($invoice_number, $invoice_number_list)){
                throw FailedException::forProductCreate('Invoice number already exists, please try something new!',422);
            }
        }
        
        // $data = $this->freshbooks->checkProductExist($product);
        // if($data->ok()){
        //     $response = $data->json()['response']['result'];
        //     \Log::Info($data->json()['response']);
        //     if($response['items']){
        //         $message = 'Fresbooks(FB) duplicate item message: FB does not support duplicate/repeated items. Please update the Job Title for this invoice. Save. And then try again.';
        //         throw FailedException::forProductCreate($message, 422);
        //         \Log::debug('Freshbooks existing items used for user_id:' . $this->user_id, ['_trace' => $response]);

        //         // return [
        //         //     'id' => $response['items'][0]['id'], 
        //         //     'name' => $response['items'][0]['name'],
        //         //     'product' => $response['items'][0]
        //         // ];
        //     }
        // }

        $data = $this->freshbooks->createProduct($product);
        if($data->ok()){
            $response = $data->json()['response']['result'];
            \Log::debug('Freshbooks Product created successfully for user_id:' . $this->user_id, ['_trace' => $response]);
            
            return [
                'id' => $response['item']['id'], 
                'name' => $response['item']['name'],
                'product' => $response['item']
            ];  
        }else{
            $error = $data->json()['response']['errors'][0];
            if($error['errno']== 1003){
                \Log::debug('Token generation from refresh token for user_id:' . $this->user_id, ['_trace' => $error]);
                (new AuthorizeFreshbooks( config('invoice-gateways.freshbooks')))->refreshToken();
                throw UnauthenticatedException::forCustomerAll();
            }else{
                \Log::error('Product already created' . $this->user_id, ['_trace' => $error]);
                $message = $input['message'] ?? 'Fresbooks(FB) duplicate item message: FB does not support duplicate/repeated items. Please update the Job Title for this invoice. Save. And then try again.';
                throw FailedException::forProductCreate($message, 422);
                // throw FailedException::forProductCreate($error['message'], 422);
            }
        }             
    }

    private function populateConfigFromDb()
    {
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

    public function getAllCustomers()
    {
        $variables = [
            "businessId" => $this->businessId
        ];
        $response = $this->freshbooks->customers($variables);
       
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
    public function createCustomer($input = [])
    {
        return $this->freshbooks->createCustomer($input);
    }

    public function downloadInvoice($invoice_id)
    {
        return $this->freshbooks->downloadInvoice($invoice_id);
    }
}

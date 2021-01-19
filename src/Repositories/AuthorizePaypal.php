<?php

namespace Amritms\InvoiceGateways\Repositories;

use Illuminate\Http\Request;
use Amritms\InvoiceGateways\Contracts\Authorize;
use Amritms\InvoiceGateways\Models\InvoiceGateway as InvoiceGatewayModel;
use Illuminate\Support\Facades\Http;


class AuthorizePaypal implements Authorize {

    protected $config;

    public function __construct($config = [])
    {
        $this->config = !empty($config) ? $config : config('invoice-gateways.paypal');

    }

    /**
     * 1. Redirect to Wave to request authorization
     */
    public function authorize(){
        // if(!auth()->check()){
        //     return redirect(url('/'));
        // }

        try{

            $auth_scopes = str_replace(' ','+',$this->config['account']['auth_scope']);
            $query_string = http_build_query([
                'scope'=>$auth_scopes,
                'redirect_uri' => $this->config['account']['auth_redirect_uri'],
                'flowEntry' => 'static',
                'client_id' => $this->config['account']['client_id'],
                'response_type' => 'code'
            ]);

            $url = $this->config['account']['auth_uri'].'?flowEntry=static&scope='.$auth_scopes.
                                                            '&redirect_uri='.$this->config['account']['auth_redirect_uri'].
                                                            '&client_id='.$this->config['account']['client_id'].
                                                            '&response_type=code';

            // dd($url,$this->config['account']['client_id']);
            // dd($url);
            return redirect($url);
        } 
        catch (\Exception $e){
            session()->flash('error', 'Couldn\'t connect with quickbooks, Please contact info@voiceoverview.com with full details.');
            \Log::error($e->getMessage(),[
                '_user_id' => auth()->id(),
                'url' => \Request::fullUrl() ?? '',
                'method' => \Route::getCurrentRoute()->getActionName() ?? '',
                '__trace' => $e->getTraceAsString()
            ]);

            return redirect()->back();
        }
    }

    /**
     * 2. User redirected back to your site by Wave
     * code - The server returns the authorization code in the query string. May only be exchanged * once
     */
    public function callback(Request $request)
    {
        if(! request('code')){
            \Log::error('something went wrong, Paypal didn\'t return code', ['_trace' => request()->json()]);
            flash('something went wrong, Paypal didn\'t return code.')->error();
            $job_url_before_redirect = session()->pull('job_url_before_redirect');

            return redirect($job_url_before_redirect ?? url('job'));
        }

        // integrate in package
        // \Log::info(request('code'));
        // return redirect('http://localhost:8000/paypal/authorize?code='.request('code'));

        $client_id = config('invoice-gateways.paypal.account.client_id');
        $client_secret = config('invoice-gateways.paypal.account.client_secret');
    
        $headerToken = $client_id.':'.$client_secret;
    
            $curl = curl_init();
    
        $data = array(
            CURLOPT_URL => "https://api.sandbox.paypal.com/v1/oauth2/token",
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => "POST",
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_POSTFIELDS => http_build_query(array(
                'grant_type'=>'authorization_code',
                'code'=> $request->code,
            )),
            CURLOPT_HTTPHEADER => array(
                "Content-Type: application/x-www-form-urlencoded",
            ),
            CURLOPT_USERPWD => $headerToken
        );
    
        curl_setopt_array($curl, $data);
    
        $response = curl_exec($curl);
        $status_code = curl_getinfo($curl,CURLINFO_HTTP_CODE);
    
        if($status_code>=400){
            dd($response);
        }
        dd(json_decode($response,true));
    
    
        curl_close($curl);



        // $response = $this->getToken($request);
        // session(['access_token' => $response['access_token']]);
        // session(['expires_in' => now()->addSeconds($response['expires_in'])]);
        // $user_id = auth()->id();

        // $invoice_configs = [
        //     'user_id' => $user_id,
        //     'invoice_type' => 'quickbooks',
        //     'config' => [
        //         "businessId" => $response['businessId'],
        //         "refresh_token" => $response['refresh_token'],
        //         "access_token" => $response['access_token'],
        //         "expires_in" => $response['expires_in'],
        //         'refresh_token_expires_in' => $response['refresh_token_expires_in']
        //     ],
        //     'business_id' => $response['businessId']
        // ];
        // (new InvoiceGatewayModel)->updateOrCreate(['user_id' => $user_id], $invoice_configs);
        // \Log::debug('Application verified successfully for user::' . $user_id);
        // flash('Application verified successfully.')->success();
        // $job_url_before_redirect = session()->pull('job_url_before_redirect');

        // return redirect($job_url_before_redirect ?? url('job'));
    }

    /**
     * Expired tokens and refreshing
     */
    public function refreshToken(){
        if(!auth()->check()){
            return redirect(url('/'));
        }

        $config = InvoiceGatewayModel::where('user_id', \Auth::user()->id)->first();
        $oauth2LoginHelper  = new OAuth2LoginHelper($this->config['client_id'],$this->config['client_secret']);
        $accessTokenObj = $oauth2LoginHelper->refreshAccessTokenWithRefreshToken($config->config['refresh_token']);
        $accessTokenValue = $accessTokenObj->getAccessToken();
        $refreshTokenValue = $accessTokenObj->getRefreshToken();
        $data = [
            'access_token' => $accessTokenObj->getAccessToken(),
            'refresh_token' => $accessTokenObj->getRefreshToken(),
            'refresh_token_expires_in' => $accessTokenObj->getRefreshTokenExpiresAt(),
            'expires_in' => $accessTokenObj->getAccessTokenExpiresAt(),
            'businessId' => $config->config['businessId'],
            'incomeAccountId' => $config->config['incomeAccountId'] ?? ''
        ];
        
        config(['invoice-gateways.quickbooks.access_token' => $data['access_token']]);
        config(['invoice-gateways.quickbooks.expires_in' => $data['expires_in'] ]);

        InvoiceGatewayModel::where('user_id', \Auth::user()->id)->update([
            'config' => $data
        ]);
        \Log::debug('Token refreshed successfully for user_id:' . auth()->id());

        return $data;
    }


    public function getToken(Request $request) {
        $OAuth2LoginHelper = $this->dataService->getOAuth2LoginHelper();
        $accessToken = $OAuth2LoginHelper->exchangeAuthorizationCodeForToken($request->get('code'), $request->get('realmId'));
        $this->dataService->updateOAuth2Token($accessToken);
        $data = [
            'token_type' => 'bearer',
            'access_token' => $accessToken->getAccessToken(),
            'refresh_token' => $accessToken->getRefreshToken(),
            'refresh_token_expires_in' => $accessToken->getRefreshTokenExpiresAt(),
            'expires_in' => $accessToken->getAccessTokenExpiresAt(),
            'businessId' => $request->get('realmId'),
        ];

        return $data;
    }

    private function parseAuthRedirectUrl($url) {
        parse_str($url,$qsArray);

        return array(
            'code' => $qsArray['code'],
            'realmId' => $qsArray['realmId']
        );
    }
}

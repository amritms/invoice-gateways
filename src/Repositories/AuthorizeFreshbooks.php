<?php

namespace Amritms\InvoiceGateways\Repositories;

use Illuminate\Support\Arr;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Amritms\InvoiceGateways\Contracts\Authorize;
use Amritms\InvoiceGateways\Models\InvoiceGateway as InvoiceGatewayModel;

class AuthorizeFreshbooks implements Authorize{

    protected $config;

    public function __construct($config = [])
    {
        $this->config = empty($config) ? $config : config('invoice-gateways.freshbooks');
        $this->config['state'] = "csrf_protection";
    }

    /**
     * 1. Redirect to Freshbooks to request authorization
     * https://developer.waveapps.com/hc/en-us/articles/360019493652
     */
    public function authorize()
    {
        if(!auth()->check()){

            return redirect(url('/'));
        }
        try{
            $url = $this->config['freshbook_uri'] .
                '?client_id='. $this->config['client_id'] .
                '&response_type=code' .
                '&redirect_uri=' . $this->config['freshbook_auth_uri_redirect'];
           
            session(['job_url_before_redirect' => url()->previous()]);

            return redirect($url);

        } catch (\Exception $e){
            session()->flash('error', 'Couldn\'t connect with freshbooks, Please contact info@voiceoverview.com with full details.');

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
     * code - The server returns the authorization code in the query string. May only be exchanged * once and expire 10 minutes after issuance.
     * state - The server returns the same state value that you passed (if you provided one). If the states don't match, the request may have been created by a third party and you should abort the process.
     */
    public function callback(Request $request)
    {
        if(! request('code')){
            \Log::error('something went wrong, freshbooks didn\'t return code', ['_trace' => request()->json()]);

            flash('something went wrong, freshbooks didn\'t return code.')->error();
            $job_url_before_redirect = session()->pull('job_url_before_redirect');
    
            return redirect($job_url_before_redirect ?? url('job'));
        }
        // 2.1 Exchange auth code for tokens
        // Your application should POST to: https://api.freshboks.com/auth/oauth/token/
       $headers = [
            'client_id' => $this->config['client_id'],
            'client_secret' => $this->config['client_secret'],
            'code' => request('code'),
            'grant_type' => 'authorization_code',
            'redirect_uri' => $this->config['freshbook_auth_uri_redirect']
       ];
        $response = Http::asForm()->post('https://api.freshbooks.com/auth/oauth/token', $headers);
        if( $response->status() != 200){
            \Log::error('Something went wrong, could\'t verify application', ['_trace' => request()->json()]);

            flash('Something went wrong, could\'t verify application')->error();
            $job_url_before_redirect = session()->pull('job_url_before_redirect');
    
            return redirect($job_url_before_redirect ?? url('job'));
        }
        $header = [
            'Content-Type' => 'application/json',
            'Authorization' => 'Bearer ' . $response->json()['access_token'],
            'Api-Version' => 'alpha',
        ];
        //get user info
        $user_info = Http::withHeaders($header)->get('https://api.freshbooks.com/auth/api/v1/users/me');
       
        if( $user_info->status() != 200){
            \Log::error('Something went wrong, could\'t verify application', ['_trace' => request()->json()]);

            flash('Something went wrong, could\'t verify application')->error();
            $job_url_before_redirect = session()->pull('job_url_before_redirect');
    
            return redirect($job_url_before_redirect ?? url('job'));
        }
        $response = $response->json();
        $businesses = $user_info->json()['response']['business_memberships'];
        $business_list = [];
        foreach ($businesses as $key => $business) {
            $business_list[] = Arr::only($business['business'], ['account_id', 'name']);
        }
        session(['access_token' => $response['access_token']]);
        session(['expires_in' => now()->addSeconds($response['expires_in'])]);

        $user_id = auth()->id();
        $invoice_configs = [
            'user_id' => $user_id,
            'invoice_type' => 'freshbooks',
            'config' => [
                "business_list" => $business_list,
                "businessId" => '',
                "refresh_token" => $response['refresh_token'],
                "access_token" => $response['access_token'],
                "expires_in" => now()->addSeconds($response['expires_in'])
            ]
        ];
        (new InvoiceGatewayModel)->updateOrCreate(['user_id' => $user_id], $invoice_configs);

        \Log::debug('Application verified successfully for user::' . $user_id, ['_trace' => $response]);
        flash('Application verified successfully.')->success();
       
        $job_url_before_redirect = session()->pull('job_url_before_redirect');

        return redirect($job_url_before_redirect ?? url('job'));
    }

    /**
     * Expired tokens and refreshing
     * Your application should POST to: https://api.freshbooks.com/oauth2/token/
     */
    public function refreshToken()
    {
        if(!auth()->check()){

            return redirect(url('/'));
        }

        $config = InvoiceGatewayModel::where('user_id', \Auth::user()->id)->first();
        $body = [
            'grant_type' => 'refresh_token',
            'refresh_token' => $config->config['refresh_token'],
            'client_id' => $this->config['client_id'],
            'client_secret' => $this->config['client_secret'],
            'redirect_uri' => config(['invoice-gateways.freshbooks.freshbook_auth_uri_redirect'])
        ];

        $response = HTTP::post('https://api.freshbooks.com/auth/oauth/token', $body);

        // refresh token is stored indefinitely, if waveaps returns unauthorized(401) status code, then redirect user to get access token.
        if(in_array($response->status(), [400, 401])){
            \Redirect::to(route('invoce-gateways.authorize'))->send();
        }

        $header = [
            'Content-Type' => 'application/json',
            'Authorization' => 'Bearer ' . $response->json()['access_token'],
            'Api-Version' => 'alpha',
        ];
        config(['invoice-gateways.freshbooks.access_token' => $response['access_token']]);
        config(['invoice-gateways.freshbooks.expires_in' => now()->addSeconds($response['expires_in'])]);
        
        $invoice_config = \Auth::user()->invoicesConfig()->first();
        $config = json_decode($invoice_config->config,true);

        $config['refresh_token']=$response['refresh_token'];
        $config['access_token'] = $response['access_token'];
        $config['expires_in'] = now()->addSeconds($response['expires_in']);
        $invoice_config->update([
            'config'=> json_encode($config)
        ]);
        \Log::debug('Token refreshed successfully for user_id:' . auth()->id());
    }
}

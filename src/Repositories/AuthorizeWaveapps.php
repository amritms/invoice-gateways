<?php

namespace Amritms\InvoiceGateways\Repositories;

use Amritms\InvoiceGateways\Contracts\Authorize;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Amritms\InvoiceGateways\Models\InvoiceGateway as InvoiceGatewayModel;

class AuthorizeWaveapps implements Authorize
{
    protected $config;

    public function __construct($config = [])
    {
        $this->config = empty($config) ? $config : config('invoice-gateways.waveapps');
        $this->config['state'] = "csrf_protection";
    }

    /**
     * 1. Redirect to Wave to request authorization
     * https://developer.waveapps.com/hc/en-us/articles/360019493652
     */
    public function authorize(){
        if(!auth()->check()){
            return redirect(url('/'));
        }

        try{
            $url = $this->config['graphql_auth_uri'] .
                '?client_id='. $this->config['client_id'].
                '&response_type=code'.
                '&scope=' . $this->config['granted_permissions'].
                '&state='. $this->config['state'];

            session(['job_url_before_redirect' => url()->previous()]);

            
            return redirect($url);

        } catch (\Exception $e){
            session()->flash('error', 'Couldn\'t connect with waveapps, Please contact info@voiceoverview.com with full details.');

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
            \Log::error('something went wrong, waveapps didn\'t return code', ['_trace' => request()->json()]);

            flash('something went wrong, waveapps didn\'t return code.')->error();
            $job_url_before_redirect = session()->pull('job_url_before_redirect');
    
            return redirect($job_url_before_redirect ?? url('job'));
        }

        // 2.1 Exchange auth code for tokens
        // Your application should POST to: https://api.waveapps.com/oauth2/token/
        $response = HTTP::asForm()->post('https://api.waveapps.com/oauth2/token/', [
            'client_id' => $this->config['client_id'],
            'client_secret' => $this->config['client_secret'],
            'code' => request('code'),
            'grant_type' => 'authorization_code',
            'redirect_uri' => $this->config['graphql_auth_redirect_uri'],
        ]);

        if( $response->status() != 200){
            \Log::error('something went wrong, could\'t verify application', ['_trace' => request()->json()]);

            flash('something went wrong, could\'t verify application')->error();
            $job_url_before_redirect = session()->pull('job_url_before_redirect');
    
            return redirect($job_url_before_redirect ?? url('job'));
        }

        $response = $response->json();

        session(['access_token' => $response['access_token']]);
        session(['expires_in' => now()->addSeconds($response['expires_in'])]);

        $user_id = auth()->id();

        $invoice_configs = [
            'user_id' => $user_id,
            'invoice_type' => 'waveapps',
            'config' => [
                "businessId" => $response['businessId'],
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
     * Your application should POST to: https://api.waveapps.com/oauth2/token/
     */
    public function refreshToken()
    {
        if(!auth()->check()){
            return redirect(url('/'));
        }

        $config = InvoiceGatewayModel::where('user_id', \Auth::user()->id)->first();

        $response = HTTP::asForm()->post('https://api.waveapps.com/oauth2/token/', [
            'client_id' => $this->config['client_id'],
            'client_secret' => $this->config['client_secret'],
            'refresh_token' => $config['config']['refresh_token'],
            'grant_type' => 'refresh_token',
            'redirect_uri' => $this->config['graphql_auth_redirect_uri'],
        ]);

        try {
                  // refresh token is stored indefinitely, if waveaps returns unauthorized(401) status code, then redirect user to get access token.
            if(in_array($response->status(), [400, 401])){
                return \Redirect::to(route('invoce-gateways.authorize'))->send();
            }

            $response = $response->json();
            config(['invoice-gateways.waveapps.access_token' => $response['access_token']]);
            config(['invoice-gateways.waveapps.expires_in' => now()->addSeconds($response['expires_in'])]);

            InvoiceGatewayModel::where('user_id', \Auth::user()->id)->update([
                'config' => [
                    "businessId" => $response['businessId'],
                    "refresh_token" => $response['refresh_token'],
                    "access_token" => $response['access_token'],
                    "expires_in" => now()->addSeconds($response['expires_in'])
                ]
            ]);

            \Log::debug('Token refreshed successfully for user_id:' . auth()->id());
        } catch (\Throwable $th) {
            \Log::error([ 'msg'=> 'error on waveapps while refreshing token','__trace' => $th]);
            return \Redirect::to(route('invoce-gateways.authorize'))->send();
        }
    }
}

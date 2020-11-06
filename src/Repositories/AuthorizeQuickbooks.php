<?php

namespace Amritms\InvoiceGateways\Repositories;

use Illuminate\Http\Request;
use Amritms\InvoiceGateways\Contracts\Authorize;
use QuickBooksOnline\API\DataService\DataService;
use QuickBooksOnline\API\Core\OAuth\OAuth2\OAuth2LoginHelper;
use Amritms\InvoiceGateways\Models\InvoiceGateway as InvoiceGatewayModel;


class AuthorizeQuickbooks implements Authorize {

    protected $config;
    protected $dataService;

    public function __construct($config = [])
    {
        $this->config = !empty($config) ? $config : config('invoice-gateways.quickbooks');
        $this->dataService = DataService::configure([
            'auth_mode' => 'oauth2',
            'ClientID' => $this->config['client_id'],
            'ClientSecret' =>  $this->config['client_secret'],
            'RedirectURI' => $this->config['auth_redirect_uri'],
            'scope' => $this->config['oauth_scope'],
            'baseUrl' => $this->config['mode']
        ]);
    }

    /**
     * 1. Redirect to Wave to request authorization
     */
    public function authorize(){
        if(!auth()->check()){
            return redirect(url('/'));
        }

        try{
            $OAuth2LoginHelper = $this->dataService->getOAuth2LoginHelper();
            $url = $OAuth2LoginHelper->getAuthorizationCodeURL();
            session(['job_url_before_redirect' => url()->previous()]);

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
     * code - The server returns the authorization code in the query string. May only be exchanged * once and expire 10 minutes after issuance.
     * realmId - The server returns realmLd(BusinessId) of selected business
     */
    public function callback(Request $request)
    {
        if(! request('code')){
            \Log::error('something went wrong, quickbooks didn\'t return code', ['_trace' => request()->json()]);
            flash('something went wrong, quickbooks didn\'t return code.')->error();
            $job_url_before_redirect = session()->pull('job_url_before_redirect');

            return redirect($job_url_before_redirect ?? url('job'));
        }

        $response = $this->getToken($request);
        session(['access_token' => $response['access_token']]);
        session(['expires_in' => now()->addSeconds($response['expires_in'])]);
        $user_id = auth()->id();

        $invoice_configs = [
            'user_id' => $user_id,
            'invoice_type' => 'quickbooks',
            'config' => [
                "businessId" => $response['businessId'],
                "refresh_token" => $response['refresh_token'],
                "access_token" => $response['access_token'],
                "expires_in" => $response['expires_in'],
                'refresh_token_expires_in' => $response['refresh_token_expires_in']
            ],
            'business_id' => $response['businessId']
        ];
        (new InvoiceGatewayModel)->updateOrCreate(['user_id' => $user_id], $invoice_configs);
        \Log::debug('Application verified successfully for user::' . $user_id);
        flash('Application verified successfully.')->success();
        $job_url_before_redirect = session()->pull('job_url_before_redirect');

        return redirect($job_url_before_redirect ?? url('job'));
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

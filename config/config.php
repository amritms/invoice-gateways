<?php

/*
 * You can place your custom package configuration in here.
 */
return [
	// possible options: paypal, freshbooks, quickbooks, wave
    'payment_type' => env('PAYMENT_TYPE', 'waveapps'),
    'supported_invoice_types' => env('SUPPORTED_INVOICE_TYPES', 'waveapps'),
    'paypal' => [
        # Define your application mode here
	    'mode' => env('PAYPAL_MODE', 'sandbox'),  //"sanbox" for testing and "live" for production

	    # Account credentials from developer portal
	    'account' => [
	        'client_id'		=> env('PAYPAL_CLIENT_ID', ''),
	        'client_secret' => env('PAYPAL_CLIENT_SECRET', ''),
	    ],

	    # Connection Information
	    'http' => [
	        'retry' => 1,
	        'connection_time_out' => 30,
	    ],

	    # Logging Information
	    'log' => [
	        'log_enabled' => true,

	        # When using a relative path, the log file is created
	        # relative to the .php file that is the entry point
	        # for this request. You can also provide an absolute
	        # path here
	        'file_name' => '../PayPal.log',

	        # Logging level can be one of FINE, INFO, WARN or ERROR
	        # Logging is most verbose in the 'FINE' level and
	        # decreases as you proceed towards ERROR
	        'log_level' => 'FINE',
	    ],
    ],
	'freshbooks' => [
		'client_id' => env('FRESHBOOKS_CLIENT_ID'),
		'client_secret' => env('FRESHBOOKS_CLIENT_SECRET'),
		'freshbook_uri_redirect' => env('APP_URL') . '/redirect',
		'freshbook_uri' => env('FRESHBOOK_URI'),
		'app_url' => env('APP_URL'),
		'account_id' => env('ACCOUNT_ID', null),
		'access_token' => null,
		'refresh_token' => null,
	],
    'quickbooks' => [
		'client_id'=> env('QUICK_BOOKS_CLIENT_ID',''),
		'client_secret' => env('QUICK_BOOKS_CLIENT_SECRET',''),
		'auth_uri' => env('QUICK_BOOKS_AUTH_URI','https://appcenter.intuit.com/connect/oauth2'),
		'auth_redirect_uri' => env('QUICK_BOOKS_AUTH_REDIRECT_URI','/invoice-gateways/callback?invoice_type=quickbooks'),
		'mode' => env('QUICK_BOOKS_MODE','production'),
		'oauth_scope' => env('QUICK_BOOKS_OAUTH_SCOPE','com.intuit.quickbooks.accounting openid profile email phone address'),
		'webHook_verify_token' => env('QUICK_BOOKS_WEB_HOOKS_VERIFY_TOKEN',''),
		'businessId' => env('QUICK_BOOKS_BUSINESS_ID',''),
		'access_token' => null,
		'refresh_token' => null,
		'base_url' => env('QUICK_BOOKS_BASE_URL','https://quickbooks.api.intuit.com'),
		'invoice_url'=> env('QUICK_BOOKS_INVOICE_URL','')
	],
    'waveapps' => [
        'client_id' => env('WAVE_CLIENT_ID'),
        'client_secret' => env('WAVE_CLIENT_SECRET'),
        'graphql_auth_uri' => env('WAVE_GRAPHQL_AUTH_URI', 'https://api.waveapps.com/oauth2/token/'),
        'graphql_auth_redirect_uri' => env('WAVE_GRAPHQL_AUTH_REDIRECT_URI', '/invoice-gateways/call-back'),
        'graphql_uri' => env('WAVE_GRAPHQL_URI', 'https://gql.waveapps.com/graphql/public'),
        'businessId' => env('WAVE_BUSINESS_ID', null),
        'granted_permissions' => env('WAVE_GRANTED_PERMISSIONS', 'account:* business:read customer:* invoice:* product:* user:read'),
        'access_token' => null,
        'refresh_token' => null
    ]
];


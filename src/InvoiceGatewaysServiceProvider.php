<?php

namespace Amritms\InvoiceGateways;

use Illuminate\Support\ServiceProvider;
//use Amritms\WaveappsClientPhp\Waveapps;
use Amritms\InvoiceGateways\Contracts\Invoice;
use Amritms\InvoiceGateways\Contracts\Authorize;
use Amritms\InvoiceGateways\Repositories\Waveapps;
use Amritms\InvoiceGateways\Repositories\Paypal;
use Amritms\InvoiceGateways\Repositories\Freshbooks;
use Amritms\InvoiceGateways\Repositories\AuthorizeWaveapps;

class InvoiceGatewaysServiceProvider extends ServiceProvider
{
    /**
     * Bootstrap the application services.
     */
    public function boot()
    {
        /*
         * Optional methods to load your package assets
         */
        // $this->loadTranslationsFrom(__DIR__.'/../resources/lang', 'invoice-gateways');
        // $this->loadViewsFrom(__DIR__.'/../resources/views', 'invoice-gateways');
        // $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        $this->loadRoutesFrom(__DIR__.'/../routes/routes.php');


        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/config.php' => config_path('invoice-gateways.php'),
            ], 'config');

            // Publishing the views.
            /*$this->publishes([
                __DIR__.'/../resources/views' => resource_path('views/vendor/invoice-gateways'),
            ], 'views');*/

            // Publishing assets.
            /*$this->publishes([
                __DIR__.'/../resources/assets' => public_path('vendor/invoice-gateways'),
            ], 'assets');*/

            // Publishing the translation files.
            /*$this->publishes([
                __DIR__.'/../resources/lang' => resource_path('lang/vendor/invoice-gateways'),
            ], 'lang');*/

            // Registering package commands.
            // $this->commands([]);


        }

    }

    /**
     * Register the application services.
     */
    public function register()
    {
//        $request = app(\Illuminate\Http\Request::class);
//        $request = resolve(\Illuminate\Http\Request::class);

        // Automatically apply the package configuration
        $this->mergeConfigFrom(__DIR__.'/../config/config.php', 'invoice-gateways');

        $invoice_type = $this->app->request->get('invoice_type');

        $invoice_type = isset($invoice_type) ? $invoice_type :  config('invoice-gateways.payment_type');

        $this->app->bind(Invoice::class, function ($app) use($invoice_type){

            return $this->resolveInvoice($invoice_type);
        });



        $this->app->bind(Authorize::class, function ($app) use($invoice_type){
            switch($invoice_type){
                //in case of paypal
                case 'paypal' : return new AuthorizePaypal();
                    break;

                case 'waveapps' : return new AuthorizeWaveapps(config('invoice-gateways.waveapps'));
                    break;

                //in case of freshbooks
                case 'freshbooks' : return new AuthorizeFreshbooks();
                    break;

                //in case of quickbooks
                case 'quickbooks' : return new AuthorizeQuickbooks();
                    break;

                //default case
//                default : return new Paypal();
            }
        });
    }

    private function resolveInvoice($invoice_type){
        switch($invoice_type){
            //in case of paypal
            case 'paypal' : return new Paypal();
                break;

            case 'waveapps' : return new Waveapps(null, null, null, config('invoice-gateways.waveapps'));
                break;

            //in case of freshbooks
            case 'freshbooks' : return new Freshbooks();
                break;

            //in case of quickbooks
            case 'quickbooks' : return new Quickbooks();
                break;

            //default case
//                default : return new Paypal();
        }
    }
}

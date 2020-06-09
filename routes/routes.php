<?php

use Amritms\InvoiceGateways\Controllers\AuthorizeController;
use Amritms\InvoiceGateways\Controllers\InvoiceGatewayController;

Route::get('invoice-gateways/authorize', [AuthorizeController::class, 'authorize'])->name('invoce-gateways.authorize');
Route::get('invoice-gateways/get-refresh-token', [AuthorizeController::class, 'refreshToken']);
Route::get('invoice-gateways/call-back', [AuthorizeController::class, 'callback']);
//Route::get('invoice-gateways/create-invoice-form', 'InvoiceGatewayController@createInvoiceForm');
Route::post('invoice-gateways/create-invoice', [InvoiceGatewayController::class, 'create']);
//Route::post('invoice-gateways/create-invoice', 'InvoiceGatewayController@createInvoice');
Route::get('invoice-gateways/get-all-invoices', [InvoiceGatewayController::class,'getAllInvoices']);
//Route::get('invoice-gateways/get-all-customers', 'InvoiceGatewayController@getAllCustomers');
//Route::get('invoice-gateways/get-all-products', 'InvoiceGatewayController@getAllProducts');
//Route::get('invoice-gateways/get-accountid', 'InvoiceGatewayController@getAccountId');
//Route::post('invoice-gateways/create-customer', 'InvoiceGatewayController@createCustomer');
//Route::post('invoice-gateways/create-product', 'InvoiceGatewayController@createProduct');
//Route::post('invoice-gateways/approve-invoice', 'InvoiceGatewayController@approveInvoice');
//Route::post('invoice-gateways/send-invoice', 'InvoiceGatewayController@sendInvoice');
//Route::delete('invoice-gateways/delete-invoice', 'InvoiceGatewayController@deleteInvoice');
Route::get('invoice-gateways/test', 'Amritms\InvoiceGateways\Controllers\InvoiceGatewayController@wavePackageTest');

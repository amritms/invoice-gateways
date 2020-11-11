<?php

use Amritms\InvoiceGateways\Controllers\AuthorizeController;
use Amritms\InvoiceGateways\Controllers\InvoiceGatewayController;

Route::get('invoice-gateways/authorize', [AuthorizeController::class, 'authorize'])->name('invoce-gateways.authorize');
Route::get('invoice-gateways/get-refresh-token', [AuthorizeController::class, 'refreshToken']);
Route::get('invoice-gateways/call-back', [AuthorizeController::class, 'callback']);
Route::post('invoice-gateways/create-invoice', [InvoiceGatewayController::class, 'create']);

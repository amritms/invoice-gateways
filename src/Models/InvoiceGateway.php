<?php

namespace Amritms\InvoiceGateways\Models;

use Illuminate\Database\Eloquent\Model;

Class InvoiceGateway extends Model
{
    protected $table = 'invoices_configs';
    protected $guarded = [];

    protected $casts = [
        'user_id' => 'integer',
        'invoice_type' => 'string',
        'config' => 'array'
    ];
}

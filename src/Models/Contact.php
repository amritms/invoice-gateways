<?php


namespace Amritms\InvoiceGateways\Models;


use Illuminate\Database\Eloquent\Model;

class Contact extends Model
{
    protected $table = 'contacts';
    protected $guarded = [];

    public function updateCustomerId($customer_id){
        return $this->update(['customer_id', $customer_id]);
    }
}

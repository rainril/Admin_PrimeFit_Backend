<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AttendanceLog extends Model
{
    protected $fillable = [
        'customer_id',
        'handled_by_admin_id',
        'date',
        'check_in_time',
        'check_out_time',
        'status',
    ];

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }

    public function handledByAdmin()
    {
        return $this->belongsTo(Admin::class, 'handled_by_admin_id');
    }
}
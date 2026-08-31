<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ApprovalRequest extends Model
{
    protected $fillable = [
        'type',
        'target_type',
        'target_id',
        'payload',
        'original',
        'requested_by',
        'status',
    ];

    protected $casts = [
        'payload' => 'array',
        'original' => 'array',
    ];
}
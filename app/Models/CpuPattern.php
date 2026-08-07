<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CpuPattern extends Model
{
    protected $guarded = [];

    protected $casts = [
        'layout' => 'array',
    ];
}

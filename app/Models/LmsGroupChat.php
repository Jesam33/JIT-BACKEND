<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Traits\TenantAware;

class LmsGroupChat extends Model
{
    use HasFactory;
    use TenantAware;

    protected $fillable = [
        'track_id',
    ];
}

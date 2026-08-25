<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Plan extends Model
{
    use HasFactory;

    protected $fillable = ['name', 'slug', 'price', 'description', 'features'];

    protected $casts = [
        'price' => 'decimal:2',
        'features' => 'array',
    ];
}

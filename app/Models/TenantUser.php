<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class TenantUser extends Model
{
    use HasFactory;

    protected $table = 'tenant_users';

    protected $fillable = ['tenant_id', 'name', 'email', 'password', 'role'];

    protected $hidden = ['password'];
}

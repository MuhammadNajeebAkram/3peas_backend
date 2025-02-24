<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class UserPaymentSlip extends Model
{
    use HasFactory;

    protected $table = 'user_payment_slip_tbl';

    // Define the primary key (optional if it's 'id')
    protected $primaryKey = 'id';

    // Disable timestamps if the table doesn't have created_at and updated_at
    public $timestamps = true;

    protected $fillable = [
        'user_id', 
        'name', 
       
    ];
}

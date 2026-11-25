<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AdminActivityLog extends Model
{
    public $timestamps = false;

    protected $table = 'admin_activity_logs';

    protected $guarded = ['id'];

    protected $casts = [
        'actor_id' => 'integer',
        'target_id' => 'integer',
        'metadata' => 'array',
        'ip_address' => 'encrypted',
        'user_agent' => 'encrypted',
        'created_at' => 'datetime',
    ];

    public function actor()
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}

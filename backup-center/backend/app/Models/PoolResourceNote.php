<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PoolResourceNote extends Model
{
    protected $fillable = ['pool_id', 'path', 'remark'];

    public function pool(): BelongsTo
    {
        return $this->belongsTo(ResourcePool::class, 'pool_id');
    }
}

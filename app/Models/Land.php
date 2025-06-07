<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Land extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'lands';

    protected $fillable = [
        'land_name',
        'user_id',
        'location',
        'data',
        'geojson',
        'color',
        'enabled',
    ];

    protected $casts = [
        'location' => 'array',
        'data' => 'array',
        'geojson' => 'array',
        'enabled' => 'boolean',
    ];

    protected $dates = [
        'deleted_at',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function devices(): HasMany
    {
        return $this->hasMany(Device::class, 'land_id');
    }
}

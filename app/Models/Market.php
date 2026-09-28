<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Market extends Model
{
    use HasFactory;

    protected $fillable = [
        'name', 'slug', 'description', 'address', 'city', 'latitude', 'longitude',
        'operating_days', 'open_time', 'close_time', 'image', 'is_active',
    ];

    protected function casts(): array
    {
        return [
            'operating_days' => 'array',
            'is_active' => 'boolean',
            'latitude' => 'float',
            'longitude' => 'float',
        ];
    }

    public function farmers(): BelongsToMany
    {
        return $this->belongsToMany(Farmer::class, 'farmer_market')->withTimestamps();
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function operatingDaysLabel(): string
    {
        if (empty($this->operating_days)) {
            return 'Days vary';
        }

        $days = is_array($this->operating_days) ? $this->operating_days : json_decode($this->operating_days, true);
        $map = [
            'monday' => 'Mon', 'tuesday' => 'Tue', 'wednesday' => 'Wed',
            'thursday' => 'Thu', 'friday' => 'Fri', 'saturday' => 'Sat', 'sunday' => 'Sun',
        ];
        $labels = array_map(fn ($d) => $map[strtolower($d)] ?? ucfirst($d), $days);

        return implode(', ', $labels);
    }

    public function isOpenToday(): bool
    {
        $days = $this->operating_days ?? [];
        if (! is_array($days)) {
            $days = json_decode($days, true) ?? [];
        }

        return in_array(strtolower(now()->format('l')), array_map('strtolower', $days));
    }
}

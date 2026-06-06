<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Product extends Model
{
    protected $guarded = [];
    protected $casts = ['is_active' => 'boolean'];

    public function packages()
    {
        return $this->hasMany(ProductPackage::class);
    }

    public function variations()
    {
        return $this->hasMany(Variation::class);
    }

    public function categorie()
    {
        return $this->belongsTo(Categorie::class);
    }
}
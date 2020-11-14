<?php

namespace App;

use Illuminate\Database\Eloquent\Model;

class RegistryRecord extends Model
{
    protected $table = 'registry_record';

    protected $fillable = [
        'source_filename',
        'out_filename',
        'rows_count',
        'rows_success',
        'rows_warning',
        'user_id'
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}

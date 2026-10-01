<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
class ActivityLog extends Model
{
    use HasUlids;
    public $timestamps = false;
    protected $guarded = ['id'];
    protected $casts = ['metadata' => 'array', 'created_at' => 'immutable_datetime'];
}

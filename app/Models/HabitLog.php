<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
class HabitLog extends Model
{
    use HasUlids;
    protected $guarded = ['id'];
    protected $casts = ['completed' => 'boolean'];
}

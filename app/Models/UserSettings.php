<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
class UserSettings extends Model
{
    use HasUlids;
    protected $guarded = ['id', 'user_id'];
    protected $casts = ['week_starts_on' => 'integer'];
}

<?php
namespace App\Http\Requests;

use App\Support\EntityCatalog;
use App\Support\RecordRules;
use Illuminate\Foundation\Http\FormRequest;

class RecordRequest extends FormRequest
{
    public function authorize(): bool
    {
        if (! $this->user()) return false;
        if ($id = $this->route('id')) EntityCatalog::find($this->route('entity'), $this->user(), $id);
        return true;
    }
    public function rules(): array { return RecordRules::rules($this->route('entity'), $this->user(), $this->route('id')); }
}

<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreSeatLayoutRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:80'],
            'description' => ['nullable', 'string', 'max:300'],
            'decks' => ['required', 'integer', 'in:1,2'],
            'rows' => ['required', 'integer', 'min:1', 'max:40'],
            'cols' => ['required', 'integer', 'min:1', 'max:10'],
            'seats' => ['required', 'array', 'min:1'],
            'seats.*.deck' => ['required', 'integer', 'min:1', 'max:2'],
            'seats.*.row' => ['required', 'integer', 'min:1'],
            'seats.*.col' => ['required', 'integer', 'min:1'],
            'seats.*.label' => ['required', 'string', 'max:10', 'distinct'],
            'seats.*.type' => ['required', 'in:standard,premium,vip,disabled_access,staff,driver,toilet,kitchen,stairs,exit'],
            'seats.*.position' => ['nullable', 'in:window,aisle,middle,none'],
            'seats.*.side' => ['nullable', 'in:left,right,center'],
            'seats.*.price_modifier' => ['nullable', 'numeric', 'min:0', 'max:9999'],
        ];
    }
}

<?php

declare(strict_types=1);

namespace App\Modules\Generation\Http\Requests;

use App\Modules\Generation\Enums\Platform;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class GenerateAdRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'platform' => ['required', 'string', Rule::enum(Platform::class)],
        ];
    }

    public function platform(): Platform
    {
        return Platform::from($this->string('platform')->toString());
    }
}

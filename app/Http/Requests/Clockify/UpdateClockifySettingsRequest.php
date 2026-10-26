<?php

/**
 * Invoice Ninja (https://invoiceninja.com).
 *
 * @link https://github.com/invoiceninja/invoiceninja source repository
 *
 * @copyright Copyright (c) 2026. Invoice Ninja LLC (https://invoiceninja.com)
 *
 * @license https://www.elastic.co/licensing/elastic-license
 */

namespace App\Http\Requests\Clockify;

use App\Http\Requests\Request;

class UpdateClockifySettingsRequest extends Request
{
    public function authorize(): bool
    {
        /** @var \App\Models\User $user */
        $user = auth()->user();

        return $user->isAdmin();
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'api_key' => ['sometimes', 'string', 'max:255'],
            'workspace_id' => ['sometimes', 'nullable', 'string', 'max:64'],
            'week_start' => ['sometimes', 'integer', 'between:1,7'],
        ];
    }
}

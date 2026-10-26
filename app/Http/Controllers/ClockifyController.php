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

namespace App\Http\Controllers;

use App\Http\Requests\Clockify\ImportClockifyRequest;
use App\Http\Requests\Clockify\UpdateClockifySettingsRequest;
use App\Services\Clockify\ClockifyImporter;
use Illuminate\Http\JsonResponse;
use RuntimeException;

class ClockifyController extends BaseController
{
    /**
     * Whether Clockify is configured. The API key itself is never returned.
     */
    public function show(): JsonResponse
    {
        /** @var \App\Models\User $user */
        $user = auth()->user();

        return response()->json(['data' => $this->settingsPayload($user->company()->clockify_config ?? [])]);
    }

    public function update(UpdateClockifySettingsRequest $request): JsonResponse
    {
        /** @var \App\Models\User $user */
        $user = auth()->user();
        $company = $user->company();

        $config = $company->clockify_config ?? [];

        foreach (['api_key', 'workspace_id', 'week_start'] as $field) {
            if ($request->has($field)) {
                $config[$field] = $request->input($field);
            }
        }

        $company->clockify_config = $config;
        $company->save();

        return response()->json(['data' => $this->settingsPayload($config)]);
    }

    /**
     * Roll up Clockify entries for a date range into tasks. With
     * dry_run (the default) nothing is created and the response shows what
     * would be.
     */
    public function import(ImportClockifyRequest $request): JsonResponse
    {
        /** @var \App\Models\User $user */
        $user = auth()->user();

        try {
            $result = (new ClockifyImporter($user->company(), $user))->run(
                $request->input('start'),
                $request->input('end'),
                $request->boolean('dry_run', true),
            );
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['data' => $result + ['dry_run' => $request->boolean('dry_run', true)]]);
    }

    /**
     * @param  array<string, mixed> $config
     * @return array<string, mixed>
     */
    private function settingsPayload(array $config): array
    {
        return [
            'has_key' => ! empty($config['api_key']),
            'workspace_id' => $config['workspace_id'] ?? null,
            'week_start' => (int) ($config['week_start'] ?? 1),
        ];
    }
}

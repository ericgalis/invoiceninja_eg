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

namespace App\Services\Clockify;

use DateTimeImmutable;
use DateTimeInterface;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Minimal Clockify API client: workspace lookup and a detailed time-entry
 * report for a date range.
 */
class ClockifyClient
{
    private const API_BASE = 'https://api.clockify.me/api/v1';

    private const REPORTS_BASE = 'https://reports.api.clockify.me/v1';

    private const PAGE_SIZE = 500;

    public function __construct(
        private string $apiKey,
        private ?string $workspaceId = null,
    ) {
        if ($apiKey === '') {
            throw new RuntimeException('Clockify API key is not configured.');
        }
    }

    /**
     * Every finished time entry started between $from (inclusive) and $to
     * (exclusive), as flat arrays. Running entries are skipped.
     *
     * Clockify returns clientName, projectName and taskName as flat top-level
     * fields on each row, not nested objects.
     *
     * @return array<int, array<string, mixed>>
     */
    public function detailedReport(DateTimeInterface $from, DateTimeInterface $to): array
    {
        $workspace = $this->requireWorkspaceId();
        $entries = [];
        $page = 1;

        do {
            $response = $this->request('post', self::REPORTS_BASE . "/workspaces/{$workspace}/reports/detailed", [
                'dateRangeStart' => $from->format('Y-m-d\TH:i:s.000\Z'),
                'dateRangeEnd' => $to->format('Y-m-d\TH:i:s.000\Z'),
                'detailedFilter' => ['page' => $page, 'pageSize' => self::PAGE_SIZE, 'sortColumn' => 'DATE'],
                'exportType' => 'JSON',
                'amountShown' => 'HIDE_AMOUNT',
            ]);

            $rows = $response->json('timeentries') ?? [];

            foreach ($rows as $row) {
                if ($parsed = self::parseRow($row)) {
                    $entries[] = $parsed;
                }
            }

            $page++;
        } while (count($rows) >= self::PAGE_SIZE);

        return $entries;
    }

    /**
     * @param  array<string, mixed> $row
     * @return array<string, mixed>|null
     */
    public static function parseRow(array $row): ?array
    {
        $interval = $row['timeInterval'] ?? [];

        if (empty($interval['start']) || empty($interval['end'])) {
            return null;
        }

        return [
            'id' => $row['_id'] ?? null,
            'description' => trim((string) ($row['description'] ?? '')),
            'billable' => (bool) ($row['billable'] ?? false),
            'client_name' => $row['clientName'] ?? null,
            'project_name' => $row['projectName'] ?? null,
            'clockify_task_name' => $row['taskName'] ?? null,
            'start' => new DateTimeImmutable($interval['start']),
            'end' => new DateTimeImmutable($interval['end']),
        ];
    }

    private function requireWorkspaceId(): string
    {
        if ($this->workspaceId) {
            return $this->workspaceId;
        }

        $workspaces = $this->request('get', self::API_BASE . '/workspaces')->json() ?? [];

        if (count($workspaces) === 1) {
            return $this->workspaceId = $workspaces[0]['id'];
        }

        throw new RuntimeException('Set a Clockify workspace ID: more than one workspace is available.');
    }

    /**
     * @param array<string, mixed> $body
     */
    private function request(string $method, string $url, array $body = []): Response
    {
        $http = Http::withHeaders(['X-Api-Key' => $this->apiKey])->timeout(60)->acceptJson();

        $response = $method === 'post' ? $http->post($url, $body) : $http->get($url);

        if ($response->failed()) {
            // Status only: the response body can echo request details.
            throw new RuntimeException("Clockify request failed with status {$response->status()}.");
        }

        return $response;
    }
}

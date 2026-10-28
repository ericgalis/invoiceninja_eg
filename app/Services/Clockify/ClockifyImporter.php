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

use App\Factory\TaskFactory;
use App\Models\Client;
use App\Models\Company;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Repositories\TaskRepository;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Support\Facades\Cache;
use RuntimeException;

/**
 * Pulls billable Clockify entries for a date range, rolls them up and
 * rounds them (see TimeEntryRollup), and creates one Task per unique
 * client + project + date + description.
 *
 * Clients and projects are matched to Clockify by exact name. Anything that
 * cannot be matched, or that already exists as a task, is skipped and
 * reported with the reason; nothing is ever created twice.
 */
class ClockifyImporter
{
    public const SKIP_NO_CLIENT = 'no_client';

    public const SKIP_CLIENT_NOT_FOUND = 'client_not_found';

    public const SKIP_CLIENT_AMBIGUOUS = 'client_ambiguous';

    public const SKIP_PROJECT_NOT_FOUND = 'project_not_found';

    public const SKIP_PROJECT_AMBIGUOUS = 'project_ambiguous';

    public const SKIP_ROUNDS_TO_ZERO = 'rounds_to_zero';

    public const SKIP_DUPLICATE = 'duplicate';

    public const SKIP_FAILED = 'failed';

    private DateTimeZone $timezone;

    private TimeEntryRollup $rollup;

    public function __construct(
        private Company $company,
        private User $user,
        private ?ClockifyClient $clockify = null,
    ) {
        $config = $company->clockify_config ?? [];

        if (empty($config['api_key'])) {
            throw new RuntimeException('Clockify is not configured for this company.');
        }

        $this->clockify ??= new ClockifyClient($config['api_key'], $config['workspace_id'] ?? null);
        $this->timezone = new DateTimeZone($company->timezone()->name ?? 'UTC');
        $this->rollup = new TimeEntryRollup($this->timezone, (int) ($config['week_start'] ?? 1));
    }

    /**
     * @param  string $start first day to import, Y-m-d (company timezone)
     * @param  string $end   last day to import, Y-m-d, inclusive
     * @return array{created: array<int, array<string, mixed>>, skipped: array<int, array<string, mixed>>, totals: array<string, int>}
     */
    public function run(string $start, string $end, bool $dryRun = true): array
    {
        if ($end < $start) {
            throw new RuntimeException('The end date must not be before the start date.');
        }

        $jobs = $this->rollup->process($this->billableEntries($start, $end, $nonBillable));
        $jobs = array_filter($jobs, fn (array $job): bool => $job['date'] >= $start && $job['date'] <= $end);

        $created = [];
        $skipped = [];

        $work = function () use ($jobs, $dryRun, &$created, &$skipped): void {
            $clients = $this->clientsByName();
            $projects = $this->projectsByClientAndName();
            $existing = $this->existingTasks(array_unique(array_filter(array_map(fn ($c) => $c?->id, $clients))));

            foreach ($jobs as $job) {
                $row = $this->describe($job);

                if ($reason = $this->resolve($job, $clients, $projects, $clientId, $projectId)) {
                    $skipped[] = $row + $reason;

                    continue;
                }

                if ($job['rounded_quarters'] === 0) {
                    $skipped[] = $row + ['reason' => self::SKIP_ROUNDS_TO_ZERO, 'detail' => 'Rounds to 0 hours.'];

                    continue;
                }

                $dedupeKey = self::dedupeKey($clientId, $projectId, $job['date'], $job['description']);

                if (isset($existing[$dedupeKey])) {
                    $skipped[] = $row + [
                        'reason' => self::SKIP_DUPLICATE,
                        'detail' => 'A task already exists for this client, project, date and description.',
                        'existing_task' => $existing[$dedupeKey],
                    ];

                    continue;
                }

                if ($dryRun) {
                    $created[] = $row + ['task_number' => null];
                    $existing[$dedupeKey] = '(pending)';

                    continue;
                }

                try {
                    $task = $this->createTask($job, $clientId, $projectId, $dedupeKey);
                } catch (\Throwable $e) {
                    $skipped[] = $row + ['reason' => self::SKIP_FAILED, 'detail' => $e->getMessage()];

                    continue;
                }

                $created[] = $row + ['task_number' => $task->number];
                $existing[$dedupeKey] = $task->number;
            }
        };

        if ($dryRun) {
            $work();
        } else {
            // One import per company at a time, so two runs cannot both pass the duplicate check.
            Cache::lock('clockify-import:' . $this->company->id, 300)->block(10, $work);
        }

        return [
            'created' => $created,
            'skipped' => $skipped,
            'totals' => [
                'created' => count($created),
                'skipped' => count($skipped),
                'non_billable_entries_ignored' => $nonBillable,
            ],
        ];
    }

    /**
     * Fetch the whole weeks containing the range (so a day rounds the same
     * whatever range was picked), keeping billable entries only.
     *
     * @return array<int, array<string, mixed>>
     */
    private function billableEntries(string $start, string $end, ?int &$nonBillable): array
    {
        $from = (new DateTimeImmutable($this->rollup->weekStart($start) . ' 00:00:00', $this->timezone))->setTimezone(new DateTimeZone('UTC'));
        $to = (new DateTimeImmutable($this->rollup->weekStart($end) . ' 00:00:00', $this->timezone))->modify('+7 days')->setTimezone(new DateTimeZone('UTC'));

        $entries = $this->clockify->detailedReport($from, $to);
        $billable = array_values(array_filter($entries, fn (array $e): bool => $e['billable']));
        $nonBillable = count($entries) - count($billable);

        return $billable;
    }

    /**
     * @return array<string, mixed>
     */
    private function describe(array $job): array
    {
        return [
            'date' => $job['date'],
            'client' => $job['client_name'],
            'project' => $job['project_name'] === TimeEntryRollup::NO_PROJECT ? null : $job['project_name'],
            'description' => $job['description'],
            'raw_hours' => round($job['raw_seconds'] / 3600, 2),
            'rounded_hours' => $job['rounded_hours'],
        ];
    }

    /**
     * Resolve the InvoiceNinja client and project ids by exact name. Returns
     * a skip reason array when the job cannot be placed, null when it can.
     *
     * @param  array<string, Client|null>              $clients
     * @param  array<string, Project|null>             $projects
     * @return array<string, string>|null
     */
    private function resolve(array $job, array $clients, array $projects, ?int &$clientId, ?int &$projectId): ?array
    {
        $clientId = $projectId = null;

        if ($job['client_name'] === TimeEntryRollup::NO_CLIENT) {
            return ['reason' => self::SKIP_NO_CLIENT, 'detail' => 'The Clockify entry has no client.'];
        }

        if (! array_key_exists($job['client_name'], $clients)) {
            return ['reason' => self::SKIP_CLIENT_NOT_FOUND, 'detail' => "No client named \"{$job['client_name']}\" in InvoiceNinja."];
        }

        if ($clients[$job['client_name']] === null) {
            return ['reason' => self::SKIP_CLIENT_AMBIGUOUS, 'detail' => "More than one client is named \"{$job['client_name']}\"."];
        }

        $clientId = $clients[$job['client_name']]->id;

        if ($job['project_name'] === TimeEntryRollup::NO_PROJECT) {
            return null;
        }

        $key = $clientId . "\x1f" . $job['project_name'];

        if (! array_key_exists($key, $projects)) {
            return ['reason' => self::SKIP_PROJECT_NOT_FOUND, 'detail' => "No project named \"{$job['project_name']}\" for this client in InvoiceNinja."];
        }

        if ($projects[$key] === null) {
            return ['reason' => self::SKIP_PROJECT_AMBIGUOUS, 'detail' => "More than one project is named \"{$job['project_name']}\" for this client."];
        }

        $projectId = $projects[$key]->id;

        return null;
    }

    /**
     * @return array<string, Client|null> name => client, null when the name is not unique
     */
    private function clientsByName(): array
    {
        $map = [];

        foreach (Client::query()->where('company_id', $this->company->id)->get(['id', 'name']) as $client) {
            $name = trim((string) $client->name);
            $map[$name] = array_key_exists($name, $map) ? null : $client;
        }

        return $map;
    }

    /**
     * @return array<string, Project|null> "clientId<US>name" => project, null when not unique
     */
    private function projectsByClientAndName(): array
    {
        $map = [];

        foreach (Project::query()->where('company_id', $this->company->id)->get(['id', 'name', 'client_id']) as $project) {
            $key = $project->client_id . "\x1f" . trim((string) $project->name);
            $map[$key] = array_key_exists($key, $map) ? null : $project;
        }

        return $map;
    }

    /**
     * Existing tasks for the matched clients, keyed by dedupe key. Includes
     * manually created tasks: the local date of the first time-log entry plus
     * the description (without any "Ddd M/D - " prefix) decide a match.
     *
     * @param  array<int, int> $clientIds
     * @return array<string, string> dedupe key => task number
     */
    private function existingTasks(array $clientIds): array
    {
        $existing = [];

        // Archived tasks still count (often already invoiced); deleted ones do not.
        Task::withTrashed()
            ->where('company_id', $this->company->id)
            ->where('is_deleted', false)
            ->whereIn('client_id', $clientIds)
            ->get(['id', 'number', 'client_id', 'project_id', 'description', 'time_log', 'meta'])
            ->each(function (Task $task) use (&$existing): void {
                if ($task->meta?->clockify_key) {
                    $existing[$task->meta->clockify_key] = (string) $task->number;
                }

                $log = json_decode($task->time_log ?: '[]', true);

                if (empty($log[0][0])) {
                    return;
                }

                $date = (new DateTimeImmutable('@' . (int) $log[0][0]))->setTimezone($this->timezone)->format('Y-m-d');
                $description = preg_replace('/^\w{3} \d{1,2}\/\d{1,2} - /', '', (string) $task->description);

                $existing[self::dedupeKey($task->client_id, $task->project_id, $date, $description)] = (string) $task->number;
            });

        return $existing;
    }

    private static function dedupeKey(?int $clientId, ?int $projectId, string $date, string $description): string
    {
        return sha1(implode('|', [$clientId, $projectId, $date, mb_strtolower(trim($description))]));
    }

    private function createTask(array $job, int $clientId, ?int $projectId, string $dedupeKey): Task
    {
        $task = TaskFactory::create($this->company->id, $this->user->id);

        $label = $job['start']->format('D n/j') . ' - ' . $job['description'];

        return (new TaskRepository())->save([
            'client_id' => $clientId,
            'project_id' => $projectId,
            'description' => $label,
            'time_log' => json_encode([[
                $job['start']->getTimestamp(),
                $job['end']->getTimestamp(),
                $job['description'],
                true,
            ]]),
            'meta' => ['clockify_key' => $dedupeKey],
        ], $task);
    }
}

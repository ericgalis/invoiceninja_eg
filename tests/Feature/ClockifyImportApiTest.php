<?php

namespace Tests\Feature;

use App\Models\Task;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Http;
use Tests\MockAccountData;
use Tests\TestCase;

class ClockifyImportApiTest extends TestCase
{
    use DatabaseTransactions;
    use MockAccountData;

    protected function setUp(): void
    {
        parent::setUp();

        $this->makeTestData();

        $settings = $this->company->settings;
        $settings->timezone_id = (string) app('timezones')->firstWhere('name', 'America/New_York')->id;
        $this->company->settings = $settings;
        $this->company->clockify_config = ['api_key' => 'test-key', 'workspace_id' => 'ws1'];
        $this->company->save();

        $this->client->name = 'Acme Test Co';
        $this->client->save();
        $this->project->name = 'Alpha';
        $this->project->save();
    }

    /**
     * @return array<string, string>
     */
    private function apiHeaders(): array
    {
        return [
            'X-API-SECRET' => config('ninja.api_secret'),
            'X-API-TOKEN' => $this->token,
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     */
    private function fakeClockify(array $rows): void
    {
        Http::fake(['reports.api.clockify.me/*' => Http::response(['timeentries' => $rows])]);
    }

    private function row(string $start, int $minutes, string $description, ?string $client = 'Acme Test Co', ?string $project = 'Alpha', bool $billable = true): array
    {
        $s = new \DateTimeImmutable($start);

        return [
            '_id' => md5($start . $description),
            'description' => $description,
            'billable' => $billable,
            'clientName' => $client,
            'projectName' => $project,
            'taskName' => null,
            'timeInterval' => [
                'start' => $s->format('c'),
                'end' => $s->modify("+{$minutes} minutes")->format('c'),
            ],
        ];
    }

    private function import(bool $dryRun = false, string $start = '2026-08-24', string $end = '2026-08-30')
    {
        return $this->withHeaders($this->apiHeaders())
            ->postJson('/api/v1/clockify/import', ['start' => $start, 'end' => $end, 'dry_run' => $dryRun]);
    }

    public function testSettingsNeverReturnTheApiKey(): void
    {
        $response = $this->withHeaders($this->apiHeaders())->getJson('/api/v1/clockify/settings');

        $response->assertOk()->assertJsonPath('data.has_key', true);
        $this->assertStringNotContainsString('test-key', $response->getContent());
    }

    public function testDryRunCreatesNothing(): void
    {
        $this->fakeClockify([$this->row('2026-08-24T09:00:00-04:00', 50, 'Review logs')]);

        $response = $this->import(dryRun: true);

        $response->assertOk()->assertJsonPath('data.totals.created', 1);
        $this->assertSame(0, Task::where('description', 'like', '%Review logs')->count());
    }

    public function testImportCreatesRoundedTaskAndSecondRunCreatesNothing(): void
    {
        $this->fakeClockify([
            $this->row('2026-08-24T09:00:00-04:00', 50, 'Review logs'),
            $this->row('2026-08-24T13:00:00-04:00', 10, 'review LOGS'),
        ]);

        $this->import()->assertOk()->assertJsonPath('data.totals.created', 1);

        $task = Task::where('description', 'like', '%Review logs')->firstOrFail();
        $this->assertSame('Mon 8/24 - Review logs', $task->description);
        $this->assertSame($this->client->id, $task->client_id);
        $this->assertSame($this->project->id, $task->project_id);

        $log = json_decode($task->time_log, true);
        $this->assertSame(3600, $log[0][1] - $log[0][0]);
        $this->assertTrue($log[0][3]);
        $this->assertNotEmpty($task->meta->clockify_key);

        $second = $this->import();
        $second->assertOk()->assertJsonPath('data.totals.created', 0);
        $second->assertJsonPath('data.skipped.0.reason', 'duplicate');
        $this->assertSame(1, Task::where('description', 'like', '%Review logs')->count());
    }

    public function testManuallyCreatedTaskWithDifferentTimesBlocksImport(): void
    {
        $this->fakeClockify([$this->row('2026-08-24T09:00:00-04:00', 60, 'Review logs')]);

        $manual = \App\Factory\TaskFactory::create($this->company->id, $this->user->id);
        $manual->client_id = $this->client->id;
        $manual->project_id = $this->project->id;
        $manual->description = 'review logs';
        $manual->number = 'MANUAL-1';
        $start = (new \DateTimeImmutable('2026-08-24T15:00:00-04:00'))->getTimestamp();
        $manual->time_log = json_encode([[$start, $start + 1800, '', true]]);
        $manual->saveQuietly();

        $response = $this->import();

        $response->assertOk()->assertJsonPath('data.totals.created', 0);
        $response->assertJsonPath('data.skipped.0.reason', 'duplicate');
        $response->assertJsonPath('data.skipped.0.existing_task', 'MANUAL-1');
    }

    public function testEveryUnplaceableEntryIsSkippedWithAReason(): void
    {
        $this->fakeClockify([
            $this->row('2026-08-24T09:00:00-04:00', 30, 'Unknown client', 'Nobody Inc'),
            $this->row('2026-08-24T10:00:00-04:00', 30, 'Unknown project', 'Acme Test Co', 'Missing'),
            $this->row('2026-08-24T11:00:00-04:00', 30, 'No client', null, null),
            $this->row('2026-08-24T12:00:00-04:00', 2, 'Tiny'),
            $this->row('2026-08-24T14:00:00-04:00', 30, 'Not billable', 'Acme Test Co', 'Alpha', false),
        ]);

        $response = $this->import();

        $response->assertOk()->assertJsonPath('data.totals.created', 0);
        $response->assertJsonPath('data.totals.non_billable_entries_ignored', 1);
        $reasons = collect($response->json('data.skipped'))->pluck('reason', 'description')->all();

        $this->assertSame('client_not_found', $reasons['Unknown client']);
        $this->assertSame('project_not_found', $reasons['Unknown project']);
        $this->assertSame('no_client', $reasons['No client']);
        $this->assertSame('rounds_to_zero', $reasons['Tiny']);
    }

    public function testNoClockifyProjectCreatesClientLevelTask(): void
    {
        $this->fakeClockify([$this->row('2026-08-25T09:00:00-04:00', 30, 'General work', 'Acme Test Co', null)]);

        $this->import()->assertOk()->assertJsonPath('data.totals.created', 1);

        $task = Task::where('description', 'Tue 8/25 - General work')->firstOrFail();
        $this->assertNull($task->project_id);
    }

    public function testDaysOutsideTheRangeAreNotCreated(): void
    {
        $this->fakeClockify([
            $this->row('2026-08-24T09:00:00-04:00', 30, 'In range'),
            $this->row('2026-08-26T09:00:00-04:00', 30, 'Out of range'),
        ]);

        $this->import(start: '2026-08-24', end: '2026-08-24')->assertOk()->assertJsonPath('data.totals.created', 1);

        $this->assertSame(0, Task::where('description', 'like', '%Out of range')->count());
    }

    public function testRequiresValidDatesAndConfiguration(): void
    {
        $this->withHeaders($this->apiHeaders())
            ->postJson('/api/v1/clockify/import', ['start' => '2026-08-30', 'end' => '2026-08-24'])
            ->assertStatus(422);

        $this->company->clockify_config = null;
        $this->company->save();

        $this->import()->assertStatus(422);
    }
}

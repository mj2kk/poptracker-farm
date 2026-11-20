<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class FarmDashboardTest extends TestCase
{
    public function test_guest_must_sign_in_for_both_collection_and_technical_operations(): void
    {
        $this->get('/dashboard/phones')->assertRedirect('/');
        $this->getJson('/dashboard/phones/production')->assertUnauthorized();
        $this->postJson('/dashboard/phones/production/jobs')->assertUnauthorized();
        $this->postJson('/dashboard/phones/production/evaluations')->assertUnauthorized();
        $this->postJson('/dashboard/phones/production/queue')->assertUnauthorized();
        $this->postJson('/dashboard/phones/production/jobs/'.Str::uuid().'/cancel')->assertUnauthorized();
        $this->getJson('/dashboard/phones/snapshot')->assertUnauthorized();
        $this->postJson('/dashboard/phones/tasks')->assertUnauthorized();
    }

    public function test_developer_id_without_operator_access_cannot_manage_the_farm(): void
    {
        config(['farm.operator_ids' => '401', 'farm.developer_ids' => '402']);
        $this->actingAs(User::factory()->make(['id' => 402]));

        $this->get('/dashboard')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->where('farmOperator', false));
        $this->get('/dashboard/phones')->assertForbidden();
        $this->getJson('/dashboard/phones/production')->assertForbidden();
        $this->postJson('/dashboard/phones/production/jobs', ['type' => 'IMPORT_FEED'])->assertForbidden();
        $this->postJson('/dashboard/phones/production/evaluations')->assertForbidden();
        $this->postJson('/dashboard/phones/production/queue', ['paused' => true])->assertForbidden();
        $this->postJson('/dashboard/phones/production/jobs/'.Str::uuid().'/cancel')->assertForbidden();
        $this->getJson('/dashboard/phones/snapshot')->assertForbidden();
        $this->postJson('/dashboard/phones/tasks')->assertForbidden();
    }

    public function test_production_operator_can_open_collection_but_cannot_use_technical_tools(): void
    {
        config(['farm.operator_ids' => '401, 402', 'farm.developer_ids' => '401']);
        $this->actingAs(User::factory()->make(['id' => 402]));

        $this->get('/dashboard')->assertInertia(fn (AssertableInertia $page) => $page->where('farmOperator', true));
        $this->get('/dashboard/phones')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->component('farm-dashboard')->where('canDevelop', false));
        $this->getJson('/dashboard/phones/snapshot')->assertForbidden();
        $this->postJson('/dashboard/phones/tasks', ['target' => 'any', 'type' => 'PING', 'payload' => []])->assertForbidden();

        $this->postJson('/dashboard/phones/production/jobs', ['type' => 'PING'])->assertUnprocessable()->assertJsonValidationErrors('type');
        $this->postJson('/dashboard/phones/production/jobs', ['type' => 'EVALUATE_VIDEO'])->assertUnprocessable()->assertJsonValidationErrors('type');
        $this->postJson('/dashboard/phones/production/queue', ['paused' => 'arbitrary'])->assertUnprocessable()->assertJsonValidationErrors('paused');
        $this->getJson('/dashboard/phones/production?type[]=INVALID')->assertUnprocessable()->assertJsonValidationErrors('type');
        $this->postJson('/dashboard/phones/production/jobs/non-uuid/cancel')->assertNotFound();
    }

    public function test_geo_preview_is_operator_only_and_cannot_create_a_task(): void
    {
        $url = '/dashboard/phones/production?type=VALIDATE_GEO&preview=geo';
        config(['farm.operator_ids' => '401']);
        $this->getJson($url)->assertUnauthorized();
        $this->actingAs(User::factory()->make(['id' => 402]))->getJson($url)->assertForbidden();
        $this->actingAs(User::factory()->make(['id' => 401]));
        $this->getJson('/dashboard/phones/production?type=IMPORT_FEED&preview=geo')->assertUnprocessable();
        Process::fake(fn () => Process::result(output: '{"targets":[],"controls":[]}'));
        $this->getJson($url)->assertOk()->assertExactJson(['targets' => [], 'controls' => []]);
        Process::assertRan(fn (PendingProcess $process) => json_decode($process->input, true) === ['action' => 'geo_preview']);
    }

    public function test_fresh_geo_submission_uses_only_the_prepared_scope_and_operator_device(): void
    {
        config(['farm.operator_ids' => '401']);
        $url = '/dashboard/phones/production/jobs';
        $geo = ['device_id' => (string) Str::uuid(), 'request_id' => (string) Str::uuid(), 'scope_sha256' => str_repeat('a', 64)];
        $input = ['type' => 'VALIDATE_GEO'] + $geo;
        Process::fake(fn () => Process::result(output: '{"status":"queued"}'));
        $this->postJson($url, $input)->assertUnauthorized();
        $this->actingAs(User::factory()->make(['id' => 402]))->postJson($url, $input)->assertForbidden();
        $this->actingAs(User::factory()->make(['id' => 401]));
        $this->postJson($url, ['scope_sha256' => 'bad'] + $input)->assertUnprocessable();
        Process::assertNothingRan();
        $this->postJson($url, $input + ['members' => ['injected']])->assertCreated();
        Process::assertRan(fn (PendingProcess $process) => json_decode($process->input, true) === ['action' => 'fresh_geo_submit', ...$geo]);
    }

    public function test_developer_with_operator_access_can_use_the_existing_technical_form(): void
    {
        config(['farm.operator_ids' => '401, 402', 'farm.developer_ids' => '402']);
        $this->actingAs(User::factory()->make(['id' => 402]));

        $this->get('/dashboard/phones')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->component('farm-dashboard')->where('canDevelop', true));
        $this->postJson('/dashboard/phones/tasks', ['payload' => 'wrong shape'])->assertUnprocessable()->assertJsonValidationErrors(['target', 'type', 'payload']);
    }

    public function test_apify_fields_are_accepted_together_only_for_feed_and_forwarded_to_the_queue(): void
    {
        config(['farm.operator_ids' => '401']);
        $this->actingAs(User::factory()->make(['id' => 401]));
        Process::fake(fn () => Process::result(output: '{"queued":1}'));
        $path = '/dashboard/phones/production/jobs';
        $id = (string) Str::uuid();
        $feed = ['type' => 'IMPORT_FEED'];
        $this->postJson($path, $feed + ['source' => 'apify'])->assertUnprocessable()->assertJsonValidationErrors('request_id');
        $this->postJson($path, $feed + ['request_id' => $id])->assertUnprocessable()->assertJsonValidationErrors('source');
        $this->postJson($path, $feed + ['source' => 'apify', 'request_id' => 'invalid'])->assertUnprocessable()->assertJsonValidationErrors('request_id');
        $this->postJson($path, $feed + ['source' => 'other', 'request_id' => $id])->assertUnprocessable()->assertJsonValidationErrors('source');
        $this->postJson($path, ['type' => 'REFRESH_METRICS', 'source' => 'apify', 'request_id' => $id])->assertUnprocessable()->assertJsonValidationErrors(['source', 'request_id']);
        Process::assertNothingRan();
        $apify = $feed + ['source' => 'apify', 'request_id' => $id];
        foreach ([$feed, $apify, $apify + ['requested_count' => 10000, 'max_charge_cents' => 1000]] as $payload) {
            $this->postJson($path, $payload)->assertCreated()->assertJsonPath('queued', 1);
            Process::assertRan(fn (PendingProcess $process) => json_decode($process->input, true) === ['action' => 'production_submit', ...$payload]);
        }
    }

    public function test_feed_budget_is_a_pair_of_integers_only_for_apify(): void
    {
        config(['farm.operator_ids' => '401']);
        $this->actingAs(User::factory()->make(['id' => 401]));
        Process::fake();
        $path = '/dashboard/phones/production/jobs';
        $feed = ['type' => 'IMPORT_FEED', 'source' => 'apify', 'request_id' => (string) Str::uuid()];
        $budget = ['requested_count' => 100, 'max_charge_cents' => 10];
        foreach ([['type' => 'IMPORT_FEED'], ['type' => 'COLLECT_COMMENTS']] as $command) {
            $this->postJson($path, $command + $budget)->assertUnprocessable()->assertJsonValidationErrors(array_keys($budget));
        }
        foreach ($budget as $field => $value) {
            $this->postJson($path, $feed + array_diff_key($budget, [$field => $value]))->assertUnprocessable()->assertJsonValidationErrors($field);
        }
        foreach ([99, 10001, 512.5, true, '500'] as $count) {
            $this->postJson($path, $feed + ['requested_count' => $count] + $budget)->assertUnprocessable()->assertJsonValidationErrors('requested_count');
        }
        foreach ([0, -1, 1.5, true, '10'] as $cents) {
            $this->postJson($path, $feed + ['max_charge_cents' => $cents] + $budget)->assertUnprocessable()->assertJsonValidationErrors('max_charge_cents');
        }
        Process::assertNothingRan();
    }
}

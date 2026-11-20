<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Process;
use Inertia\Inertia;
use Inertia\Response;

final class FarmDashboardController extends Controller
{
    public static function allowed(Request $request): bool
    {
        $ids = array_filter(array_map('trim', explode(',', (string) config('farm.operator_ids'))));

        return $request->user() && in_array((string) $request->user()->getAuthIdentifier(), $ids, true);
    }

    public static function canDevelop(Request $request): bool
    {
        $ids = array_map('trim', explode(',', (string) config('farm.developer_ids')));

        return self::allowed($request) && in_array((string) $request->user()->getAuthIdentifier(), $ids, true);
    }

    private function farm(Request $request, array $input): array
    {
        abort_unless(self::allowed($request), 403);
        $process = Process::timeout(14)->input(json_encode($input, JSON_THROW_ON_ERROR))
            ->run(['/usr/bin/sudo', '-n', '-u', 'popfarm', '--', '/usr/bin/php', '/PopTracker Farm/server/bin/dashboard.php']);
        $data = json_decode($process->output(), true);
        if ($process->exitCode() === 2 && isset($data['status'])) {
            abort(in_array($data['status'], [404, 409, 422], true) ? $data['status'] : 422, $data['status'] === 409 ? ($data['error'] ?? 'Команда недоступна.') : 'Проверьте устройство и параметры задания.');
        }
        abort_unless($process->successful() && is_array($data), 503, 'Farm временно недоступна.');

        return $data;
    }

    public function index(Request $request): Response
    {
        abort_unless(self::allowed($request), 403);

        return Inertia::render('farm-dashboard', ['canDevelop' => self::canDevelop($request)]);
    }

    public function snapshot(Request $request): JsonResponse
    {
        abort_unless(self::canDevelop($request), 403);

        return response()->json($this->farm($request, ['action' => 'snapshot']));
    }

    public function store(Request $request): JsonResponse
    {
        abort_unless(self::canDevelop($request), 403);
        $v = $request->validate(['target' => ['required', 'string', 'max:36'], 'type' => ['required', 'string', 'max:32'],
            'payload' => ['present', 'array', 'max:2']]);

        return response()->json($this->farm($request, ['action' => 'create', 'target' => $v['target'],
            'type' => $v['type'], 'payload' => (object) $v['payload']]), 201);
    }

    public function production(Request $request): JsonResponse
    {
        abort_unless(self::allowed($request), 403);
        if ($request->has('preview')) {
            $request->validate(['preview' => ['required', 'string', 'in:geo'], 'type' => ['required', 'string', 'in:VALIDATE_GEO']]);

            return response()->json($this->farm($request, ['action' => 'geo_preview']));
        }

        $filters = $request->validate(['type' => ['nullable', 'string', 'max:32'], 'status' => ['nullable', 'string', 'max:16']]);

        return response()->json($this->farm($request, ['action' => 'production', ...$filters]) + ['can_develop' => self::canDevelop($request)]);
    }

    public function submit(Request $request): JsonResponse
    {
        abort_unless(self::allowed($request), 403);
        if ($request->input('type') === 'VALIDATE_GEO' && $request->has('scope_sha256')) {
            $geo = $request->validate([
                'device_id' => ['required', 'uuid'], 'request_id' => ['required', 'uuid:4'],
                'scope_sha256' => ['required', 'string', 'regex:/^[a-f0-9]{64}$/D'],
            ]);

            return response()->json($this->farm($request, ['action' => 'fresh_geo_submit', ...$geo]), 201);
        }
        $input = $request->validate([
            'type' => ['required', 'string', 'in:IMPORT_FEED,VALIDATE_GEO,DISCOVER_VIDEOS,REFRESH_METRICS,COLLECT_COMMENTS'],
            'source' => ['missing_unless:type,IMPORT_FEED', 'required_with:request_id', 'string', 'in:apify'],
            'request_id' => ['missing_unless:type,IMPORT_FEED', 'required_with:source', 'uuid:4'],
            'requested_count' => ['missing_unless:source,apify', 'required_with:max_charge_cents', 'integer:strict', 'between:100,10000'],
            'max_charge_cents' => ['missing_unless:source,apify', 'required_with:requested_count', 'integer:strict', 'min:1'],
        ]);

        return response()->json($this->farm($request, ['action' => 'production_submit', ...$input]), 201);
    }

    public function evaluate(Request $request): JsonResponse
    {
        return response()->json($this->farm($request, ['action' => 'production_submit', 'type' => 'EVALUATE_VIDEO']), 201);
    }

    public function queue(Request $request): JsonResponse
    {
        abort_unless(self::allowed($request), 403);
        $request->validate(['paused' => ['required', 'boolean']]);

        return response()->json($this->farm($request, ['action' => 'production_control', 'paused' => $request->boolean('paused')]));
    }

    public function cancel(Request $request, string $id): JsonResponse
    {
        return response()->json($this->farm($request, ['action' => 'production_cancel', 'id' => $id]));
    }
}

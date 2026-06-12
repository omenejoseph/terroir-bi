<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Actions\Ai\CommitAiImportAction;
use App\DataTransferObjects\AiImportData;
use App\DataTransferObjects\AiImportLineData;
use App\Enums\AiImportLineStatus;
use App\Enums\AiImportStatus;
use App\Enums\AiImportType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Ai\StoreAiImportRequest;
use App\Http\Requests\Ai\UpdateAiImportLineRequest;
use App\Jobs\ProcessAiImportJob;
use App\Models\AiImport;
use App\Models\User;
use App\Support\Ai\AiModelConfig;
use App\Tenancy\Contracts\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Tenant-facing AI data-entry endpoints: upload → async extraction → review →
 * commit. Read routes require `ai.use`; write routes require `ai.manage`
 * (see routes/api.php). Module gating (Module::AiDataEntry) is enforced by the
 * tenant middleware group via the `ai-imports` path prefix.
 */
class AiImportController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $imports = AiImport::query()
            ->withCount([
                'lines as lines_total',
                'lines as lines_pending' => fn ($q) => $q->where('status', AiImportLineStatus::Pending->value),
                'lines as lines_committed' => fn ($q) => $q->where('status', AiImportLineStatus::Committed->value),
            ])
            ->latest()
            ->limit(100)
            ->get();

        return response()->json([
            'data' => $imports->map(fn (AiImport $i) => array_merge(AiImportData::fromModel($i)->toArray(), [
                'lines_total' => (int) $i->getAttribute('lines_total'),
                'lines_pending' => (int) $i->getAttribute('lines_pending'),
                'lines_committed' => (int) $i->getAttribute('lines_committed'),
            ]))->all(),
        ]);
    }

    public function store(StoreAiImportRequest $request, AiModelConfig $models, TenantContext $tenant): JsonResponse
    {
        $type = AiImportType::from((string) $request->validated('type'));

        if (! $models->capabilityEnabled($type->capability())) {
            return response()->json([
                'message' => 'AI features are not enabled for this document type.',
                'code' => 'ai_disabled',
            ], 403);
        }

        $import = AiImport::create([
            'type' => $type,
            'status' => AiImportStatus::Uploaded,
            'source_object_key' => $request->validated('object_key'),
            'source_filename' => $request->validated('filename'),
            'source_mime' => $request->validated('mime'),
            'created_by_id' => $this->userId($request),
        ]);

        ProcessAiImportJob::dispatch($import->getKey(), $tenant->id());

        return response()->json(['data' => AiImportData::fromModel($import)->toArray()], 201);
    }

    public function show(AiImport $aiImport): JsonResponse
    {
        $aiImport->load('lines');

        return response()->json(['data' => AiImportData::fromModel($aiImport)->toArray()]);
    }

    public function updateLine(UpdateAiImportLineRequest $request, AiImport $aiImport, string $line): JsonResponse
    {
        $model = $aiImport->lines()->findOrFail($line);

        $attributes = ['status' => AiImportLineStatus::from((string) $request->validated('status'))];

        if ($request->has('edited_payload')) {
            $attributes['edited_payload'] = $request->validated('edited_payload');
        }

        $model->update($attributes);

        return response()->json(['data' => AiImportLineData::fromModel($model)->toArray()]);
    }

    public function commit(Request $request, AiImport $aiImport, CommitAiImportAction $action): JsonResponse
    {
        $summary = $action->execute($aiImport, $this->userId($request));

        $aiImport->refresh()->load('lines');

        return response()->json([
            'data' => AiImportData::fromModel($aiImport)->toArray(),
            'meta' => $summary,
        ]);
    }

    private function userId(Request $request): string
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        return $user->getKey();
    }

    public function destroy(AiImport $aiImport): JsonResponse
    {
        $aiImport->delete();

        return response()->json(null, 204);
    }
}

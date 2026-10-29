<?php

namespace App\Http\Controllers\Api\V1\Sender;

use App\Http\Controllers\Controller;
use App\Http\Requests\Sender\SenderRecipientRequest;
use App\Http\Resources\Sender\SenderRecipientResource;
use App\Http\Responses\ApiResponse;
use App\Models\SenderRecipient;
use App\Services\Sender\SenderService;
use App\Support\Api\EditGuard;
use App\Support\Api\Paging;
use App\Support\Sender\SenderRecipientSummary;
use App\Support\Tenancy\CurrentBusiness;
use App\Support\Time\BusinessClock;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** /sender-recipients (Family & Recipients). PRIVATE. ?q= name or number; no date filter (spec). */
class RecipientController extends Controller
{
    public function __construct(private SenderService $service, private SenderRecipientSummary $summaries, private CurrentBusiness $current)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $query = SenderRecipient::query();
        if ($request->query('trashed') === 'only') {
            $query->onlyTrashed();
        } elseif ($request->query('archived') === 'only') {
            $query->where('archived', true);
        } elseif ($request->query('archived') !== 'all') {
            $query->where('archived', false);
        }
        if (is_string($q = $request->query('q')) && trim($q) !== '') {
            $like   = Paging::like($q);
            $digits = preg_replace('/\D/', '', $q);
            $query->where(function ($w) use ($like, $digits) {
                $w->where('name', 'like', $like)->orWhere('phone', 'like', $like);
                if (strlen($digits) >= 3) {
                    $w->orWhere('phone_normalized', 'like', '%' . preg_replace('/^880/', '0', $digits) . '%');
                }
            });
        }

        // Newest first for v1.1.290; explicit name sort remains available.
        if ($request->query('sort') === 'name') {
            $query->orderBy('name')->orderBy('id');
        } else {
            $query->orderByDesc('created_at')->orderByDesc('id');
        }

        $page = Paging::paginate($query, $request);

        $summaries = $this->summaries->forRecipients($page->getCollection(), BusinessClock::for($this->current->get()));

        return ApiResponse::success(Paging::shape($page, fn ($m) => [
            ...SenderRecipientResource::make($m)->resolve($request),
            'summary' => $summaries[$m->getKey()],
        ]));
    }

    public function store(SenderRecipientRequest $request): JsonResponse
    {
        [$r, $created] = $this->service->createRecipient($request->validated());

        return ApiResponse::success(SenderRecipientResource::make($r)->resolve($request), $created ? 'Family member saved.' : 'Already saved.', $created ? 201 : 200);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        $recipient = SenderRecipient::findByUuidOrFail($id, withTrashed: true);
        $summaries = $this->summaries->forRecipients(new \Illuminate\Database\Eloquent\Collection([$recipient]), BusinessClock::for($this->current->get()));

        return ApiResponse::success([
            ...SenderRecipientResource::make($recipient)->resolve($request),
            'summary' => $summaries[$recipient->getKey()],
        ]);
    }

    public function update(SenderRecipientRequest $request, string $id): JsonResponse
    {
        $r = SenderRecipient::findByUuidOrFail($id);
        EditGuard::assertFresh($r, $request->input('base_updated_at'), fn ($x) => SenderRecipientResource::make($x)->resolve($request));

        return ApiResponse::success(SenderRecipientResource::make($this->service->updateRecipient($r, $request->validated()))->resolve($request), 'Family member updated.');
    }

    public function archive(Request $request, string $id): JsonResponse
    {
        $recipient = $this->service->setRecipientArchived(SenderRecipient::findByUuidOrFail($id), true);

        return ApiResponse::success(SenderRecipientResource::make($recipient)->resolve($request), 'Family member archived.');
    }

    public function unarchive(Request $request, string $id): JsonResponse
    {
        $recipient = $this->service->setRecipientArchived(SenderRecipient::findByUuidOrFail($id), false);

        return ApiResponse::success(SenderRecipientResource::make($recipient)->resolve($request), 'Family member returned to the active list.');
    }

    /** A new, unused family profile can be deleted permanently and releases its lifetime slot. */
    public function deleteNew(string $id): JsonResponse
    {
        $this->service->permanentlyDeleteRecipient(SenderRecipient::findByUuidOrFail($id, withTrashed: true));

        return ApiResponse::success(null, 'New family member permanently deleted.');
    }

    public function destroy(string $id): JsonResponse
    {
        SenderRecipient::findByUuidOrFail($id)->delete();   // old remittances keep the name they were sent with

        return ApiResponse::success(null, 'Family member deleted.');
    }

    public function restore(Request $request, string $id): JsonResponse
    {
        return ApiResponse::success(SenderRecipientResource::make($this->service->restoreRecipient(SenderRecipient::findByUuidOrFail($id, withTrashed: true)))->resolve($request), 'Family member restored.');
    }
}

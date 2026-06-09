<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Actions\Orders\AddOrderCommentAction;
use App\Authorization\MembershipContext;
use App\Http\Controllers\Controller;
use App\Http\Requests\Orders\AddOrderCommentRequest;
use App\Http\Requests\Orders\UpdateOrderCommentRequest;
use App\Models\Order;
use App\Models\OrderNote;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OrderCommentController extends Controller
{
    public function __construct(private readonly MembershipContext $membership) {}

    public function store(AddOrderCommentRequest $request, Order $order, AddOrderCommentAction $action): JsonResponse
    {
        /** @var list<string> $mentions */
        $mentions = array_values((array) $request->validated('mentions', []));
        $note = $action->execute($order, (string) $request->validated('content'), $mentions, $this->userId($request));

        return response()->json(['data' => $this->present($note)], 201);
    }

    public function update(UpdateOrderCommentRequest $request, OrderNote $orderNote): JsonResponse
    {
        $this->ensureCanModify($orderNote, $request);

        $orderNote->content = (string) $request->validated('content');
        $orderNote->save();

        return response()->json(['data' => $this->present($orderNote)]);
    }

    public function destroy(Request $request, OrderNote $orderNote): JsonResponse
    {
        $this->ensureCanModify($orderNote, $request);
        $orderNote->delete();

        return response()->json(status: 204);
    }

    private function ensureCanModify(OrderNote $note, Request $request): void
    {
        $isAuthor = $note->author_id === $this->userId($request);
        $isAdmin = $this->membership->current()?->isAdmin() ?? false;

        abort_unless($isAuthor || $isAdmin, 403, 'Only the author or an admin can change this comment.');
    }

    /**
     * @return array<string, mixed>
     */
    private function present(OrderNote $note): array
    {
        return [
            'id' => $note->getKey(),
            'order_id' => $note->order_id,
            'content' => $note->content,
            'author_id' => $note->author_id,
            'created_at' => $note->created_at?->toIso8601String(),
        ];
    }

    private function userId(Request $request): string
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        return $user->getKey();
    }
}

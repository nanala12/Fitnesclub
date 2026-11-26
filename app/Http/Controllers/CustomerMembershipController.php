<?php

namespace App\Http\Controllers;

use App\Models\Membership;
use App\Support\OrderSummary;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

class CustomerMembershipController extends Controller
{
    public function index(Request $request, OrderSummary $summary): Response
    {
        $request->validate(['page' => ['sometimes', 'integer', 'min:1']]);
        $memberships = $this->owned($request)->with(['order.payments', 'order.membership'])
            ->orderByDesc('id')->paginate(12);

        return $this->page($request, 'memberships/Index', ['memberships' => $memberships->through(
            fn (Membership $membership): array => $this->data($membership, $request, $summary))]);
    }

    public function show(int $membership, Request $request, OrderSummary $summary): Response
    {
        $request->validate(['page' => ['sometimes', 'integer', 'min:1']]);
        $member = $this->owned($request)->with(['order.payments', 'order.membership', 'activatedBy:id,name'])->findOrFail($membership);
        abort_unless(Gate::forUser($request->user()->fresh())->allows('viewOwn', $member), 404);
        $entries = $member->entries()->with('recordedBy:id,name')->orderByDesc('used_at')->orderByDesc('id')->paginate(20);

        return $this->page($request, 'memberships/Show', [
            'membership' => $this->data($member, $request, $summary),
            'activatedAt' => $member->activated_at?->toISOString(), 'activatedBy' => $member->activatedBy?->name,
            'entries' => $entries->through(fn ($entry): array => ['id' => $entry->id, 'usedAt' => $entry->used_at->toISOString(), 'actor' => $entry->recordedBy->name]),
        ]);
    }

    /** @return Builder<Membership> */
    private function owned(Request $request): Builder
    {
        $actor = $request->user()->fresh();
        abort_if($actor === null || $actor->isSuspended(), 403);

        return Membership::query()->where(fn ($query) => $query->where('holder_user_id', $actor->id)
            ->orWhereHas('order', fn ($orders) => $orders->where('purchaser_user_id', $actor->id)));
    }

    /** @return array<string, mixed> */
    private function data(Membership $membership, Request $request, OrderSummary $summary): array
    {
        $order = $summary->make($membership->order, false, true);

        return ['id' => $membership->id, 'holderName' => $membership->holder_first_name.' '.$membership->holder_last_name,
            'forAnotherPerson' => $membership->holder_user_id !== $request->user()->id,
            'planName' => $order['planName'], 'state' => $order['state'], 'startsOn' => $order['startsOn'], 'expiresOn' => $order['expiresOn'],
            'entriesRemaining' => $order['entriesRemaining'], 'purchasedAt' => $order['orderedAt'], 'pdfUrl' => $order['pdfUrl'],
            'showUrl' => route('memberships.show', $membership->id, absolute: false)];
    }

    /** @param array<string, mixed> $props */
    private function page(Request $request, string $name, array $props): Response
    {
        Inertia::encryptHistory();
        $response = Inertia::render($name, $props)->toResponse($request);
        $response->headers->add(['Cache-Control' => 'private, no-store', 'Referrer-Policy' => 'no-referrer', 'X-Robots-Tag' => 'noindex, nofollow']);

        return $response;
    }
}

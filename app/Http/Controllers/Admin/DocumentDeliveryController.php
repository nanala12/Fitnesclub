<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Documents\RequestDocumentResend;
use App\Enums\DeliveryStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\DocumentResendRequest;
use App\Models\DocumentDelivery;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class DocumentDeliveryController extends Controller
{
    public function index(Request $request): Response
    {
        Gate::forUser($request->user()->fresh())->authorize('manage', DocumentDelivery::class);
        $request->validate(['page' => ['sometimes', 'integer', 'min:1']]);
        $rows = DocumentDelivery::query()->where('kind', 'membership_pdf')->with(['membership.order', 'attempts' => fn ($query) => $query->orderByDesc('attempt_number')])
            ->orderByDesc('id')->paginate(20);
        $rootIds = $rows->getCollection()->map(fn (DocumentDelivery $row): int => $row->root_delivery_id ?? $row->id)->unique()->values()->all();
        $busy = DocumentDelivery::query()->where(fn ($query) => $query->whereIn('id', $rootIds)->orWhereIn('root_delivery_id', $rootIds))
            ->where(fn ($query) => $query->where(fn ($active) => $active->whereIn('status', [DeliveryStatus::Pending, DeliveryStatus::Processing])->where('needs_review', false))
                ->orWhere(fn ($retry) => $retry->where('status', DeliveryStatus::Failed)->where('needs_review', false)->where('attempts_count', '<', DocumentDelivery::MAX_ATTEMPTS)))
            ->get(['id', 'root_delivery_id'])->map(fn ($row): int => $row->root_delivery_id ?? $row->id)->all();

        return Inertia::render('admin/deliveries/Index', ['deliveries' => $rows->through(fn (DocumentDelivery $row): array => [
            'id' => $row->id, 'planName' => $row->membership->plan_snapshot['name'], 'orderReference' => $row->membership->order->public_reference,
            'recipientLabel' => mb_substr($row->recipient_email, 0, 1).'***@'.(explode('@', $row->recipient_email, 2)[1] ?? '***'),
            'status' => $row->status->value, 'attemptsCount' => $row->attempts_count, 'maxAttempts' => DocumentDelivery::MAX_ATTEMPTS,
            'needsReview' => $row->needs_review, 'errorLabel' => $row->last_error_code === null ? null : ($row->needs_review ? 'Wynik próby jest niepewny. Sprawdź, czy wiadomość dotarła, przed ponowną wysyłką.' : 'Nie udało się przygotować lub wysłać wiadomości.'),
            'scheduledFor' => $row->scheduled_for->toISOString(), 'sentAt' => $row->sent_at?->toISOString(),
            'canResend' => ! in_array($row->root_delivery_id ?? $row->id, $busy, true),
            'isResend' => $row->root_delivery_id !== null,
            'attempts' => $row->attempts->map(fn ($attempt): array => ['number' => $attempt->attempt_number, 'status' => $attempt->status, 'attemptedAt' => $attempt->attempted_at->toISOString()]),
        ])]);
    }

    public function resend(int $delivery, DocumentResendRequest $request, RequestDocumentResend $resend): RedirectResponse
    {
        $resend->handle($request->user(), $delivery, $request->validated('request_id'));

        return to_route('admin.deliveries.index')->with('success', 'Zlecono ponowną wysyłkę PDF na zapisany adres odbiorcy.');
    }
}

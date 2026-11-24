<?php

namespace App\Http\Controllers;

use App\Actions\Documents\GenerateMembershipPdf;
use App\Enums\SalesChannel;
use App\Exceptions\MembershipPdfException;
use App\Models\DocumentDelivery;
use App\Models\Membership;
use App\Models\Order;
use App\Support\CheckoutAccess;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

class MembershipPdfController extends Controller
{
    public function checkout(string $reference, Request $request, CheckoutAccess $access, GenerateMembershipPdf $generate): Response
    {
        $order = Order::query()->where('public_reference', $reference)->where('sales_channel', SalesChannel::Online)->firstOrFail();
        $access->authorize($request, $order);

        return $this->download($order->membership()->firstOrFail(), $generate);
    }

    public function show(int $membership, Request $request, GenerateMembershipPdf $generate): Response
    {
        $member = Membership::query()->with('order')->findOrFail($membership);
        $actor = $request->user()?->fresh();
        abort_unless($actor !== null && Gate::forUser($actor)->allows('downloadPdf', $member), 404);

        return $this->download($member, $generate);
    }

    private function download(Membership $membership, GenerateMembershipPdf $generate): Response
    {
        $delivery = DocumentDelivery::query()->where('membership_id', $membership->id)->where('kind', 'membership_pdf')
            ->where('idempotency_key', 'membership:'.$membership->id.':initial-pdf')->firstOrFail();
        try {
            $path = $generate->handle($delivery->id);

            return Storage::disk('membership_documents')->download($path, 'karnet-activefit.pdf', [
                'Content-Type' => 'application/pdf', 'Cache-Control' => 'private, no-store',
                'Referrer-Policy' => 'no-referrer', 'X-Robots-Tag' => 'noindex, nofollow', 'X-Content-Type-Options' => 'nosniff',
            ]);
        } catch (MembershipPdfException $exception) {
            Log::warning('Membership PDF unavailable.', ['delivery_id' => $delivery->id, 'reason' => $exception->failureCode]);

            return response('Nie można teraz przygotować PDF karnetu. Spróbuj ponownie za chwilę.', 503)
                ->header('Cache-Control', 'private, no-store');
        }
    }
}

<?php

namespace App\Http\Controllers\Payments;

use App\Actions\Payments\VerifyPrzelewy24Payment;
use App\Exceptions\PaymentVerificationException;
use App\Http\Controllers\Controller;
use App\Services\Payments\Przelewy24Notification;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use JsonException;

class Przelewy24NotificationController extends Controller
{
    public function __invoke(Request $request, VerifyPrzelewy24Payment $verify): Response
    {
        try {
            if (! $request->isJson() || strlen($request->getContent()) > 8192) {
                throw new PaymentVerificationException('invalid_notification');
            }
            // Verify exact received strings; global input trimming must not alter the checksum.
            $data = json_decode($request->getContent(), true, 16, JSON_THROW_ON_ERROR);
            if (! is_array($data)) {
                throw new PaymentVerificationException('invalid_notification');
            }
            $verify->handle(Przelewy24Notification::fromArray($data));

            return response('OK', 200)->header('Cache-Control', 'no-store');
        } catch (JsonException) {
            Log::warning('P24 notification rejected.', ['reason' => 'invalid_json']);

            return response('Rejected', 400);
        } catch (PaymentVerificationException $exception) {
            Log::warning('P24 notification not finalized.', ['reason' => $exception->failureCode]);

            return response($exception->retryable ? 'Retry later' : 'Rejected', $exception->retryable ? 503 : 400);
        } catch (ValidationException) {
            Log::warning('P24 notification unavailable.', ['reason' => 'configuration_unavailable']);

            return response('Retry later', 503);
        }
    }
}

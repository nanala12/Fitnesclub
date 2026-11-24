<?php

use App\Services\Payments\Przelewy24Notification;
use App\Services\Payments\Przelewy24Signature;

it('signs the ordered registration fields using unescaped Unicode and slashes', function () {
    expect(Przelewy24Signature::registration('session/ż', 123, 12050, 'PLN', 'ż/fixture'))
        ->toBe('7539aa82faaec9ee4a7e12fe9a162721c895745f63f55f0fb0461bf13d5aa1df3c0dcf1ca1f21f1961eea3246385a1c1');
});

it('signs verification with the provider integer transaction ID', function () {
    expect(Przelewy24Signature::verification('session/ż', 987654, 12050, 'PLN', 'ż/fixture'))
        ->toBe('c45f33228c0de6a5709d4d11f47af634bc6e52bc7fa5b44aba576718eb604b64f6ea6d1e85b13307dddf4da211c0f6a5');
});

it('signs every notification field preserving raw whitespace and Unicode', function () {
    $notification = Przelewy24Notification::fromArray(['merchantId' => 123, 'posId' => 456, 'sessionId' => 'session/ż',
        'amount' => 12050, 'originAmount' => 12050, 'currency' => 'PLN', 'orderId' => 987654,
        'methodId' => 25, 'statement' => 'Zażółć / ', 'sign' => str_repeat('0', 96)]);
    expect(Przelewy24Signature::notification($notification, 'ż/fixture'))
        ->toBe('8accb58b6c1ea1c5f99f2ac90c677d639b5e0a7bd956b43d19478233761ee4ae900638302784570ed7707d7fd1ebd1db');
});

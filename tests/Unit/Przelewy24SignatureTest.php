<?php

use App\Services\Payments\Przelewy24Signature;

it('signs the ordered registration fields using unescaped Unicode and slashes', function () {
    expect(Przelewy24Signature::registration('session/ż', 123, 12050, 'PLN', 'ż/fixture'))
        ->toBe('7539aa82faaec9ee4a7e12fe9a162721c895745f63f55f0fb0461bf13d5aa1df3c0dcf1ca1f21f1961eea3246385a1c1');
});

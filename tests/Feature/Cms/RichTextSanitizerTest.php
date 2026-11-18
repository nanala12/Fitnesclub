<?php

use App\Support\Cms\PublicationTime;
use App\Support\Cms\RichTextSanitizer;
use Dom\HTMLDocument;
use Illuminate\Validation\ValidationException;

test('rich text preserves approved formatting and is stable after another parse', function () {
    $sanitizer = app(RichTextSanitizer::class);
    $html = '<h2>Nagłówek</h2><p>Treść <b>ważna</b> i <i>kursywa</i> <u>podkreślona</u>.</p><ul><li>Pierwsza</li></ul><ol><li>Druga</li></ol><blockquote>Cytat</blockquote><p><a href="https://example.com/a?q=1&amp;x=2">Link</a><br>Koniec</p>';
    $clean = $sanitizer->clean($html);
    expect($clean)->toContain('<h2>Nagłówek</h2>', '<strong>ważna</strong>', '<em>kursywa</em>', '<u>podkreślona</u>', '<ul>', '<ol>', '<blockquote>', 'href="https://example.com/a?q=1&amp;x=2"')->and($sanitizer->clean($clean))->toBe($clean);
});

test('untrusted HTML cannot retain executable content or arbitrary attributes', function (string $payload) {
    $sanitizer = app(RichTextSanitizer::class);
    $clean = $sanitizer->clean($payload.'<p>Bezpieczna treść</p>');
    $document = HTMLDocument::createFromString('<!doctype html><html><body>'.$clean.'</body></html>', LIBXML_NOERROR, 'UTF-8');
    foreach ($document->body->querySelectorAll('*') as $element) {
        expect($element->namespaceURI)->toBe('http://www.w3.org/1999/xhtml');
        expect($element->localName)->toBeIn(['p', 'h2', 'h3', 'strong', 'em', 'u', 'ul', 'ol', 'li', 'blockquote', 'br', 'a']);
        foreach ($element->attributes as $attribute) {
            expect($attribute->name)->toBeIn(['href', 'rel']);
        }
        if ($element->hasAttribute('href')) {
            expect($element->getAttribute('href'))->toStartWith('https://');
        }
    }
    expect($clean)->not->toContain('window.pwned', '<script', '<img', '<svg', '<math', 'javascript:', 'data:', 'onerror=', 'style=');
    expect($sanitizer->clean($clean))->toBe($clean);
})->with([
    '<script>window.pwned=1</script>', '<img src=x onerror="window.pwned=1">', '<p onclick="alert(1)" style="background:url(javascript:alert(1))">Tekst</p>',
    '<a href="javascript:alert(1)" target="_blank">Link</a>', '<a href="jAvAsCrIpT&#58;alert(1)">Link</a>', '<a href="java&#x09;script:alert(1)">Link</a>',
    '<a href="data:text/html,<script>alert(1)</script>">Link</a>', '<a href="//evil.example">Link</a>', '<a href="/\\evil.example">Link</a>',
    '<svg><foreignObject><script>window.pwned=1</script></foreignObject></svg>', '<math><mtext><table><mglyph><style><!--</style><img title="--><img src=x onerror=alert(1)>"></table></mtext></math>',
    '<template><img src=x onerror=alert(1)></template>', '<iframe srcdoc="<script>window.pwned=1</script>"></iframe>',
    '<form action="https://evil.example"><input autofocus onfocus="alert(1)"></form>', '<object data="data:text/html,test"></object>',
    '<p id="x" class="fixed" data-state="evil" contenteditable=true title="test">Tekst</p>', '<a href="https://user:password@example.com">Link</a>',
]);

test('rich text permits local links and escapes text which looks like HTML', function () {
    $html = app(RichTextSanitizer::class)->clean('<p>&lt;script&gt;dosłowny tekst&lt;/script&gt;</p><a href="/oferta/1">Oferta</a>');
    expect($html)->toContain('&lt;script&gt;dosłowny tekst&lt;/script&gt;', 'href="/oferta/1"')->not->toContain('<script>');
});

test('rich text rejects excessive nesting and content size', function (string $mode) {
    expect(fn () => app(RichTextSanitizer::class)->clean($mode === 'depth' ? str_repeat('<span>', 65).'Tekst'.str_repeat('</span>', 65) : str_repeat('x', 200001)))->toThrow(ValidationException::class);
})->with(['depth', 'size']);

test('publication local time converts to UTC through summer and winter', function (string $local, string $utc) {
    expect(app(PublicationTime::class)->parse($local)->format('Y-m-d H:i:s'))->toBe($utc);
})->with([['2026-01-15T12:30', '2026-01-15 11:30:00'], ['2026-07-15T12:30', '2026-07-15 10:30:00']]);

test('publication rejects invalid nonexistent and ambiguous local instants', function (string $local) {
    expect(fn () => app(PublicationTime::class)->parse($local))->toThrow(ValidationException::class);
})->with(['2026-03-29T02:30', '2026-10-25T02:30', 'not-a-date', '2026-02-30T12:00']);

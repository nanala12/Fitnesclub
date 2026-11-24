<!DOCTYPE html>
<html lang="pl">
<head>
    <meta charset="UTF-8">
    <title>Karnet</title>
    <style>
        @page { margin: 50px; }
        body { font-family: "DejaVu Sans", sans-serif; font-size: 11px; color: #403934; line-height: 1.6; }
        .brand { font-size: 25px; font-weight: bold; margin-bottom: 6px; word-wrap: break-word; }
        .eyebrow { color: #786c62; font-size: 9px; letter-spacing: 2px; text-transform: uppercase; }
        h1 { font-size: 32px; margin: 24px 0 6px; }
        .name { font-size: 21px; font-weight: bold; margin: 8px 0 28px; word-wrap: break-word; }
        .panel { border: 1px solid #dfd7d0; border-radius: 10px; padding: 22px; margin: 20px 0; }
        .plan { font-size: 18px; font-weight: bold; margin: 0 0 18px; word-wrap: break-word; }
        table { width: 100%; border-collapse: collapse; }
        td { border-top: 1px solid #eae4df; padding: 10px 0; vertical-align: top; }
        td:first-child { color: #786c62; width: 52%; }
        td:last-child { text-align: right; font-weight: bold; }
        .notice { background: #eee7e0; border-radius: 10px; padding: 19px; margin-top: 24px; }
        .notice strong { display: block; margin-bottom: 6px; }
        .footer { margin-top: 35px; font-size: 9px; color: #786c62; }
    </style>
</head>
<body>
    <div class="brand">{{ $document->clubName }}</div>
    <div class="eyebrow">Twój karnet</div>
    <h1>Karnet</h1>
    <div class="eyebrow">Posiadacz karnetu</div>
    <div class="name">{{ $document->holderName }}</div>
    <div class="panel">
        <div class="plan">{{ $document->planName }}</div>
        <table>
            <tr><td>Rodzaj karnetu</td><td>{{ $document->kind === 'time' ? 'Karnet czasowy' : 'Karnet wejściowy' }}</td></tr>
            @if($document->kind === 'time')
                <tr><td>Liczba miesięcy</td><td>{{ $document->durationMonths }}</td></tr>
            @else
                <tr><td>Zakupiony pakiet wejść</td><td>{{ $document->includedEntries }}</td></tr>
                <tr><td>Łączny limit wejść na tym karnecie</td><td>{{ $document->grantedEntries }}</td></tr>
                <tr><td>Ograniczenie czasowe</td><td>Bez ograniczenia czasowego</td></tr>
            @endif
            <tr><td>Cena zakupu</td><td>{{ $document->priceLabel }}</td></tr>
            @if($document->startsOn !== null)
                <tr><td>Data rozpoczęcia</td><td>{{ $document->startsOn }}</td></tr>
            @endif
            @if($document->expiresOn !== null)
                <tr><td>Ważny do (włącznie)</td><td>{{ $document->expiresOn }}</td></tr>
            @endif
        </table>
    </div>
    @if($document->startsOn === null)
        <div class="notice"><strong>Ważność rozpocznie się przy pierwszej wizycie w klubie</strong>Pracownik lub administrator aktywuje karnet podczas wizyty. Opłacenie zakupu nie rozpoczyna jego ważności.</div>
    @else
        <div class="notice">Dokument przedstawia ustalony okres lub początek korzystania z karnetu. Informację o bieżącej możliwości wejścia uzyskasz w klubie.</div>
    @endif
    <div class="footer">Dokument potwierdza zakup karnetu. Dane i warunki pochodzą z opłaconego zamówienia. Wykorzystanie wejścia jest osobną czynnością rejestrowaną w klubie.</div>
</body>
</html>

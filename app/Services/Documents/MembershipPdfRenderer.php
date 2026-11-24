<?php

namespace App\Services\Documents;

use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Support\Facades\File;
use SensitiveParameter;

class MembershipPdfRenderer
{
    public function render(#[SensitiveParameter] MembershipPdfData $document): string
    {
        $cache = storage_path('framework/cache/membership-pdf-fonts');
        File::ensureDirectoryExists($cache, 0700);
        $options = new Options;
        $options->setDefaultFont('DejaVu Sans');
        $options->setIsRemoteEnabled(false);
        $options->setIsPhpEnabled(false);
        $options->setIsJavascriptEnabled(false);
        $options->setAllowedProtocols([]);
        $options->setChroot(base_path('vendor/dompdf/dompdf/lib/fonts'));
        $options->setFontCache($cache);
        $options->setTempDir($cache);
        $options->setIsFontSubsettingEnabled(true);
        $pdf = new Dompdf($options);
        $pdf->setPaper('A4');
        $pdf->loadHtml(view('documents.membership', ['document' => $document])->render(), 'UTF-8');
        $pdf->render();
        $pdf->addInfo('Title', 'Karnet ActiveFit');
        $pdf->addInfo('Author', 'ActiveFit');

        return $pdf->output();
    }
}

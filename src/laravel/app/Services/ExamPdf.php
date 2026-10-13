<?php

namespace App\Services;

use App\Models\Exam;
use Dompdf\Dompdf;
use Dompdf\Options;

class ExamPdf
{
    public function render(Exam $exam, string $kind, bool $explanations): string
    {
        $options = new Options(['isRemoteEnabled' => false, 'isPhpEnabled' => false, 'defaultFont' => 'DejaVu Sans', 'chroot' => public_path()]);
        $pdf = new Dompdf($options);
        $pdf->loadHtml(view('pdf.exam', compact('exam', 'kind', 'explanations'))->render());
        $pdf->setPaper('A4');
        $pdf->render();
        $canvas = $pdf->getCanvas();
        $font = $pdf->getFontMetrics()->getFont('DejaVu Sans', 'normal');
        $canvas->page_text(35, 800, 'Jon Bryan S. Tiosin, MD | (c) jstiosin 2026', $font, 7, [.43, .14, .25]);
        $canvas->page_text(35, 813, $exam->code.' | '.strtoupper($kind).' | Page {PAGE_NUM} of {PAGE_COUNT}', $font, 7, [.43, .14, .25]);

        return $pdf->output();
    }
}

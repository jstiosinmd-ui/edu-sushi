<?php

namespace App\Jobs;

use App\Models\PdfExport;
use App\Services\ExamPdf;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Storage;

class BuildExamPdfs implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public int $timeout = 300;

    public function __construct(public int $exportId) {}

    public function handle(ExamPdf $pdf): void
    {
        $e = PdfExport::with('exam')->findOrFail($this->exportId);
        $e->update(['status' => 'building', 'error' => null]);
        foreach (['paper', 'sheet', 'key'] as $kind) {
            Storage::disk('local')->put('exams/'.$e->id.'/'.$kind.'.pdf', $pdf->render($e->exam, $kind, $e->explanations));
        }
        $e->update(['status' => 'ready']);
    }

    public function failed(?\Throwable $e): void
    {
        PdfExport::whereKey($this->exportId)->update(['status' => 'failed', 'error' => 'PDF generation failed. The administrator can inspect the server log and retry.']);
    }
}

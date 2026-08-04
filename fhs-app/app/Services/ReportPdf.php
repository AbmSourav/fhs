<?php

namespace App\Services;

use App\Models\User;
use App\Support\BusinessCalendar;
use Barryvdh\DomPDF\Facade\Pdf;
use Barryvdh\DomPDF\PDF as PdfDocument;
use Illuminate\Http\Response;

/**
 * A month's report as a PDF, built on the server.
 *
 * The point of doing this here rather than through the browser's print dialog
 * is provenance: the figures are read from the database and written straight
 * into the file, so they never pass through anything the reader can edit.
 * Printing the on-screen page would take whatever the DOM said at the time,
 * which anyone can change in devtools.
 */
class ReportPdf
{
    public function __construct(
        private readonly DashboardService $dashboard,
    ) {}

    /** The report for a month, as a downloadable file. */
    public function download(string $month, User $generatedBy): Response
    {
        return $this->render($month, $generatedBy)->download($this->filename($month));
    }

    /**
     * The same PDF, shown in the browser rather than saved.
     *
     * For working on the template: dompdf renders a limited CSS subset, so a
     * layout has to be seen as a PDF to be judged, and downloading a file for
     * every tweak makes that painfully slow. Identical bytes to download() —
     * only the Content-Disposition differs.
     */
    public function preview(string $month, User $generatedBy): Response
    {
        return $this->render($month, $generatedBy)->stream($this->filename($month));
    }

    /**
     * The document itself, before it is decided how to send it.
     *
     * The user is passed in rather than read from auth() here, so the service
     * states what it needs and can be exercised without a logged-in session.
     */
    private function render(string $month, User $generatedBy): PdfDocument
    {
        return Pdf::loadView('reports.monthly', [
            'report' => $this->dashboard->monthlyReport($month),
            'money'  => $this->formatter(),
            // Rendered here rather than in the template so the PDF is stamped
            // on the business clock like every other date in the application.
            'generatedAt' => BusinessCalendar::now()->format('j M Y, g:i:sa'),
            // Who produced this copy. Worth recording on a document that leaves
            // the business: it says who to ask about it.
            'generatedBy' => $generatedBy->name,
        ]);
    }

    /**
     * Money, written for a PDF.
     *
     * "Tk" rather than the ৳ sign: dompdf's bundled DejaVu Sans has no glyph
     * at U+09F3, so the symbol would render as a blank box. The screen keeps
     * the proper sign — only the PDF is constrained by the font.
     */
    private function formatter(): callable
    {
        return function (float $amount): string {
            $formatted = number_format(abs($amount), 0);

            return "Tk {$formatted}";
        };
    }

    /**
     * "fhs-report-july-2026.pdf" — readable at a glance in a folder.
     *
     * Lower-cased and hyphenated rather than "July 2026": a filename with a
     * space in it gets escaped or mangled by half the things that handle it.
     */
    private function filename(string $month): string
    {
        $named = strtolower(BusinessCalendar::parseMonth($month)->format('F-Y'));

        return "fhs-report-{$named}.pdf";
    }
}

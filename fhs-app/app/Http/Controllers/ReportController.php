<?php

namespace App\Http\Controllers;

use App\Services\DashboardService;
use App\Services\ReportPdf;
use App\Support\BusinessCalendar;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Inertia\Inertia;
use Inertia\Response;

class ReportController extends Controller
{
    public function __construct(
        private readonly DashboardService $dashboard,
        private readonly ReportPdf $pdf,
    ) {}

    /**
     * A month's trading, for reading on screen.
     *
     * The month is a query parameter rather than a path segment so the picker
     * can switch months without leaving the page, and so a report can be
     * bookmarked or linked to.
     */
    public function __invoke(Request $request): Response
    {
        $months = $this->dashboard->reportableMonths();

        return Inertia::render('reports/index', [
            'report' => $this->dashboard->monthlyReport($this->monthFrom($request, $months)),
            'months' => $months,
        ]);
    }

    /**
     * The same report as a PDF, built on the server.
     *
     * A separate route rather than a print of the page: the figures are read
     * from the database here, so editing the on-screen report in devtools
     * cannot change what is downloaded.
     */
    public function download(Request $request): HttpResponse
    {
        return $this->pdf->download(
            $this->monthFrom($request, $this->dashboard->reportableMonths()),
            $request->user(),
        );
    }

    /**
     * The PDF shown in the browser rather than downloaded.
     *
     * For working on the template. The layout can only really be judged as a
     * rendered PDF, and saving a file for every change makes that slow.
     */
    public function preview(Request $request): HttpResponse
    {
        return $this->pdf->preview(
            $this->monthFrom($request, $this->dashboard->reportableMonths()),
            $request->user(),
        );
    }

    /**
     * The month being asked for, or the latest if that makes no sense.
     *
     * Shared by both routes so a hand-edited URL cannot make the PDF report a
     * month the page would refuse to show.
     *
     * @param  array<int, array{value: string, label: string}>  $months
     */
    private function monthFrom(Request $request, array $months): string
    {
        $month = $request->string('month')->value();

        // Anything unrecognised falls back to the most recent month rather than
        // erroring: a mistyped URL should still produce a usable report.
        return BusinessCalendar::isValidMonth($month) ? $month : $months[0]['value'];
    }
}

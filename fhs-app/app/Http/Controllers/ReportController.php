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
     * Nothing is reported until a month is picked. Landing on a report nobody
     * asked for invites it being read as the current position, and the month it
     * happened to choose is easy to miss.
     *
     * The month is a query parameter rather than a path segment so the picker
     * can switch months without leaving the page, and so a report can be
     * bookmarked or linked to.
     */
    public function __invoke(Request $request): Response
    {
        $month = $request->string('month')->value();

        return Inertia::render('reports/index', [
            // Null until a real month is asked for, which also means a mistyped
            // URL shows the empty state rather than someone else's month.
            'report' => BusinessCalendar::isValidMonth($month)
                ? $this->dashboard->monthlyReport($month)
                : null,
            'months' => $this->dashboard->reportableMonths(),
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
     * For the two PDF routes only. A file has to be for some month — there is
     * no empty state to fall back to the way the page has — so an unrecognised
     * one yields the most recent report rather than an error.
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

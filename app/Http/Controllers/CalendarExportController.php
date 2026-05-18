<?php

namespace App\Http\Controllers;

use App\Models\CalendarEntry;
use App\Models\ContentCalendar;
use App\Models\Team;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class CalendarExportController extends Controller
{
    public function __invoke(Request $request, Team $current_team): StreamedResponse
    {
        $month = $request->query('month', now()->format('Y-m'));
        $start = Carbon::parse($month.'-01');
        $end = $start->copy()->endOfMonth();

        $cal = ContentCalendar::firstOrCreate(['team_id' => $current_team->id]);

        $entriesByDay = CalendarEntry::where('calendar_id', $cal->id)
            ->whereBetween('scheduled_for', [$start, $end])
            ->orderBy('scheduled_for')
            ->orderBy('id')
            ->get()
            ->groupBy(fn ($e) => $e->scheduled_for->format('Y-m-d'));

        $isSlovenian = $current_team->content_language === 'sl';

        $headers = $isSlovenian
            ? ['Ideja', 'Platforma', 'Grafika Copy', 'Vsebina', 'Opombe / Avtor slike']
            : ['Idea', 'Platform', 'Image Headline', 'Content', 'Notes'];

        $weekdayLabels = $isSlovenian
            ? [1 => 'ponedeljek', 2 => 'torek', 3 => 'sreda', 4 => 'četrtek', 5 => 'petek', 6 => 'sobota', 7 => 'nedelja']
            : [1 => 'monday', 2 => 'tuesday', 3 => 'wednesday', 4 => 'thursday', 5 => 'friday', 6 => 'saturday', 7 => 'sunday'];

        $filename = ($current_team->slug ?? (string) $current_team->id).'-calendar-'.$month.'.csv';

        return response()->streamDownload(function () use ($start, $end, $entriesByDay, $headers, $weekdayLabels) {
            $out = fopen('php://output', 'w');
            fputcsv($out, array_merge([$start->format('dm'), ''], $headers));
            for ($d = $start->copy(); $d->lte($end); $d->addDay()) {
                $dayEntries = $entriesByDay->get($d->format('Y-m-d'), collect());
                if ($dayEntries->isEmpty()) {
                    fputcsv($out, [$d->day, $weekdayLabels[$d->isoWeekday()], '', '', '', '', '']);
                    continue;
                }
                foreach ($dayEntries as $e) {
                    fputcsv($out, [
                        $d->day,
                        $weekdayLabels[$d->isoWeekday()],
                        $e->title ?? '',
                        $e->platform ?? '',
                        $e->image_headline ?? '',
                        $e->content ?? '',
                        $e->notes ?? '',
                    ]);
                }
            }
            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv']);
    }
}

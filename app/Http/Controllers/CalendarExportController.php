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
        $postingDays = $cal->posting_days ?? $current_team->posting_days ?? ['mon', 'wed', 'fri'];
        $postingDayNumbers = collect($postingDays)->map(fn ($d) => [
            'mon' => 1, 'tue' => 2, 'wed' => 3, 'thu' => 4, 'fri' => 5, 'sat' => 6, 'sun' => 7,
        ][$d] ?? null)->filter()->all();

        $entries = CalendarEntry::where('calendar_id', $cal->id)
            ->whereBetween('scheduled_for', [$start, $end])
            ->get()
            ->keyBy(fn ($e) => $e->scheduled_for->format('Y-m-d'));

        $isSlovenian = $current_team->content_language === 'sl';

        $headers = $isSlovenian
            ? ['Ideja', 'Grafika Copy', 'LinkedIn Copy', 'Instagram Copy', 'Facebook Copy', 'Opombe / Avtor slike']
            : ['Idea', 'Image Headline', 'LinkedIn Copy', 'Instagram Copy', 'Facebook Copy', 'Notes'];

        $weekdayLabels = $isSlovenian
            ? [1 => 'ponedeljek', 2 => 'torek', 3 => 'sreda', 4 => 'četrtek', 5 => 'petek', 6 => 'sobota', 7 => 'nedelja']
            : [1 => 'monday', 2 => 'tuesday', 3 => 'wednesday', 4 => 'thursday', 5 => 'friday', 6 => 'saturday', 7 => 'sunday'];

        $filename = ($current_team->slug ?? (string) $current_team->id).'-calendar-'.$month.'.csv';

        return response()->streamDownload(function () use ($start, $end, $postingDayNumbers, $entries, $headers, $weekdayLabels) {
            $out = fopen('php://output', 'w');
            fputcsv($out, array_merge([$start->format('dm'), ''], $headers));
            for ($d = $start->copy(); $d->lte($end); $d->addDay()) {
                if (! in_array($d->isoWeekday(), $postingDayNumbers)) {
                    continue;
                }
                $e = $entries->get($d->format('Y-m-d'));
                fputcsv($out, [
                    $d->day,
                    $weekdayLabels[$d->isoWeekday()],
                    $e->title ?? '',
                    $e->image_headline ?? '',
                    $e->linkedin_copy ?? '',
                    $e->instagram_copy ?? '',
                    $e->facebook_copy ?? '',
                    $e->notes ?? '',
                ]);
            }
            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv']);
    }
}

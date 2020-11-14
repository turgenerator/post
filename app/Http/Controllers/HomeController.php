<?php

namespace App\Http\Controllers;

use App\Jobs\ProcessRecord;
use App\RegistryRecord;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Unirest\Request as UnirestRequest;

class HomeController extends Controller
{
    protected $requestHeaders = array(
        'Content-Type' => 'application/json',
        'AuthCode' => '53fb9daa-7f06-481f-aad6-c6a7a58ec0bb',
    );

    /**
     * Create a new controller instance.
     *
     * @return void
     */
    public function __construct()
    {
        $this->middleware('auth');
    }

    /**
     * Show the application dashboard.
     *
     * @return \Illuminate\Contracts\Support\Renderable
     */
    public function index(Request $request)
    {
        $filesUploaded = $request->user()->reports()->count();
        $rowsProcessed = $request->user()->reports()->sum('rows_count');
        $successRowsProcessed = $request->user()->reports()->sum('rows_success');
        $warningRowsProcessed = $request->user()->reports()->sum('rows_warning');

        return view('home', [
            'filesUploaded' => $filesUploaded,
            'rowsProcessed' => $rowsProcessed,
            'successRowsProcessed' => $successRowsProcessed,
            'warningRowsProcessed' => $warningRowsProcessed
        ]);
    }

    public function upload()
    {
        return view('home_upload');
    }

    public function processUpload(Request $request)
    {
        $record = $request->user()->reports()->where('progress', '<>', '1')->count();

        if ($record > 0) {
            return redirect()->back()->with('status', 'Вы уже загрузили файл на проверку!');
        }


        $file = $request->file('file');
        $name = Str::random(6) . '.' . $file->getClientOriginalExtension();
        $file->storeAs('', $name);

        $record = RegistryRecord::create([
            'source_filename' => $name,
            'user_id' => $request->user()->id
        ]);

        $job = (new ProcessRecord($record))
            ->delay(Carbon::now()->addSecond(1));

        dispatch($job);

        return redirect()->route('home.reports');
    }

    public function reports(Request $request)
    {
        $records = $request->user()->reports()->orderBy('id', 'DESC')->get();

        return view('home_reports', [
            'records' => $records
        ]);
    }

    public function download(Request $request)
    {
        $type = $request->get('type');
        $record = RegistryRecord::where('id', $request->get('record_id'))->first();

        if ($type == 'report') {
            $filename = $record->out_filename;

            if (Storage::disk('local')->exists($filename)) {
                return Storage::disk('local')->download($filename);
            }
        } else {
            $filename = $record->source_filename;

            if (Storage::disk('local')->exists($filename)) {
                return Storage::disk('local')->download($filename);
            }
        }

        return redirect()->back();
    }

    public function workProgress(Request $request)
    {
        $records = $request->user()->reports()->where('progress', '<>', '1')->get();
        $data = [];

        foreach ($records as $record) {
            $data[] = [
                'record' => $record->id,
                'progress' => $record->progress
            ];
        }

        return response()->json($data);
    }
}

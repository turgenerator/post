<?php

namespace App\Jobs;

use App\RegistryRecord;
use Illuminate\Bus\Queueable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Support\Facades\Storage;
use Unirest\Request as UnirestRequest;

class ProcessRecord implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected $registryRecord;

    protected $requestHeaders = array(
        'Content-Type' => 'application/json',
        'AuthCode' => '53fb9daa-7f06-481f-aad6-c6a7a58ec0bb',
    );

    /**
     * Create a new job instance.
     *
     * @param RegistryRecord $record
     */
    public function __construct(RegistryRecord $record)
    {
        $this->registryRecord = $record;
    }

    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle()
    {
        if (stripos($this->registryRecord->source_filename,'csv')) {
            $fff = storage_path('app/' . $this->registryRecord->source_filename);
            $filename = $fff;

            $rows = [];

            if (($h = fopen("{$filename}", "r")) !== FALSE)
            {

                while (($data = $this->customfgetcsv($h, 1000, ';')) !== FALSE)
                {
                    if (empty($data[3])) break;
                    $rows[] = [$data[3]];
                }

                array_shift($rows);

                fclose($h);
            }
        } else {
            $xlsx = \SimpleXLSX::parse(Storage::disk('local')->path($this->registryRecord->source_filename));
            $rows = $xlsx->rows(0);
        }

        $num_rows = count($rows);

        $processedData = [];
        $processedData[0] = [
            'Исходный адрес',
            'Полученный адрес',
            'Статус',
            'Комментарий',
            'Индекс(index)',
            'Страна(C)',
            'Регион(R)',
            'Район(A)',
            'Населенный пункт(P)',
            'Внутригородская территория(T)',
            'Улично-дорожные элементы(S)',
            'Номер дома(N)',
            'Литера(N)',
            'Дробь(D)',
            'Корпус(E)',
            'Строение(B)',
            'Помещение(F)',
            'Абонентский ящик (А/Я)(BOX)',
            'Отделение почтовой связи (ОПС)(OPS)',
            'Войсковая часть (В/Ч)(M)',
        ];
        $index = 1;
        $successCount = 0;
        foreach ($rows as $row) {

            $query = array(
                "version" => "ce2bedf1-f31c-45ed-b3a8-b67ac3d26b23",
                'fio' => 'Иванов Петр Васильевич',
                'addr' => [
                    [
                        'val' => $row[0],
                    ],
                ],
            );

            $response = UnirestRequest::post(
                'https://address.pochta.ru/validate/api/v7_1',
                $this->requestHeaders,
                json_encode($query, JSON_UNESCAPED_UNICODE)
            );

            $processedData[$index][] = $response->body->addr->inaddr;
            $processedData[$index][] = $response->body->addr->outaddr;

            if ($response->body->state == '301') {
                $processedData[$index][] = 'Адрес подтвержден';
            } else if ($response->body->state == '302') {
                $processedData[$index][] = 'Адрес подтвержден и он неполный';
            } else if ($response->body->state == '303') {
                $processedData[$index][] = 'Адресу сопоставлено несколько вариантов';
            } else if ($response->body->state == '404') {
                $processedData[$index][] = 'Ящик в указанном ОПС не найден';
            }

            $accuracy = str_split($response->body->addr->accuracy);
            $text = $this->getAccuracyString($accuracy);

            $processedData[$index][] = $text;

            $processedData[$index][] = $response->body->addr->index ?? '';
            $processedData[$index][] = $this->getElementContent('C', $response->body->addr->element);
            $processedData[$index][] = $this->getElementContent('R', $response->body->addr->element);
            $processedData[$index][] = $this->getElementContent('A', $response->body->addr->element);
            $processedData[$index][] = $this->getElementContent('P', $response->body->addr->element);
            $processedData[$index][] = $this->getElementContent('T', $response->body->addr->element);
            $processedData[$index][] = $this->getElementContent('S', $response->body->addr->element);
            $processedData[$index][] = $this->getElementContent('N', $response->body->addr->element);
            $processedData[$index][] = $this->getElementContent('n', $response->body->addr->element);
            $processedData[$index][] = $this->getElementContent('D', $response->body->addr->element);
            $processedData[$index][] = $this->getElementContent('E', $response->body->addr->element);
            $processedData[$index][] = $this->getElementContent('B', $response->body->addr->element);
            $processedData[$index][] = $this->getElementContent('F', $response->body->addr->element);
            $processedData[$index][] = $this->getElementContent('BOX', $response->body->addr->element);
            $processedData[$index][] = $this->getElementContent('OPS', $response->body->addr->element);
            $processedData[$index][] = $this->getElementContent('M', $response->body->addr->element);

            $index++;
            if ($response->body->state == '301') {
                $successCount++;
            }

            if ($index == intval($num_rows / 4)) {
                $this->registryRecord->progress = 0.25;
                $this->registryRecord->save();
            } else if ($index == intval($num_rows / 2)) {
                $this->registryRecord->progress = 0.5;
                $this->registryRecord->save();
            } else if ($index == intval($num_rows / 1.25)) {
                $this->registryRecord->progress = 0.8;
                $this->registryRecord->save();
            }
        }

        $xlsx = \SimpleXLSXGen::fromArray( $processedData );
        $normalizedPath = storage_path('app/n_' . $this->registryRecord->source_filename);
        $xlsx->saveAs($normalizedPath); // or downloadAs('books.xlsx')

        $this->registryRecord->out_filename = 'n_' . $this->registryRecord->source_filename;
        $this->registryRecord->rows_count = $index;
        $this->registryRecord->rows_success = $successCount;
        $this->registryRecord->rows_warning = $index - $successCount;
        $this->registryRecord->progress = 1.0;

        $this->registryRecord->save();
    }

    private function getAccuracyString($accuracy) {
        $text = '';

        switch ($accuracy[0]) {
            case '0':
                $text .= 'Индекс определен по дому / квартире.';
                break;
            case '1':
                $text .= 'Индекс определен по улице.';
                break;
            case '2':
                $text .= 'Индекс определен по нас. пункту';
                break;
            case '3':
                $text .= 'Индекс не определен';
                break;
        }

        switch ($accuracy[1]) {
            case '0':
                $text .= ' Дом найден в ФИАС.';
                break;
            case '1':
                $text .= ' Дом определен из запроса.';
                break;
            case '2':
                $text .= ' Дом не определен.';
                break;
        }

        switch ($accuracy[2]) {
            case '0':
                $text .= ' Квартира найдена в ФИАС.';
                break;
            case '1':
                $text .= ' Квартира определена из запроса.';
                break;
            case '2':
                $text .= ' Квартира не определена';
                break;
        }

        return $text;
    }

    private function getElementContent($char, $elements) {
        foreach ($elements as $el) {
            if ($char == $el->content) {
                return $el->val ?? '';
            }
        }

        return '';
    }

    function customfgetcsv(&$handle, $length, $separator = ';'){
        if (($buffer = fgets($handle, $length)) !== false) {
            return explode($separator, iconv("CP1251", "UTF-8", $buffer));
        }
        return false;
    }
}

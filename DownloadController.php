<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Http\Services\OrbitResponse;
use App\Http\Services\OrbitResponseException;
use Illuminate\Support\Facades\Storage;

class DownloadController extends Controller
{
    // private $response = [
    //     'StatusCode' => 200,
    //     'Message' => "SUCCESS",
    //     'Result' => null
    // ];

    private $orbitResp;

    private $ftpHost = null;
    private $ftpAccount = null;
    private $ftpPassword = null;
    private $ftpPort = null;

    public function __construct()
    {
        if (app()->environment() == 'production') {
            // $this->ftpHost = '10.11.56.92';
            $this->ftpHost = '10.11.56.81';
            $this->ftpAccount = 'ftpwms';
            $this->ftpPassword = '24941ftp';
            $this->ftpPort = 21;
        } else {
            $this->ftpHost = '127.0.0.1';
            $this->ftpAccount = 'ftpwms';
            $this->ftpPassword = '24941ftp';
            $this->ftpPort = 21;
        }

        $this->middleware(['ad', 'acl:staff']);

        $this->orbitResp = new OrbitResponse;
    }

    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function index()
    {   

        $name = url()->getRequest()['name'] ?? '';

        $name = preg_replace('/[^A-Za-z0-9._-]/', '', $name);

        if ($name === '') {
            return $this->orbitResp->response();
        }


        $zipName = session()->getId() . "-" . $name;
        $path = \Storage::path('public\tmp') . "\\" . $zipName . '.txt';

        try {
            $file = fopen($path, "r");
            $list = [];

            if ($file) {
                while(!feof($file)) {
                    $line = fgets($file);

                    if ($line != PHP_EOL) {
                        array_push($list, $line);
                    }
                }

                fclose($file);

                $total = !empty($list[0]) ? (int)$list[0] : 0;
                $current = count($list) - 2;
                $status = 'dealing';

                if ($current >= $total) {
                    $status = 'solved';
                    $current = $total;
                    // \Log::info('unlink: ' . $path);
                    // unlink($path);
                }

                $this->orbitResp->setResult([
                    'total' => $total,
                    'current' => $current,
                    'status' => $status,
                ]);
            }
        } catch (\Exception $e) {
            \Log::info('[' . __CLASS__ . '] ' . $e->getMessage());
        }

        return $this->orbitResp->response();
    }

    /**
     * Show the form for creating a new resource.
     *
     * @return \Illuminate\Http\Response
     */
    // public function create()
    // {
    //     //
    // }

    /**
     * Store a newly created resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function store(Request $request)
    {
        $response = [
            'statusCode' => 200,
            'message' => "SUCCESS",
            'result' => [
                'datas' => null,
            ],
        ];

        $requests = $request->all();
        $querys = $requests['querys'];

        $name = $requests['name'] ?? '';
        $name = preg_replace('/[^A-Za-z0-9._-]/', '', $name);

        if ($name === '') {
            return response()->json([
                'statusCode' => -1,
                'message' => 'INVALID NAME',
                'result' => [
                    'datas' => null,
                ],
            ]);
        }




        $zipName = session()->getId() . "-" . $name;
        $hintPath = \Storage::path('public\tmp') . "\\" . $zipName . '.txt';
        // $zipPath = \Storage::getDriver()->getAdapter()->getPathPrefix() . "public\\tmp\\" . $zipName . '.zip';
        $zipPath = \Storage::disk('public')->path('tmp/' . $zipName . '.zip');

        // $tmpPath = \Storage::getDriver()->getAdapter()->getPathPrefix() . "public\\tmp\\" . $zipName;

        $tmpPath = \Storage::disk('public')->path('tmp/' . $zipName);

        file_put_contents($hintPath, count($querys) . PHP_EOL); // write total
        mkdir($tmpPath);
        $ftpStream = ftp_connect($this->ftpHost, $this->ftpPort);


        //ftp 下載
        if (ftp_login($ftpStream, $this->ftpAccount, $this->ftpPassword)) {
            \Log::info('[' . __CLASS__ . ']' . 'CONNECT SUCCESS!');

            ftp_pasv($ftpStream, true);
            $zip = new \ZipArchive;

            if ($zip->open($zipPath, \ZipArchive::CREATE) == false) {
                throw new \Exception("壓縮檔建立失敗", 1);
            }

            foreach ($querys as $id) {

                $id = preg_replace('/[^A-Za-z0-9._-]/', '', (string)$id);

                if ($id === '') {
                    continue;
                }

                
                $fileName = $id . ".jpg";
                // $tmpFile = \Storage::getDriver()->getAdapter()->getPathPrefix() . "public\\tmp\\" . $zipName . "\\" . $fileName;

                $tmpFile = Storage::disk('public')->path("tmp/{$zipName}/{$fileName}");

                try {
                    if (ftp_get($ftpStream, $tmpFile, $fileName, FTP_BINARY)) {
                        if (file_exists($tmpFile)) {
                            file_put_contents($hintPath, $fileName . PHP_EOL, FILE_APPEND);
                            $zip->addFile($tmpFile, $fileName);
                        }
                    }
                } catch (\Exception $e) {
                    $response['statusCode'] = -10;
                    $response['message'] = '圖片下載失敗';
                    \Log::info('圖片下載失敗');
                }
            }

            // $files = glob($tmpPath . "\\*");
            // foreach ($files as $file) {
            //     if (is_file($file)) unlink($file);
            // }
            // rmdir($tmpPath);
            // unlink($hintPath);
            $zip->close();

            $response['result']['zipName'] = $zipName;
        }

        ftp_close($ftpStream);

        return response()->json($response);
    }

    /**
     * Display the specified resource.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function show($id)
    {
        $zipName = session()->getId() . "-" . $id;
        $zipPath = \Storage::path('public\tmp') . "\\" . $zipName . '.zip';

        $response = [
            'statusCode' => 200,
            'message' => "SUCCESS",
            'result' => [
                'datas' => null,
            ],
        ];

        if (file_exists($zipPath)) {
            // $newName = \Storage::getDriver()->getAdapter()->getPathPrefix() . "public\\tmp\\viewCase\\" . date("YmdHis") . '.zip';
            $newName = Storage::disk('public')->path('tmp/viewCase/' . date('YmdHis') . '.zip');
            rename($zipPath, $newName);
            return response()->download($newName);
        }

        return response('查無此資源');
    }

    /**
     * Show the form for editing the specified resource.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    // public function edit($id)
    // {
    //     //
    // }

    /**
     * Update the specified resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    // public function update(Request $request, $id)
    // {
    //     //
    // }

    /**
     * Remove the specified resource from storage.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    // public function destroy($id)
    // {
    //     //
    // }
}

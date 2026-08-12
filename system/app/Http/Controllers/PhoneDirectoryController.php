<?php

namespace App\Http\Controllers;

use App\Services\PhoneDirectory1881Service;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Throwable;

class PhoneDirectoryController extends Controller
{
    public function lookup(Request $request,PhoneDirectory1881Service $directory):JsonResponse
    {
        abort_unless($directory->enabled(),404);
        $data=$request->validate(['phone'=>['required','string','max:30']]);
        try{$result=$directory->lookup($data['phone']);}
        catch(Throwable$e){report($e);return response()->json(['message'=>'Oppslaget kunne ikke gjennomføres akkurat nå.'],502);}
        DB::table('audit_logs')->insert(['organization_id'=>$request->user()->organization_id,'user_id'=>$request->user()->id,'action'=>'directory.1881.lookup','ip_address'=>$request->ip(),'metadata'=>json_encode(['found'=>(bool)$result]),'created_at'=>now()]);
        return $result?response()->json($result):response()->json(['message'=>'Nummeret er ikke offentlig oppført hos 1881.'],404);
    }
}

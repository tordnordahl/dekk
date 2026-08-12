<?php

namespace App\Http\Controllers;

use App\Models\TireProduct;
use App\Services\TabularImportReader;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class TireCatalogController extends Controller
{
    public function index(Request $request): View
    {
        $org = $request->user()->organization_id;
        $query = TireProduct::where('organization_id', $org);
        if ($q = trim((string) $request->query('q'))) $query->where(fn ($x) => $x->where('sku','like',"%{$q}%")->orWhere('brand','like',"%{$q}%")->orWhere('model','like',"%{$q}%")->orWhere('size','like',"%{$q}%"));
        if (in_array($request->query('season'), ['summer','winter','all_season'], true)) $query->where('season', $request->query('season'));
        if ($request->query('stock') === 'available') $query->where('stock_quantity','>',0);
        if ($request->query('stock') === 'empty') $query->where('stock_quantity',0);
        $sorts=['newest'=>['created_at','desc'],'sku'=>['sku','asc'],'brand'=>['brand','asc'],'size'=>['size','asc'],'stock_low'=>['stock_quantity','asc'],'stock_high'=>['stock_quantity','desc'],'price_low'=>['price_cents','asc'],'price_high'=>['price_cents','desc']];
        [$column,$direction]=$sorts[$request->query('sort','newest')]??$sorts['newest'];
        return view('admin.tire-catalog',[
            'products'=>$query->orderBy($column,$direction)->paginate(50)->withQueryString(),
            'stats'=>['total'=>TireProduct::where('organization_id',$org)->count(),'available'=>TireProduct::where('organization_id',$org)->where('stock_quantity','>',0)->count(),'units'=>TireProduct::where('organization_id',$org)->sum('stock_quantity'),'empty'=>TireProduct::where('organization_id',$org)->where('stock_quantity',0)->count()],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $rows=[['sku'=>$request->input('sku'),'brand'=>$request->input('brand'),'model'=>$request->input('model'),'size'=>$request->input('size'),'season'=>$request->input('season'),'price'=>$request->input('price'),'cost'=>$request->input('cost'),'stock_quantity'=>$request->input('stock_quantity'),'studded'=>$request->boolean('studded')?'ja':'nei']];
        $result=$this->importRows($rows,$request->user()->organization_id);
        return $result['failed'] ? back()->withErrors(['product'=>$result['errors'][0]])->withInput() : back()->with('success','Dekket er lagt til i katalogen.');
    }

    public function bulk(Request $request): RedirectResponse
    {
        $data=$request->validate(['rows'=>['required','string','max:1000000']]);
        $lines=preg_split('/\R/u',trim($data['rows']));
        if(count($lines)>1000)return back()->withErrors(['rows'=>'Du kan lime inn maksimalt 1 000 rader om gangen.'])->withInput();
        $rows=[];
        foreach($lines as$line){if(trim($line)==='')continue;$parts=str_getcsv($line,str_contains($line,"\t")?"\t":';');$rows[]=['sku'=>$parts[0]??null,'brand'=>$parts[1]??null,'model'=>$parts[2]??null,'size'=>$parts[3]??null,'season'=>$parts[4]??null,'price'=>$parts[5]??null,'cost'=>$parts[6]??null,'stock_quantity'=>$parts[7]??null,'studded'=>$parts[8]??null];}
        if(isset($rows[0])&&in_array(mb_strtolower(trim((string)$rows[0]['sku'])),['varenummer','sku','artikkelnummer'],true))array_shift($rows);
        $result=$this->importRows($rows,$request->user()->organization_id);
        return back()->with('success',$result['imported'].' produkter ble lagt inn eller oppdatert.')->with('product_import_report',$result);
    }

    public function upload(Request $request, TabularImportReader $reader): RedirectResponse
    {
        @set_time_limit(300);
        $data=$request->validate(['file'=>['required','file','mimes:csv,txt,xlsx','max:51200']]);$ext=strtolower($data['file']->getClientOriginalExtension());if(!in_array($ext,['csv','xlsx'],true))return back()->withErrors(['file'=>'Bruk CSV eller Excel (.xlsx).']);$path=$data['file']->store('imports');
        try{$parsed=$reader->read(Storage::path($path),$ext);if(count($parsed['rows'])>5000)throw new \RuntimeException('Maksimalt 5 000 produkter per fil.');$map=$this->detectHeaders($parsed['headers']);foreach(['sku','brand','model','size','season','price','stock_quantity']as$required)if(!isset($map[$required]))throw new \RuntimeException('Mangler kolonnen «'.$required.'». Last ned malen for riktig oppsett.');$rows=[];foreach($parsed['rows']as$row){$mapped=[];foreach($map as$key=>$header)$mapped[$key]=$row[$header]??null;$rows[]=$mapped;}$result=$this->importRows($rows,$request->user()->organization_id);return back()->with('success',$result['imported'].' produkter ble importert fra '.$data['file']->getClientOriginalName().'.')->with('product_import_report',$result);}catch(\Throwable$e){return back()->withErrors(['file'=>$e->getMessage()]);}finally{Storage::delete($path);}
    }

    public function update(Request $request,TireProduct $product):RedirectResponse
    {
        abort_unless($product->organization_id===$request->user()->organization_id,404);$data=$request->validate(['price'=>['required','numeric','between:0,1000000'],'stock_quantity'=>['required','integer','between:0,1000000'],'active'=>['nullable','boolean']]);$product->update(['price_cents'=>(int)round($data['price']*100),'stock_quantity'=>$data['stock_quantity'],'active'=>$request->boolean('active')]);return back()->with('success',$product->sku.' er oppdatert.');
    }

    public function template():StreamedResponse
    {
        return response()->streamDownload(function(){$out=fopen('php://output','wb');fwrite($out,"\xEF\xBB\xBF");fputcsv($out,['Varenummer','Merke','Modell','Dimensjon','Sesong','Pris inkl mva','Innkjøpspris','Lagerantall','Pigg'],';','"','\\');fputcsv($out,['NOK-2055516-WR','Nokian','Hakkapeliitta R5','205/55 R16','Vinter','1499,00','980,00','24','Nei'],';','"','\\');fclose($out);},'dekkpilot-dekkatalog-mal.csv',['Content-Type'=>'text/csv; charset=UTF-8']);
    }

    private function importRows(array $rows,int $org):array
    {
        $imported=0;$errors=[];DB::transaction(function()use($rows,$org,&$imported,&$errors){foreach($rows as$i=>$row){try{$sku=strtoupper(trim((string)($row['sku']??'')));$brand=trim((string)($row['brand']??''));$model=trim((string)($row['model']??''));$size=strtoupper(preg_replace('/\s+/',' ',trim((string)($row['size']??''))));$season=$this->season($row['season']??'');$price=$this->number($row['price']??null);$cost=filled($row['cost']??null)?$this->number($row['cost']):null;$stock=(int)$this->number($row['stock_quantity']??0);if($sku===''||$brand===''||$model===''||$size===''||!$season||$price<0||$stock<0)throw new \RuntimeException('mangler eller har ugyldige obligatoriske felt');TireProduct::updateOrCreate(['organization_id'=>$org,'sku'=>$sku],['public_id'=>(string)(TireProduct::withTrashed()->where('organization_id',$org)->where('sku',$sku)->value('public_id')?:Str::uuid()),'brand'=>$brand,'model'=>$model,'size'=>$size,'season'=>$season,'studded'=>in_array(mb_strtolower(trim((string)($row['studded']??''))),['1','ja','yes','true','pigg'],true),'price_cents'=>(int)round($price*100),'cost_cents'=>$cost===null?null:(int)round($cost*100),'stock_quantity'=>$stock,'active'=>true,'deleted_at'=>null]);$imported++;}catch(\Throwable$e){if(count($errors)<50)$errors[]='Rad '.($i+1).': '.$e->getMessage();}}});return ['imported'=>$imported,'failed'=>count($rows)-$imported,'errors'=>$errors];
    }
    private function number(mixed$value):float{$clean=str_replace([' ','kr'],['',''],mb_strtolower(trim((string)$value)));if(str_contains($clean,',')&&str_contains($clean,'.')){$clean=strrpos($clean,',')>strrpos($clean,'.')?str_replace(',','.',str_replace('.','',$clean)):str_replace(',','',$clean);}elseif(str_contains($clean,','))$clean=str_replace(',','.',$clean);if(!is_numeric($clean))throw new \RuntimeException('ugyldig pris eller lagerantall');return(float)$clean;}
    private function season(mixed$value):?string{return match(mb_strtolower(trim((string)$value))){'summer','sommer'=>'summer','winter','vinter'=>'winter','all_season','helår','helaar','helars'=>'all_season',default=>null};}
    private function detectHeaders(array$headers):array{$aliases=['sku'=>['varenummer','sku','artikkelnummer'],'brand'=>['merke','brand'],'model'=>['modell','model'],'size'=>['dimensjon','størrelse','storrelse','size'],'season'=>['sesong','season'],'price'=>['pris inkl mva','pris','utsalgspris'],'cost'=>['innkjøpspris','kostpris','cost'],'stock_quantity'=>['lagerantall','lager','antall','stock'],'studded'=>['pigg','piggdekk','studded']];$map=[];foreach($headers as$header)foreach($aliases as$key=>$names)if(in_array(mb_strtolower(trim($header)),$names,true))$map[$key]=$header;return$map;}
}

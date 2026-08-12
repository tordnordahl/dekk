<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Organization;
use App\Models\TireProduct;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Tests\TestCase;

class TireCatalogTest extends TestCase
{
    use RefreshDatabase;

    private function owner(): User
    {
        $organization=Organization::create(['public_id'=>Str::uuid(),'name'=>'Dekk AS']);
        $branch=Branch::create(['public_id'=>Str::uuid(),'organization_id'=>$organization->id,'name'=>'Hoved','code'=>'HOVED']);
        return User::factory()->create(['organization_id'=>$organization->id,'branch_id'=>$branch->id,'role'=>'owner','active'=>true]);
    }

    public function test_many_spreadsheet_rows_are_upserted_by_sku(): void
    {
        $owner=$this->owner();
        $rows="Varenummer\tMerke\tModell\tDimensjon\tSesong\tPris\tInnkjøpspris\tLager\tPigg\nSKU-1\tNokian\tR5\t205/55 R16\tVinter\t1499,00\t900,00\t24\tNei\nSKU-2\tMichelin\tPrimacy\t225/45 R18\tSommer\t1899\t1200\t12\tNei";
        $this->actingAs($owner)->post(route('admin.tires.bulk'),['rows'=>$rows])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertDatabaseHas('tire_products',['organization_id'=>$owner->organization_id,'sku'=>'SKU-1','price_cents'=>149900,'stock_quantity'=>24]);
        $this->actingAs($owner)->post(route('admin.tires.bulk'),['rows'=>"SKU-1\tNokian\tR5\t205/55 R16\tVinter\t1599\t950\t30\tNei"])->assertRedirect();
        $this->assertSame(1,TireProduct::where('organization_id',$owner->organization_id)->where('sku','SKU-1')->count());
        $this->assertDatabaseHas('tire_products',['sku'=>'SKU-1','price_cents'=>159900,'stock_quantity'=>30]);
    }

    public function test_csv_catalog_import_and_catalog_search_are_tenant_isolated(): void
    {
        $owner=$this->owner();
        $csv="Varenummer;Merke;Modell;Dimensjon;Sesong;Pris inkl mva;Innkjøpspris;Lagerantall;Pigg\nCSV-1;Continental;VikingContact;235/55 R19;Vinter;2199,00;1500,00;8;Nei\n";
        $file=UploadedFile::fake()->createWithContent('dekk.csv',$csv);
        $this->actingAs($owner)->post(route('admin.tires.upload'),['file'=>$file])->assertRedirect()->assertSessionHasNoErrors();
        $this->actingAs($owner)->get(route('admin.tires',['q'=>'VikingContact']))->assertOk()->assertSee('CSV-1');

        $other=$this->owner();
        $this->actingAs($other)->get(route('admin.tires',['q'=>'VikingContact']))->assertOk()->assertDontSee('CSV-1');
    }
}

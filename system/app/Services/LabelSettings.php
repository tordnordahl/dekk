<?php
namespace App\Services;
use App\Models\Organization;
use Illuminate\Validation\Rule;
class LabelSettings
{
    public const TEMPLATES = ['standard'=>'Standard','location'=>'Tydelig lagerplass','simple'=>'Enkel'];
    public const SIZES = ['100x50'=>[100,50], '110x74'=>[110,74], '102x203'=>[101.6,203.2]];
    public static function defaults(): array { return ['template'=>'standard','size'=>'100x50','show_qr'=>true,'text_scale'=>100]; }
    public static function forOrganization(int $id): array {
        $raw=Organization::whereKey($id)->value('label_settings');
        if (is_string($raw)) $raw=json_decode($raw,true);
        return array_replace(self::defaults(),is_array($raw)?$raw:[]);
    }
    public static function rules(): array { return ['template'=>['required',Rule::in(array_keys(self::TEMPLATES))],'size'=>['required',Rule::in(array_keys(self::SIZES))],'show_qr'=>['required','boolean'],'text_scale'=>['required','integer',Rule::in([100,125,150])]]; }
}

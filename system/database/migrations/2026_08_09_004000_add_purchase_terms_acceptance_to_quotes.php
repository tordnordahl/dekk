<?php
use Illuminate\Database\Migrations\Migration;use Illuminate\Database\Schema\Blueprint;use Illuminate\Support\Facades\Schema;
return new class extends Migration{public function up():void{Schema::table('quotes',function(Blueprint $table){$table->timestamp('purchase_terms_accepted_at')->nullable()->after('responded_at');$table->string('purchase_terms_version',20)->nullable()->after('purchase_terms_accepted_at');});}public function down():void{Schema::table('quotes',fn(Blueprint $table)=>$table->dropColumn(['purchase_terms_accepted_at','purchase_terms_version']));}};

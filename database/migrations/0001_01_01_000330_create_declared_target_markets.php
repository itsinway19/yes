<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(file_get_contents(database_path('sql/business_context_lock_order.sql')));
        DB::unprepared(file_get_contents(database_path('sql/declared_target_markets.sql')));
        $labels = json_decode(file_get_contents(database_path('reference/countries/labels.json')), true, flags: JSON_THROW_ON_ERROR);
        foreach (array_chunk($labels, 100) as $chunk) {
            DB::table('country_references')->insert($chunk);
        }
    }

    public function down(): void
    {
        throw new LogicException('Business Context requires forward migration; do not erase authority or history.');
    }
};

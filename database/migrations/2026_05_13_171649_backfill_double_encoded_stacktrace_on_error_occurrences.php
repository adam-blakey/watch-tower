<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('error_occurrences')
            ->select('id', 'stacktrace')
            ->orderBy('id')
            ->chunkById(500, function ($rows) {
                foreach ($rows as $row) {
                    $value = json_decode($row->stacktrace, true);

                    if (is_string($value)) {
                        $inner = json_decode($value, true);

                        if (is_array($inner)) {
                            DB::table('error_occurrences')
                                ->where('id', $row->id)
                                ->update(['stacktrace' => json_encode($inner)]);
                        }
                    }
                }
            });
    }

    public function down(): void {}
};

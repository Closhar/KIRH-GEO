<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            throw new RuntimeException('KIRH GEO requires PostgreSQL with PostGIS; SQLite is not supported.');
        }
        $schema = file_get_contents(__DIR__.'/../schema/platform.sql');
        $schema = preg_replace('/^(BEGIN;|COMMIT;)\s*$/m', '', $schema);
        DB::unprepared($schema);
    }

    public function down(): void
    {
        throw new RuntimeException('Platform rollback is intentionally forward-only. Restore a verified backup or use an explicit corrective migration.');
    }
};

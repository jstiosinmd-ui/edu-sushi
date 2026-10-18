<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Bulk updates bypass Eloquent events. Keep legacy availability writes
        // consistent with v3 status at the database boundary as well.
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION sushi_sync_question_status() RETURNS trigger AS $$
            BEGIN
                IF TG_OP = 'INSERT' THEN
                    IF NEW.status <> 'Active' THEN
                        NEW.active := FALSE;
                    ELSIF NEW.active = FALSE THEN
                        NEW.status := 'Retired';
                    END IF;
                ELSIF NEW.status IS DISTINCT FROM OLD.status THEN
                    NEW.active := (NEW.status = 'Active');
                ELSIF NEW.active IS DISTINCT FROM OLD.active THEN
                    NEW.status := CASE WHEN NEW.active THEN 'Active' ELSE 'Retired' END;
                END IF;
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;
            CREATE TRIGGER questions_sync_availability
                BEFORE INSERT OR UPDATE OF active, status ON questions
                FOR EACH ROW EXECUTE FUNCTION sushi_sync_question_status();
            SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS questions_sync_availability ON questions; DROP FUNCTION IF EXISTS sushi_sync_question_status();');
    }
};

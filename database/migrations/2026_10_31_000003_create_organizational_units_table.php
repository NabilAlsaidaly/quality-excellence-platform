<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('organizational_units', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete()->restrictOnUpdate();
            $table->unsignedBigInteger('parent_id')->nullable();
            $table->string('name');
            $table->timestamps();

            $table->unique(['organization_id', 'id'], 'units_organization_id_unique');
            $table->index(['organization_id', 'parent_id'], 'units_organization_parent_index');
            $table->foreign(['organization_id', 'parent_id'], 'units_same_organization_parent_fk')
                ->references(['organization_id', 'id'])->on('organizational_units')
                ->restrictOnDelete()->restrictOnUpdate();
        });

        // MySQL CHECK expressions cannot reference AUTO_INCREMENT id columns.
        // These row-local guards prevent direct self-parenting, not longer cycles.
        if (DB::getDriverName() === 'mysql') {
            foreach (['INSERT', 'UPDATE'] as $event) {
                $name = 'units_no_self_parent_'.strtolower($event);
                DB::unprepared("CREATE TRIGGER {$name} BEFORE {$event} ON organizational_units
                    FOR EACH ROW BEGIN
                        IF NEW.parent_id IS NOT NULL AND NEW.parent_id = NEW.id THEN
                            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'A unit cannot be its own parent';
                        END IF;
                    END");
            }
        }
    }

    public function down(): void
    {
        // Dropping the table also drops its MySQL triggers.
        Schema::dropIfExists('organizational_units');
    }
};

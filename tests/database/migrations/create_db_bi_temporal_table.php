<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bi_temporal_items', function (Blueprint $table) {
            $table->unsignedBigInteger('id');
            $table->date('valid_from');
            $table->date('valid_to');
            $table->dateTime('known_from');
            $table->dateTime('known_to');
            $table->string('text');
            $table->timestamps();
            $table->primary(['id', 'valid_to', 'known_to']);
        });
    }
};

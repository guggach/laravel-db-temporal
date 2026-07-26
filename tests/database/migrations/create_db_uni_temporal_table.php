<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {

        Schema::create('uni_temporal_ids', function (Blueprint $table) {
            $table->unsignedBigInteger('id');
            $table->dateTime('known_from');
            $table->dateTime('known_to');
            $table->softDeletes();
            $table->string('text')->nullable();
            $table->timestamps();
            $table->primary(['id', 'known_from', 'known_to']);
        });

        Schema::create('uni_temporal_id_belives', function (Blueprint $table) {
            $table->unsignedBigInteger('id');
            $table->dateTime('belive_from');
            $table->dateTime('belive_until');
            $table->softDeletes();
            $table->string('text')->nullable();
            $table->timestamps();
            $table->primary(['id', 'belive_from', 'belive_until']);
        });

        Schema::create('uni_temporal_id_trx_dates', function (Blueprint $table) {
            $table->unsignedBigInteger('id');
            $table->dateTime('trx_date_from');
            $table->dateTime('trx_date_to');
            $table->softDeletes();
            $table->string('text')->nullable();
            $table->timestamps();
            $table->primary(['id', 'trx_date_from', 'trx_date_to']);
        });

        Schema::create('uni_temporal_ulids', function (Blueprint $table) {
            $table->ulid('id');
            $table->dateTime('known_from');
            $table->dateTime('known_to');
            $table->softDeletes();
            $table->string('text')->nullable();
            $table->timestamps();
            $table->primary(['id', 'known_from', 'known_to']);
        });

    }
};

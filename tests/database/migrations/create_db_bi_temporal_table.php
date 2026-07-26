<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('bi_temporal', function (Blueprint $table) {
            $table->unsignedBigInteger('id');
            $table->dateTime('valid_from');
            $table->dateTime('valid_until');
            $table->softDeletes();
            $table->dateTime('known_from');
            $table->dateTime('known_until');
            $table->string('text');
            $table->timestamps();
        });
    }
};

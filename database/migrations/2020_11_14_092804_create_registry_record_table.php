<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class CreateRegistryRecordTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('registry_record', function (Blueprint $table) {
            $table->bigIncrements('id');

            $table->string('source_filename', 255)->nullable();
            $table->string('out_filename', 255)->nullable();
            $table->integer('rows_count')->nullable();
            $table->integer('rows_success')->nullable();
            $table->integer('rows_warning')->nullable();
            $table->float('progress')->default(0);

            $table->unsignedBigInteger('user_id');
            $table->foreign('user_id')->references('id')->on('users');

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('registry_record');
    }
}

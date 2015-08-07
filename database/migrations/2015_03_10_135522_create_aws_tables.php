<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

/**
 * Class CreateAwsTables
 */
class CreateAwsTables extends Migration
{

    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        // AWS Service Configuration
        Schema::create(
            'aws_config',
            function (Blueprint $t){
                $t->integer('service_id')->unsigned()->primary();
                $t->foreign('service_id')->references('id')->on('service')->onDelete('cascade');
                $t->longText('key')->nullable();
                $t->longText('secret')->nullable();
                $t->string('region')->nullable();
            }
        );
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        // AWS Service Configuration
        Schema::dropIfExists('aws_config');
    }
}

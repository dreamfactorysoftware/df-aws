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
            function ( Blueprint $t )
            {
                $t->integer( 'id' )->unsigned()->primary();
                $t->longText( 'key' )->nullable();
                $t->longText( 'secret' )->nullable();
                $t->string( 'region' )->nullable();
            }
        );

        Schema::create('aws_config_to_service', function(Blueprint $t)
        {
            $t->integer('aws_config_id')->unsigned()->index();
            $t->foreign('aws_config_id')->references('id')->on('aws_config')->onDelete('cascade');
            $t->integer('service_id')->unsigned()->index();
            $t->foreign('service_id')->references('id')->on('service')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        // AWS Service Configuration
        Schema::dropIfExists( 'aws_config' );
        Schema::dropIfExists( 'aws_config_to_service' );
    }

}

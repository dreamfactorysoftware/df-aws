<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class AddProxyField extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        if (Schema::hasTable('aws_config') && !Schema::hasColumn('aws_config', 'proxy')) {
            Schema::table('aws_config', function (Blueprint $t){
                $t->string('proxy')->nullable();
            });
        }
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        if (Schema::hasTable('aws_config') && Schema::hasColumn('aws_config', 'proxy')) {
            Schema::table('aws_config', function (Blueprint $t){
                $t->dropColumn('proxy');
            });
        }
    }
}

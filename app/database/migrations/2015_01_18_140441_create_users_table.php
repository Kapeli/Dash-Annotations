<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class CreateUsersTable extends Migration {

	public function up()
	{
	    Schema::create('users', function($table)
	    {
	        $table->increments('id');
	        $table->string('username')->unique();
	        $table->string('email')->nullable();
	        $table->string('password');
	        $table->boolean('moderator');
	        $table->string('remember_token')->nullable();
	        $table->timestamps();
	    });
	}

	public function down()
	{
	    Schema::drop('users');
	}

}

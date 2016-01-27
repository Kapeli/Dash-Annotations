<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class CreateUsersTable extends Migration {

	public function up()
	{
	    Schema::create('users', function($table)
	    {
	        $table->increments('id');
	        $table->string('username', 191)->unique();
	        $table->string('email', 300)->nullable();
	        $table->string('password', 500);
	        $table->boolean('moderator')->default(FALSE);
	        $table->string('remember_token', 500)->nullable();
	        $table->timestamps();
	    });
	}

	public function down()
	{
	    Schema::drop('users');
	}

}

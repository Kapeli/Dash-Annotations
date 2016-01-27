<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class CreateTeamsTable extends Migration {

	public function up()
	{
		Schema::create('teams', function($table)
		{
		    $table->increments('id');
		    $table->string('name', 191)->unique();
		    $table->string('access_key', 500)->nullable();
		    $table->timestamps();
		});
		Schema::create('team_user', function($table)
		{
		    $table->increments('id');
		    $table->integer('team_id')->unsigned();
		    $table->foreign('team_id')->references('id')->on('teams');
		    $table->integer('user_id')->unsigned();
		    $table->foreign('user_id')->references('id')->on('users');
		    $table->string('role');
		    $table->timestamps();
		});
	}

	public function down()
	{
		Schema::drop('team_user');
		Schema::drop('teams');
	}

}

<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class CreateEntriesTable extends Migration {

	public function up()
	{
		Schema::create('identifiers', function($table)
		{
		    $table->increments('id');
		    $table->string('docset_name');
		    $table->string('docset_filename');
		    $table->string('docset_platform');
		    $table->string('docset_bundle');
		    $table->string('docset_version');
		    $table->mediumText('page_path');
		    $table->boolean('banned_from_public');
		    $table->timestamps();
		});

		Schema::create('licenses', function($table)
		{
		    $table->increments('id');
		    $table->mediumText('license');
		    $table->boolean('banned_from_public');
		    $table->timestamps();
		});

		Schema::create('entries', function($table)
		{
		    $table->increments('id');
		    $table->string('title');
		    $table->longText('body');
		    $table->longText('body_rendered');
		    $table->string('type');
		    $table->integer('identifier_id')->unsigned();
		    $table->foreign('identifier_id')->references('id')->on('identifiers');
		    $table->integer('license_id')->unsigned()->nullable();
		    $table->foreign('license_id')->references('id')->on('licenses');
		    $table->string('anchor', 1500);
		    $table->integer('user_id')->unsigned();
		    $table->foreign('user_id')->references('id')->on('users');
		    $table->boolean('public');
		    $table->boolean('removed_from_public');
		    $table->integer('score');
		    $table->timestamps();
		});
		Schema::create('entry_team', function($table)
		{
		    $table->increments('id');
		    $table->integer('entry_id')->unsigned();
		    $table->foreign('entry_id')->references('id')->on('entries');
		    $table->integer('team_id')->unsigned();
		    $table->foreign('team_id')->references('id')->on('teams');
		    $table->boolean('removed_from_team');
		    $table->timestamps();
		});
		Schema::create('votes', function($table)
		{
		    $table->increments('id');
		    $table->tinyInteger('type');
		    $table->integer('entry_id')->unsigned();
		    $table->foreign('entry_id')->references('id')->on('entries');
		    $table->integer('user_id')->unsigned();
		    $table->foreign('user_id')->references('id')->on('users');
		    $table->timestamps();
		});
	}

	public function down()
	{
		Schema::drop('entry_team');
		Schema::drop('votes');
		Schema::drop('entries');
		Schema::drop('licenses');
     	Schema::drop('identifiers');
	}

}

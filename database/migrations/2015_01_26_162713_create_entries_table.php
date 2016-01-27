<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class CreateEntriesTable extends Migration {

	public function up()
	{
		Schema::create('identifiers', function($table)
		{
		    $table->increments('id');
		    $table->string('docset_name', 340);
		    $table->string('docset_filename', 340);
		    $table->string('docset_platform', 340);
		    $table->string('docset_bundle', 340);
		    $table->string('docset_version', 340);
		    $table->longText('page_path');
		    $table->string('page_title', 340);
		    $table->longText('httrack_source');
		    $table->boolean('banned_from_public')->default(FALSE);
		    $table->timestamps();
		});

		Schema::create('licenses', function($table)
		{
		    $table->increments('id');
		    $table->longText('license');
		    $table->boolean('banned_from_public')->default(FALSE);
		    $table->timestamps();
		});

		Schema::create('entries', function($table)
		{
		    $table->increments('id');
		    $table->string('title', 340);
		    $table->longText('body');
		    $table->longText('body_rendered');
		    $table->string('type');
		    $table->integer('identifier_id')->unsigned();
		    $table->foreign('identifier_id')->references('id')->on('identifiers');
		    $table->integer('license_id')->unsigned()->nullable();
		    $table->foreign('license_id')->references('id')->on('licenses');
		    $table->string('anchor', 2000);
		    $table->integer('user_id')->unsigned();
		    $table->foreign('user_id')->references('id')->on('users');
		    $table->boolean('public');
		    $table->boolean('removed_from_public')->default(FALSE);
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
		    $table->boolean('removed_from_team')->default(FALSE);
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

<?php

Route::post('users/register', 'UsersController@register');
Route::post('users/login', 'UsersController@login');
Route::get('users/logout', 'UsersController@logout');
Route::post('users/email', 'UsersController@change_email');
Route::post('users/password', 'UsersController@change_password');
Route::post('users/forgot/request', 'ForgotController@request');
Route::post('users/forgot/reset', 'ForgotController@reset');

Route::post('teams/create', 'TeamsController@create');
Route::post('teams/join', 'TeamsController@join');
Route::post('teams/leave', 'TeamsController@leave');
Route::post('teams/set_role', 'TeamsController@set_role');
Route::post('teams/remove_member', 'TeamsController@remove_member');
Route::post('teams/set_access_key', 'TeamsController@set_access_key');
Route::post('teams/list', 'TeamsController@list_teams');
Route::post('teams/list_members', 'TeamsController@list_members');

Route::post('entries/list', 'EntriesController@list_entries');
Route::post('entries/save', 'EntriesController@save');
Route::post('entries/create', 'EntriesController@save');
Route::post('entries/get', 'EntriesController@get');
Route::post('entries/vote', 'EntriesController@vote');
Route::post('entries/delete', 'EntriesController@delete');
Route::post('entries/remove_from_public', 'EntriesController@remove_from_public');
Route::post('entries/remove_from_teams', 'EntriesController@remove_from_teams');

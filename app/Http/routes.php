<?php

$app->post('users/register', 'App\Http\Controllers\UsersController@register');
$app->post('users/login', 'App\Http\Controllers\UsersController@login');
$app->get('users/logout', 'App\Http\Controllers\UsersController@logout');
$app->post('users/email', 'App\Http\Controllers\UsersController@change_email');
$app->post('users/password', 'App\Http\Controllers\UsersController@change_password');
$app->post('users/forgot/request', 'App\Http\Controllers\ForgotController@request');
$app->post('users/forgot/reset', 'App\Http\Controllers\ForgotController@reset');

$app->post('teams/create', 'App\Http\Controllers\TeamsController@create');
$app->post('teams/join', 'App\Http\Controllers\TeamsController@join');
$app->post('teams/leave', 'App\Http\Controllers\TeamsController@leave');
$app->post('teams/set_role', 'App\Http\Controllers\TeamsController@set_role');
$app->post('teams/remove_member', 'App\Http\Controllers\TeamsController@remove_member');
$app->post('teams/set_access_key', 'App\Http\Controllers\TeamsController@set_access_key');
$app->post('teams/list', 'App\Http\Controllers\TeamsController@list_teams');
$app->post('teams/list_members', 'App\Http\Controllers\TeamsController@list_members');

$app->post('entries/list', 'App\Http\Controllers\EntriesController@list_entries');
$app->post('entries/save', 'App\Http\Controllers\EntriesController@save');
$app->post('entries/create', 'App\Http\Controllers\EntriesController@save');
$app->post('entries/get', 'App\Http\Controllers\EntriesController@get');
$app->post('entries/vote', 'App\Http\Controllers\EntriesController@vote');
$app->post('entries/delete', 'App\Http\Controllers\EntriesController@delete');
$app->post('entries/remove_from_public', 'App\Http\Controllers\EntriesController@remove_from_public');
$app->post('entries/remove_from_teams', 'App\Http\Controllers\EntriesController@remove_from_teams');

$app->get('/', function()
{
    return "Nothing to see here. Move along.";
});

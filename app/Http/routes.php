<?php

/** @var \Laravel\Lumen\Routing\Router $router */

$router->post('users/register', 'App\Http\Controllers\UsersController@register');
$router->post('users/login', 'App\Http\Controllers\UsersController@login');
$router->get('users/logout', 'App\Http\Controllers\UsersController@logout');
$router->post('users/email', 'App\Http\Controllers\UsersController@change_email');
$router->post('users/password', 'App\Http\Controllers\UsersController@change_password');
$router->post('users/forgot/request', 'App\Http\Controllers\ForgotController@request');
$router->post('users/forgot/reset', 'App\Http\Controllers\ForgotController@reset');

$router->post('teams/create', 'App\Http\Controllers\TeamsController@create');
$router->post('teams/join', 'App\Http\Controllers\TeamsController@join');
$router->post('teams/leave', 'App\Http\Controllers\TeamsController@leave');
$router->post('teams/set_role', 'App\Http\Controllers\TeamsController@set_role');
$router->post('teams/remove_member', 'App\Http\Controllers\TeamsController@remove_member');
$router->post('teams/set_access_key', 'App\Http\Controllers\TeamsController@set_access_key');
$router->post('teams/list', 'App\Http\Controllers\TeamsController@list_teams');
$router->post('teams/list_members', 'App\Http\Controllers\TeamsController@list_members');

$router->post('entries/list', 'App\Http\Controllers\EntriesController@list_entries');
$router->post('entries/save', 'App\Http\Controllers\EntriesController@save');
$router->post('entries/create', 'App\Http\Controllers\EntriesController@save');
$router->post('entries/get', 'App\Http\Controllers\EntriesController@get');
$router->post('entries/vote', 'App\Http\Controllers\EntriesController@vote');
$router->post('entries/delete', 'App\Http\Controllers\EntriesController@delete');
$router->post('entries/remove_from_public', 'App\Http\Controllers\EntriesController@remove_from_public');
$router->post('entries/remove_from_teams', 'App\Http\Controllers\EntriesController@remove_from_teams');

$router->get('/', function() use ($router)
{
    return "Nothing to see here. Move along.";
});

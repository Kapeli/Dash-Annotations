<?php

return [

	/*
	|--------------------------------------------------------------------------
	| Authentication Defaults
	|--------------------------------------------------------------------------
	|
	| This option controls the default authentication "guard" and password
	| reset options for your application.
	|
	*/

	'defaults' => [
		'guard' => 'web',
		'passwords' => 'users',
	],

	/*
	|--------------------------------------------------------------------------
	| Authentication Guards
	|--------------------------------------------------------------------------
	|
	| Next, you may define every authentication guard for your application.
	| A default configuration has been defined here which uses session storage.
	|
	*/

	'guards' => [
		'web' => [
			'driver' => 'session',
			'provider' => 'users',
		],
	],

	/*
	|--------------------------------------------------------------------------
	| User Providers
	|--------------------------------------------------------------------------
	|
	| All authentication drivers have a user provider. This defines how the
	| users are actually retrieved out of your database or other storage
	| mechanisms used by this application to persist your user's data.
	|
	*/

	'providers' => [
		'users' => [
			'driver' => 'eloquent',
			'model' => App\User::class,
		],
	],

	/*
	|--------------------------------------------------------------------------
	| Password Reset Settings
	|--------------------------------------------------------------------------
	|
	| Here you may set the options for resetting passwords including the view
	| that is your password reset e-mail. You can also set the name of the
	| table that maintains all of the reset tokens for your application.
	|
	*/

	'passwords' => [
		'users' => [
			'provider' => 'users',
			'table' => 'password_reminders',
			'expire' => 60,
		],
	],

];

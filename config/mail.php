<?php

return [

	/*
	|--------------------------------------------------------------------------
	| Default Mailer
	|--------------------------------------------------------------------------
	|
	| This option controls the default mailer that is used to send any email
	| messages sent by your application. Alternative mailers may be setup
	| and used as needed; however, this mailer will be used by default.
	|
	*/

	'default' => env('MAIL_MAILER', 'mail'),

	/*
	|--------------------------------------------------------------------------
	| Mailer Configurations
	|--------------------------------------------------------------------------
	|
	| Here you may configure all of the mailers used by your application plus
	| their respective settings. Several examples have been configured for
	| you and you are free to add your own as your application requires.
	|
	*/

	'mailers' => [
		'mail' => [
			'transport' => 'mail',
		],

		'sendmail' => [
			'transport' => 'sendmail',
			'path' => env('MAIL_SENDMAIL_PATH', '/usr/sbin/sendmail -bs'),
		],
	],

	/*
	|--------------------------------------------------------------------------
	| Global "From" Address
	|--------------------------------------------------------------------------
	|
	| You may wish for all e-mails sent by your application to be sent from
	| the same address. Here, you may specify a name and address that is
	| used globally for all e-mails that are sent by your application.
	|
	*/

	'from' => [
		'address' => env('MAIL_FROM_ADDRESS', 'annotations@kapeli.com'),
		'name' => env('MAIL_FROM_NAME', 'Dash Annotations'),
	],

];
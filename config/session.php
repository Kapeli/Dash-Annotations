<?php

return [

	/*
	|--------------------------------------------------------------------------
	| Default Session Driver
	|--------------------------------------------------------------------------
	|
	| This option controls the default session "driver" that will be used on
	| requests. By default, we will use the file driver but you are free to
	| use other drivers. file, cookie, database, apc, memcached, redis, array
	|
	*/

	'driver' => env('SESSION_DRIVER', 'file'),

	/*
	|--------------------------------------------------------------------------
	| Session Lifetime
	|--------------------------------------------------------------------------
	|
	| Here you may specify the number of minutes that you wish the session
	| to be allowed to remain idle before it expires.
	|
	*/

	'lifetime' => 120,

	'expire_on_close' => false,

	/*
	|--------------------------------------------------------------------------
	| Session Encryption
	|--------------------------------------------------------------------------
	|
	| This option allows you to easily specify that all of your session data
	| should be encrypted before it is stored.
	|
	*/

	'encrypt' => false,

	/*
	|--------------------------------------------------------------------------
	| Session File Location
	|--------------------------------------------------------------------------
	|
	| When using the file session driver, we need a location where session
	| files may be stored. A default has been set for you.
	|
	*/

	'files' => storage_path('framework/sessions'),

	/*
	|--------------------------------------------------------------------------
	| Session Database Connection
	|--------------------------------------------------------------------------
	|
	| When using database session driver, you may specify connection.
	|
	*/

	'connection' => null,

	/*
	|--------------------------------------------------------------------------
	| Session Database Table
	|--------------------------------------------------------------------------
	|
	| When using database session driver, you may specify the table.
	|
	*/

	'table' => 'sessions',

	/*
	|--------------------------------------------------------------------------
	| Session Cache Store
	|--------------------------------------------------------------------------
	|
	| When using cache-based session drivers, you may specify the cache store.
	|
	*/

	'store' => null,

	/*
	|--------------------------------------------------------------------------
	| Session Sweeping Lottery
	|--------------------------------------------------------------------------
	|
	| Some session drivers must manually sweep their storage location to get
	| rid of old sessions from storage. Here are the chances that it will
	| happen on a given request. By default, the odds are 2 out of 100.
	|
	*/

	'lottery' => [2, 100],

	/*
	|--------------------------------------------------------------------------
	| Session Cookie Name
	|--------------------------------------------------------------------------
	|
	| Here you may change the name of the cookie used to identify a session
	| instance by ID. The name specified here will get used every time a
	| new session cookie is created by the framework for every driver.
	|
	*/

	'cookie' => env('SESSION_COOKIE', 'lumen_session'),

	/*
	|--------------------------------------------------------------------------
	| Session Cookie Path
	|--------------------------------------------------------------------------
	|
	| The session cookie path determines the path for which the cookie will
	| be regarded as available. Typically, this will be the root path.
	|
	*/

	'path' => '/',

	/*
	|--------------------------------------------------------------------------
	| Session Cookie Domain
	|--------------------------------------------------------------------------
	|
	| Here you may change the domain of the cookie used to identify a session
	| in your application. This will determine which domains the cookie is
	| available to in your application.
	|
	*/

	'domain' => env('SESSION_DOMAIN', null),

	/*
	|--------------------------------------------------------------------------
	| HTTPS Only Cookies
	|--------------------------------------------------------------------------
	|
	| By setting this option to true, session cookies will only be sent back
	| to the server if the browser has a HTTPS connection.
	|
	*/

	'secure' => env('SESSION_SECURE_COOKIE', false),

	/*
	|--------------------------------------------------------------------------
	| HTTP Access Only
	|--------------------------------------------------------------------------
	|
	| Setting this value to true will prevent JavaScript from accessing the
	| value of the cookie and the cookie will only be accessible through
	| the HTTP protocol.
	|
	*/

	'http_only' => true,

	/*
	|--------------------------------------------------------------------------
	| Same-Site Cookies
	|--------------------------------------------------------------------------
	|
	| This option determines how your cookies behave when cross-site requests
	| take place, and can be used to mitigate CSRF attacks. By default, we
	| do not enable this as other CSRF protection services are in place.
	|
	*/

	'same_site' => null,

];
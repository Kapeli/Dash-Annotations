<!DOCTYPE html>
<html lang="en-US">
	<head>
		<meta charset="utf-8">
	</head>
	<body>
		<div>
			<P>Your reset token is: {{{ $token }}}</P>
			<P>The token will expire in {{ Config::get('auth.reminder.expire', 60) }} minutes.</P>
		</div>
	</body>
</html>

<?php

class ForgotController extends BaseController {
	public function request()
	{
		$email = Input::get('email');
		if(!empty($email))
		{
		    $user = User::where('email', '=', $email)->first();
		    if($user == NULL)
		    {
		        return json_encode(['status' => 'error', 'message' => 'No user has this email']);
		    }
			$credentials = array('email' => $email);
			Password::remind($credentials, function($message)
			{
			    $message->subject('Password Reminder');
			});
			return json_encode(['status' => 'success']);
		}
		return json_encode(['status' => 'error', 'message' => 'Invalid email']);
	}

	public function reset()
	{

		$email = Input::get('email');
		if(!empty($email))
		{
		    $user = User::where('email', '=', $email)->first();
		    if($user == NULL)
		    {
		        return json_encode(['status' => 'error', 'message' => 'Invalid reset token']);
		    }
		    else
		    {
		    	$username = $user->username;
		    	$credentials = Input::only(
		    	      'email', 'password', 'token'
		    	);
		    	$credentials['password_confirmation'] = $credentials['password'];
		    	$credentials['username'] = $username;
		    	$result = Password::reset($credentials, function($user, $password)
		    	{
		    		$user->password = Hash::make($password);
		    		$user->save();
		    	});
		    	if(strcmp($result,"reminders.reset") == 0)
		    	{
		    		return json_encode(['status' => 'success', 'username' => $username]);
		    	}
			}
		}
		return json_encode(['status' => 'error', 'message' => 'Invalid reset token']);
	}
}

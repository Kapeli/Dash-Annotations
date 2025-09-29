<?php namespace App\Http\Controllers;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use App\User;
use Illuminate\Contracts\Auth\PasswordBroker;

class ForgotController extends Controller {

	public function request()
	{
		$email = Request::input('email');
		if(!empty($email))
		{
			$credentials = array('email' => $email);
			$response = Password::sendResetLink($credentials);
			switch ($response)
			{
			    case PasswordBroker::RESET_LINK_SENT:
				    return json_encode(['status' => 'success']);

			    case PasswordBroker::INVALID_USER:
				    return json_encode(['status' => 'error', 'message' => 'No user has this email']);
			}
		}
		return json_encode(['status' => 'error', 'message' => 'Invalid email']);
	}

	public function reset()
	{

		$email = Request::input('email');
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
		    	$credentials = Request::only(
		    	      'email', 'password', 'token'
		    	);
		    	$credentials['password_confirmation'] = $credentials['password'];
		    	$credentials['username'] = $username;
		    	$result = Password::reset($credentials, function($user, $password)
		    	{
		    		$user->password = Hash::make($password);
		    		$user->save();
		    	});
		    	switch ($result)
		    	{
		    		case PasswordBroker::PASSWORD_RESET:
			    		return json_encode(['status' => 'success', 'username' => $username]);
		    	}
		}
		}
		return json_encode(['status' => 'error', 'message' => 'Invalid reset token']);
	}
}

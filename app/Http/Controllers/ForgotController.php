<?php namespace App\Http\Controllers;

use Auth, Input, Hash;
use App\User;
use Illuminate\Contracts\Auth\PasswordBroker;
use Illuminate\Foundation\Auth\ResetsPasswords;
use Illuminate\Contracts\Auth\Guard;

class ForgotController extends Controller {
	use ResetsPasswords;

	public function __construct(Guard $auth, PasswordBroker $passwords)
	{
		$this->auth = $auth;
		$this->passwords = $passwords;
	}

	public function request()
	{
		$email = Input::get('email');
		if(!empty($email))
		{
			$credentials = array('email' => $email);
			$response = $this->passwords->sendResetLink($credentials, function($message)
			{
			    $message->subject('Password Reset');
			});
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
		    	$result = $this->passwords->reset($credentials, function($user, $password)
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

<?php namespace App\Http\Controllers;

use Validator, Input, Auth, Hash;
use App\User;

class UsersController extends Controller {

    public function register()
    {
        $validator = Validator::make(Input::all(), [
            "username" => ["required", "unique:users,username"],
            "password" => "required"
        ]);

        if($validator->passes()) 
        {
            $username = Input::get('username');
            $password = Input::get('password');
            $user = new User;
            $user->username = $username;
            $user->password = Hash::make($password);
            $user->save();
            return json_encode(['status' => 'success']);
        }
        else
        {
            return json_encode(['status' => 'error', 'message' => 'Username already taken']);
        }
        return json_encode(['status' => 'error']);
    }

    public function login()
    {
        $validator = Validator::make(Input::all(), [
            "username" => "required",
            "password" => "required"
        ]);

        if($validator->passes()) 
        {
            $credentials = [
                "username" => Input::get("username"),
                "password" => Input::get("password")
            ];
            if(Auth::attempt($credentials, true)) 
            {
                return json_encode(['status' => 'success', 'email' => Auth::user()->email]);
            }
        }
        return json_encode(['status' => 'error', 'message' => 'Invalid username or password']);
    }

    public function logout()
    {
        if(Auth::check())
        {
            Auth::logout(); 
            return json_encode(['status' => 'success']);
        }
        return json_encode(['status' => 'error', 'message' => 'Not logged in']);
    }

    public function change_email()
    {
        if(Auth::check())
        {
            $user = Auth::user();
            $email = Input::get('email');
            if(!empty($email))
            {
                $testUser = User::where('email', '=', $email)->first();
                if($testUser && $testUser->id != $user->id)
                {
                    return json_encode(['status' => 'error', 'message' => 'Email already used']);
                }
            }
            $user->email = $email;
            $user->save();
            return json_encode(['status' => 'success']);
        }
        return json_encode(['status' => 'error', 'message' => 'Error. Logout and try again']);
    }

    public function change_password()
    {
        if(Auth::check())
        {
            $user = Auth::user();
            $password = Input::get('password');
            $user->password = Hash::make($password);
            $user->save();
            return json_encode(['status' => 'success']);
        }
        return json_encode(['status' => 'error', 'message' => 'Error. Logout and try again']);
    }
}

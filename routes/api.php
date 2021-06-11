<?php

use Illuminate\Http\Request;


Route::post('auth/login', 'AuthController@login');

Route::post('sociallogin/{provider}', 'Auth\SocialController@SocialLogin');
Route::post('socialsignup', 'Auth\SocialController@SocialSignup');
Route::post('checklogin', 'Auth\SocialController@checklogin');
Route::get('auth/{provider}/callback', 'Auth\SocialController@index')->where('provider', '.*');
Route::post('auth/register', 'AuthController@register')->where('vue', '.*');


Route::post('auth/password/reset', 'ApiForgotPasswordController@create');
Route::post('auth/password/forgot', 'ApiForgotPasswordController@reset');
Route::post('find/user/email', 'AuthController@findEmail');


Route::get('auth/password/reset/{token}', 'AuthController@find');

Route::group(['middleware' => 'auth:api'], function () {
    Route::get('auth/user', 'AuthController@user');
    Route::post('auth/logout', 'AuthController@logout');
    Route::get('users', 'UserController@index');
    Route::post('users/signupuser', 'UserController@signupuser');
    Route::get('allusers', 'UserController@AllUsers');
    Route::post('users/makeadmin', 'UserController@MakeUserAdmin');
    Route::put('users/{user}', 'UserController@update');
  

});
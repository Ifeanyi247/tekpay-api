<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/', function (Request $request) {
   
    dd($locationData);
    return view('mail.login-attempt-mail');
});

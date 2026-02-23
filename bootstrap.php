<?php
use Illuminate\Support\Facades\Route;

return function ($plugin) {
    // 通过name或UUID获取头像
    Route::get('api/avatar/{identifier}', 'EarlyDreamLand\\APIExtension\\Controllers\\AvatarController@avatarByIdentifier');
    // 通过name或UUID获取全身图
    Route::get('api/full/{uuid}', 'EarlyDreamLand\\APIExtension\\Controllers\\VZGEFullController@FullByUuid');
    // 通过name或UUID获取半身图
    Route::get('api/bust/{uuid}', 'EarlyDreamLand\\APIExtension\\Controllers\\VZGEBustController@BustByUuid');
};
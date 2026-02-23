<?php
use Illuminate\Support\Facades\Route;

return function ($plugin) {
    // 通过name或UUID获取头像
    Route::get('api/avatar/{identifier}', 'EarlyDreamLand\\APIExtension\\Controllers\\TextureController@avatarByIdentifier');
    // 通过UUID获取头像
    Route::get('api/avatar/uuid/{uuid}', 'EarlyDreamLand\\APIExtension\\Controllers\\TextureController@avatarByUuid');
    // 通过UUID获取皮肤文件
    Route::get('api/texture/uuid/{uuid}', 'EarlyDreamLand\\APIExtension\\Controllers\\TextureController@textureByUuid');
};
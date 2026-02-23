<?php

namespace EarlyDreamLand\APIExtension\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Player;
use App\Models\Texture;
use Illuminate\Support\Facades\DB;
use Illuminate\Http\Request;
use Blessing\Minecraft;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;

class TextureController extends Controller
{
    public function __construct()
    {
        $this->middleware('cache.headers:etag;public;max_age=' . option('cache_expire_time'))
            ->only([
                'avatarByUuid',
                'textureByUuid',
            ]);
    }

    public function avatarByUuid(Minecraft $minecraft, Request $request, $uuid)
    {
        // 移除 UUID 中的连字符（如果有的话）
        $cleanUuid = str_replace('-', '', $uuid);

        // 1. 在 uuid 表中查询该 UUID 对应的记录
        $uuidRecord = DB::table('uuid')->where('uuid', $cleanUuid)->first();

        if (!$uuidRecord) {
            abort(404, 'UUID not found');
        }

        // 2. 通过 pid 关联查询 players 表，获取玩家信息
        $player = Player::find($uuidRecord->pid);

        if (!$player) {
            abort(404, 'Player not found');
        }

        // 3. 获取玩家皮肤纹理
        if (!$player->tid_skin) {
            abort(404, 'Texture not found (no skin set)');
        }

        $texture = Texture::find($player->tid_skin);

        if (!$texture) {
            abort(404, 'Texture not found');
        }

        // 4. 调用头像生成方法（模仿官方avatarByPlayer方法）
        return $this->avatar($minecraft, $request, $texture);
    }

    public function textureByUuid(Request $request, $uuid)
    {
        // 移除 UUID 中的连字符（如果有的话）
        $cleanUuid = str_replace('-', '', $uuid);

        // 1. 在 uuid 表中查询该 UUID 对应的记录
        $uuidRecord = DB::table('uuid')->where('uuid', $cleanUuid)->first();

        if (!$uuidRecord) {
            abort(404, 'UUID not found');
        }

        // 2. 通过 pid 关联查询 players 表，获取玩家信息
        $player = Player::find($uuidRecord->pid);

        if (!$player) {
            abort(404, 'Player not found');
        }

        // 3. 获取玩家皮肤纹理
        if (!$player->tid_skin) {
            abort(404, 'Texture not found (no skin set)');
        }

        $texture = Texture::find($player->tid_skin);

        if (!$texture) {
            abort(404, 'Texture not found');
        }

        // 4. 调用皮肤文件获取方法（模仿官方texture方法）
        return $this->texture($texture->hash);
    }

    protected function avatar(Minecraft $minecraft, Request $request, ?Texture $texture)
    {
        // 完全模仿官方TextureController中的avatar方法
        if (!empty($texture) && $texture->type !== 'steve' && $texture->type !== 'alex') {
            return abort(422);
        }

        $size = (int) $request->query('size', 100);
        $mode = $request->has('3d') ? '3d' : '2d';
        $usePNG = $request->has('png') || !(imagetypes() & IMG_WEBP);
        $format = $usePNG ? 'png' : 'webp';

        $disk = Storage::disk('textures');
        if (is_null($texture) || $disk->missing($texture->hash)) {
            return \Intervention\Image\ImageManagerStatic::configure(['driver' => 'gd'])
                ->make(resource_path("misc/textures/avatar$mode.png"))
                ->resize($size, $size)
                ->response($usePNG ? 'png' : 'webp', 100);
        }

        $hash = $texture->hash;
        $now = Carbon::now();
        $response = Cache::remember(
            'avatar-' . $mode . '-t' . $texture->tid . '-s' . $size . '-' . $format,
            option('enable_avatar_cache') ? $now->addYear() : $now->addMinute(),
            function () use ($minecraft, $disk, $hash, $size, $mode, $usePNG) {
                $file = $disk->get($hash);
                if ($mode === '3d') {
                    $image = $minecraft->render3dAvatar($file, 25);
                } else {
                    $image = $minecraft->render2dAvatar($file, 25);
                }

                $lastModified = Carbon::createFromTimestamp($disk->lastModified($hash));

                return \Intervention\Image\ImageManagerStatic::configure(['driver' => 'gd'])
                    ->make($image)
                    ->resize($size, $size)
                    ->response($usePNG ? 'png' : 'webp', 100)
                    ->setLastModified($lastModified);
            }
        );

        return $response;
    }

    protected function texture(string $hash)
    {
        // 完全模仿官方TextureController中的texture方法
        $disk = Storage::disk('textures');
        abort_if($disk->missing($hash), 404);

        $lastModified = Carbon::createFromTimestamp($disk->lastModified($hash));

        return response($disk->get($hash))
            ->withHeaders([
                'Content-Type' => 'image/png',
                'Content-Length' => $disk->size($hash),
            ])
            ->setLastModified($lastModified);
    }
}
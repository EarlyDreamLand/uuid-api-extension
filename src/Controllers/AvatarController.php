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

class AvatarController extends Controller
{
    public function __construct()
    {
        $this->middleware('cache.headers:etag;public;max_age=' . option('cache_expire_time'))
            ->only([
                'avatarByIdentifier'
            ]);
    }

    public function avatarByIdentifier(Minecraft $minecraft, Request $request, $identifier)
    {
        $cleanUuid = str_replace('-', '', $identifier);
        $uuidRecord = DB::table('uuid')->where('uuid', $cleanUuid)->first();
        
        if ($uuidRecord) {
            $player = Player::find($uuidRecord->pid);
            
            if ($player) {
                return $this->getAvatar($minecraft, $request, $player);
            }
        }

        $player = Player::where('name', $identifier)->first();
        
        if ($player) {
            return $this->getAvatar($minecraft, $request, $player);
        }

        $texture = null;
        return $this->avatar($minecraft, $request, $texture);
    }

    protected function getAvatar(Minecraft $minecraft, Request $request, Player $player)
    {
        $texture = null;
        if ($player->tid_skin) {
            $texture = Texture::find($player->tid_skin);
        }

        return $this->avatar($minecraft, $request, $texture);
    }

    protected function avatar(Minecraft $minecraft, Request $request, ?Texture $texture)
    {
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
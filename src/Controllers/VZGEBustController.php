<?php

namespace EarlyDreamLand\APIExtension\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Player;
use App\Models\Texture;
use Illuminate\Support\Facades\DB;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Cache;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;

class VZGEBustController extends Controller
{
    // 全身图尺寸
    const BUST_SIZE = 512;
    
    // 缓存时间（7天）
    const CACHE_TTL = 604800;
    
    // 第三方API基础URL
    const API_BASE_URL = 'https://visage.surgeplay.com/bust/';
    
    /**
     * 通过UUID或玩家名获取全身图
     */
    public function BustByUuid(Request $request, $identifier)
    {
        // 尝试作为UUID查询
        $cleanUuid = str_replace('-', '', $identifier);
        $uuidRecord = DB::table('uuid')->where('uuid', $cleanUuid)->first();
        
        if ($uuidRecord) {
            $player = Player::find($uuidRecord->pid);
            if ($player) {
                return $this->getBustBody($player);
            }
        }
        
        // 尝试作为玩家名查询
        $player = Player::where('name', $identifier)->first();
        if ($player) {
            return $this->getBustBody($player);
        }
        
        // 两种方式都找不到玩家，使用默认皮肤
        return $this->generateDefaultBustBody();
    }
    
    /**
     * 获取玩家全身图
     */
    protected function getBustBody(Player $player)
    {
        // 如果玩家有皮肤纹理，使用本地皮肤文件
        if ($player->tid_skin) {
            $texture = Texture::find($player->tid_skin);
            if (!$texture) {
                // 纹理记录不存在，使用默认皮肤
                return $this->generateDefaultBustBody();
            }
            
            $skinContent = $this->getSkinContent($texture->hash);
            if (!$skinContent) {
                // 皮肤文件不存在，使用默认皮肤
                return $this->generateDefaultBustBody();
            }
            
            // 生成缓存键（基于皮肤哈希）
            $cacheKey = 'bust_' . $texture->hash;
            
            // 检查缓存
            if (Cache::has($cacheKey)) {
                return $this->getCachedImage($cacheKey);
            }
            
            // 使用本地皮肤生成全身图
            return $this->generateBustBodyBySkin($skinContent, $cacheKey);
        }
        
        // 玩家没有设置皮肤，使用默认皮肤
        return $this->generateDefaultBustBody();
    }
    
    /**
     * 使用皮肤内容生成全身图
     */
    protected function generateBustBodyBySkin($skinContent, $cacheKey)
    {
        // 将皮肤内容转换为base64（去除数据URI前缀）
        $base64Skin = base64_encode($skinContent);
        
        // 构建API URL
        $apiUrl = self::API_BASE_URL . self::BUST_SIZE . '/' . $base64Skin;
        
        try {
            // 使用GuzzleHTTP请求第三方API
            $client = new Client([
                'headers' => [
                    'User-Agent' => 'BlessingSkinPlugin/1.0 (https://yourdomain.com)',
                ],
                'timeout' => 10,
                'connect_timeout' => 5,
            ]);
            
            $response = $client->get($apiUrl);
            
            if ($response->getStatusCode() !== 200) {
                abort(500, 'Failed to generate bust body image by skin');
            }
            
            // 获取图片内容
            $imageContent = $response->getBody()->getContents();
            
            // 缓存图片
            $this->cacheImage($cacheKey, $imageContent);
            
            // 返回图片
            return $this->createImageResponse($imageContent);
                
        } catch (RequestException $e) {
            abort(500, 'API request failed: ' . $e->getMessage());
        }
    }
    
    /**
     * 生成默认全身图
     */
    protected function generateDefaultBustBody()
    {
        // 默认皮肤名称
        $defaultSkinName = 'X-Steve';
        
        // 生成缓存键（基于默认皮肤名称）
        $cacheKey = 'bust_default_' . md5($defaultSkinName);
        
        // 检查缓存
        if (Cache::has($cacheKey)) {
            return $this->getCachedImage($cacheKey);
        }
        
        // 构建API URL
        $apiUrl = self::API_BASE_URL . self::BUST_SIZE . '/' . urlencode($defaultSkinName);
        
        try {
            // 使用GuzzleHTTP请求第三方API
            $client = new Client([
                'headers' => [
                    'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/118.0.1.56 Safari/537.36',
                ],
                'timeout' => 10,
                'connect_timeout' => 5,
            ]);
            
            $response = $client->get($apiUrl);
            
            if ($response->getStatusCode() !== 200) {
                abort(500, 'Failed to generate default bust body image');
            }
            
            // 获取图片内容
            $imageContent = $response->getBody()->getContents();
            
            // 缓存图片
            $this->cacheImage($cacheKey, $imageContent);
            
            // 返回图片
            return $this->createImageResponse($imageContent);
                
        } catch (RequestException $e) {
            abort(500, 'API request failed: ' . $e->getMessage());
        }
    }
    
    /**
     * 获取皮肤文件内容
     */
    protected function getSkinContent($hash)
    {
        $disk = Storage::disk('textures');
        if (!$disk->exists($hash)) {
            return null;
        }
        
        return $disk->get($hash);
    }
    
    /**
     * 缓存图片
     */
    protected function cacheImage($cacheKey, $imageContent)
    {
        // 缓存到文件系统
        $cacheDir = $this->getCacheDirectory();
        $cacheFile = $cacheDir . '/' . $cacheKey . '.png';
        
        // 确保目录存在
        if (!is_dir($cacheDir)) {
            mkdir($cacheDir, 0755, true);
        }
        
        // 保存图片
        file_put_contents($cacheFile, $imageContent);
        
        // 缓存到Laravel缓存系统
        Cache::put($cacheKey, $cacheFile, self::CACHE_TTL);
    }
    
    /**
     * 获取缓存的图片
     */
    protected function getCachedImage($cacheKey)
    {
        $cacheFile = Cache::get($cacheKey);
        
        if ($cacheFile && file_exists($cacheFile)) {
            return $this->createImageResponse(file_get_contents($cacheFile));
        }
        
        // 缓存无效，清除缓存键
        Cache::forget($cacheKey);
        
        // 返回错误信息
        return response()->json([
            'error' => 'Cached image not found',
            'message' => 'The cached PNG file has been deleted. Please regenerate the image.'
        ], 404);
    }
    
    /**
     * 创建图片响应
     */
    protected function createImageResponse($imageContent)
    {
        return response($imageContent)
            ->header('Content-Type', 'image/png')
            ->header('Cache-Control', 'public, max-age=' . self::CACHE_TTL);
    }
    
    /**
     * 获取缓存目录路径
     */
    protected function getCacheDirectory()
    {
        // 插件目录路径
        $pluginDir = base_path('plugins/uuid-api-extension');
        
        // 缓存目录
        return $pluginDir . '/storage/bust';
    }
    
    /**
     * 删除缓存图片
     */
    public function deleteCache(Request $request, $cacheKey)
    {
        // 获取缓存文件路径
        $cacheFile = Cache::get($cacheKey);
        
        if (!$cacheFile) {
            return response()->json([
                'error' => 'Cache key not found'
            ], 404);
        }
        
        // 删除文件
        if (file_exists($cacheFile)) {
            unlink($cacheFile);
        }
        
        // 清除缓存键
        Cache::forget($cacheKey);
        
        return response()->json([
            'success' => true,
            'message' => 'Cache deleted successfully'
        ]);
    }
}
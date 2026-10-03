<?php

namespace App\Http\Controllers;

use App\Models\Garment;
use App\Models\Mark;
use App\Models\Photo;
use App\Services\PhotoStorageService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class PhotoController extends Controller
{
    public function __construct(private readonly PhotoStorageService $photos)
    {
    }

    /**
     * 上传照片：/api/garments/{id}/photos  stage=intake|postwash|recheck
     */
    public function store(Request $request, int $garmentId)
    {
        $data = $request->validate([
            'photo' => ['required', 'file', 'mimes:jpg,jpeg,png,webp', 'max:10240'],
            'stage' => ['required', 'string', 'in:intake,postwash,recheck'],
        ]);

        $garment = Garment::findOrFail($garmentId);
        $photo = $this->photos->store($request->file('photo'), $garment, $data['stage'], $request->user());

        return response()->json(['data' => [
            'id' => $photo->id,
            'garment_id' => $photo->garment_id,
            'stage' => $photo->stage,
            'sha256' => $photo->sha256,
            'bytes' => $photo->bytes,
            'mime' => $photo->mime,
            'url' => url("/api/photos/{$photo->id}/content"),
            'created_at' => $photo->created_at,
        ]], 201);
    }

    /**
     * 预检痕迹标注（污渍/破损/护理说明的文字记录，绑定到照片之外）。
     */
    public function storeMark(Request $request, int $garmentId)
    {
        $data = $request->validate([
            'kind' => ['required', 'string', 'in:stain,damage'],
            'location' => ['required', 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:500'],
        ]);

        $garment = Garment::findOrFail($garmentId);
        if ($garment->intake_locked_at !== null) {
            return response()->json([
                'message' => '顾客已确认，预检标注已冻结，不能修改原始记录',
                'error_code' => 'intake_evidence_locked',
            ], 409);
        }

        $mark = Mark::create([
            'garment_id' => $garment->id,
            'kind' => $data['kind'],
            'location' => $data['location'],
            'description' => $data['description'] ?? '',
            'stage' => Mark::STAGE_INTAKE,
            'created_by' => $request->user()->id,
        ]);

        return response()->json(['data' => $mark], 201);
    }

    /**
     * 读取照片内容。原始预检照片同样只能读，接口层不存在更新/删除入口。
     */
    public function content(int $id)
    {
        $photo = Photo::findOrFail($id);
        $disk = Storage::disk(PhotoStorageService::DISK);
        if (!$disk->exists($photo->disk_path)) {
            return response()->json(['message' => '照片文件缺失'], 410);
        }

        return response($disk->get($photo->disk_path))
            ->header('Content-Type', $photo->mime)
            ->header('Cache-Control', 'private, max-age=3600')
            // 明确告知前端这是不可变证据
            ->header('X-Evidence-Stage', $photo->stage)
            ->header('X-Evidence-SHA256', $photo->sha256);
    }
}

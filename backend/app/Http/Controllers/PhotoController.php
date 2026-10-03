<?php

namespace App\Http\Controllers;

use App\Http\Resources\Presenter;
use App\Models\Garment;
use App\Models\GarmentPhoto;
use App\Services\PhotoStorageService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\File\Exception\FileNotFoundException;

class PhotoController extends Controller
{
    public function __construct(private readonly PhotoStorageService $photos)
    {
    }

    public function upload(Request $request, int $garmentId): JsonResponse
    {
        $data = $request->validate([
            'photo' => ['required', 'file', 'mimes:jpg,jpeg,png,webp', 'max:10240'],
            'stage' => ['required', 'in:intake,completion,dispute'],
        ]);

        $garment = Garment::findOrFail($garmentId);
        $photo = $this->photos->store(
            $garment,
            $data['stage'],
            $request->file('photo'),
            $request->user(),
        );

        return response()->json(['photo' => Presenter::photo($photo)], 201);
    }

    /**
     * 只读输出证据文件；附带当前磁盘校验和，前端可核对证据未被篡改。
     */
    public function show(int $id): BinaryFileResponse
    {
        /** @var GarmentPhoto $photo */
        $photo = GarmentPhoto::query()->findOrFail($id);
        $absolute = storage_path('app/private/'.$photo->path);

        if (! is_file($absolute)) {
            throw new FileNotFoundException($absolute);
        }

        return response()->file($absolute, [
            'Content-Type' => $photo->mime_type ?: 'application/octet-stream',
            'X-Evidence-Checksum' => $photo->checksum,
            'X-Evidence-Stage' => $photo->stage,
            'Cache-Control' => 'private, max-age=3600',
        ]);
    }
}

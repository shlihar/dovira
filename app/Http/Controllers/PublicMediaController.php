<?php

namespace App\Http\Controllers;

use App\Support\MediaUrl;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class PublicMediaController extends Controller
{
    public function __invoke(Request $request, string $path): BinaryFileResponse
    {
        $path = $this->normalizePath($path);
        $physicalPath = $path !== null ? MediaUrl::physicalPathForLocalMedia($path) : null;

        abort_if($physicalPath === null, 404);

        return response()
            ->file($physicalPath, [
                'Cache-Control' => 'public, max-age=31536000, immutable',
            ]);
    }

    private function normalizePath(string $path): ?string
    {
        $path = rawurldecode($path);
        $path = str_replace('\\', '/', $path);
        $path = ltrim($path, '/');

        if ($path === '' || Str::contains($path, ['../', '..\\', "\0"])) {
            return null;
        }

        return $path;
    }
}

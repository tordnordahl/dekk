<?php

namespace App\Http\Controllers;

use Symfony\Component\HttpFoundation\BinaryFileResponse;

class AssetController extends Controller
{
    public function show(string $filename): BinaryFileResponse
    {
        abort_unless((bool) preg_match('/\A[a-z0-9][a-z0-9._-]*\.(css|js)\z/i', $filename), 404);
        $public = realpath(public_path());
        $path = realpath(public_path($filename));
        abort_unless($public && $path && is_file($path) && str_starts_with($path, $public.DIRECTORY_SEPARATOR), 404);
        $type = str_ends_with(strtolower($filename), '.css') ? 'text/css; charset=UTF-8' : 'application/javascript; charset=UTF-8';
        return response()->file($path, [
            'Content-Type' => $type,
            'Cache-Control' => 'public, max-age=604800, immutable',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}

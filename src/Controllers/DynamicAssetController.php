<?php

namespace Bangsamu\LibraryClay\Controllers;

use App\Http\Controllers\Controller;
use Bangsamu\LibraryClay\Services\DynamicAssetService;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class DynamicAssetController extends Controller
{
    public function __construct(
        protected DynamicAssetService $assetService
    ) {}

    /**
     * Serve dynamic favicon based on logged in user company or default fallback to favicon.ico.
     */
    public function favicon(): BinaryFileResponse
    {
        $path = $this->assetService->getFaviconPath();

        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        $contentType = match ($extension) {
            'png' => 'image/png',
            'svg' => 'image/svg+xml',
            'jpg', 'jpeg' => 'image/jpeg',
            'gif' => 'image/gif',
            'webp' => 'image/webp',
            default => 'image/x-icon',
        };

        return response()->file($path, [
            'Content-Type' => $contentType,
            'Cache-Control' => 'no-cache, no-store, must-revalidate',
            'Pragma' => 'no-cache',
            'Expires' => '0',
        ]);
    }

    /**
     * Serve dynamic logo based on logged in user company or default.
     */
    public function logo(): BinaryFileResponse
    {
        $path = $this->assetService->getLogoPath();

        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        $contentType = match ($extension) {
            'png' => 'image/png',
            'svg' => 'image/svg+xml',
            'jpg', 'jpeg' => 'image/jpeg',
            'gif' => 'image/gif',
            'webp' => 'image/webp',
            'ico' => 'image/x-icon',
            default => 'image/png',
        };

        return response()->file($path, [
            'Content-Type' => $contentType,
            'Cache-Control' => 'no-cache, no-store, must-revalidate',
            'Pragma' => 'no-cache',
            'Expires' => '0',
        ]);
    }
}

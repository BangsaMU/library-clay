<?php

namespace Bangsamu\LibraryClay\Services;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class DynamicAssetService
{
    /**
     * Get company code for current logged in user based on user_details.field_key = 'company_id'
     * joined with master_company.
     */
    public function getLoggedInCompanyCode(): ?string
    {
        if (! Auth::check()) {
            return null;
        }

        $user = Auth::user();
        if (! $user) {
            return null;
        }

        $userDetailTable = Schema::hasTable('master_user_details')
            ? 'master_user_details'
            : (Schema::hasTable('user_details') ? 'user_details' : null);

        if (! $userDetailTable) {
            return null;
        }

        $userDetail = DB::table($userDetailTable)
            ->where(function ($query) use ($user) {
                if (! empty($user->email)) {
                    $query->where('user_email', $user->email);
                }
                if (! empty($user->id)) {
                    $query->orWhere('user_id', $user->id);
                }
            })
            ->where(function ($q) {
                $q->where('field_key', 'company_id')
                  ->orWhere('field_key', 'company');
            })
            ->whereNull('deleted_at')
            ->first();

        if (! $userDetail || empty($userDetail->field_value)) {
            return null;
        }

        $company = DB::table('master_company')
            ->where('id', $userDetail->field_value)
            ->whereNull('deleted_at')
            ->first();

        if (! $company || empty($company->company_code)) {
            return null;
        }

        return strtolower(trim($company->company_code));
    }

    /**
     * Get full filesystem path to logo file for current user or default fallback.
     */
    public function getLogoPath(): string
    {
        // 1. Resolution via master_company & master_gallery database query
        if (Auth::check()) {
            try {
                $user = Auth::user();
                $userDetailTable = Schema::hasTable('master_user_details')
                    ? 'master_user_details'
                    : (Schema::hasTable('user_details') ? 'user_details' : null);

                if ($userDetailTable) {
                    $gallery = DB::table($userDetailTable . ' as ud')
                        ->join('master_company as mc', DB::raw('CAST(mc.id AS CHAR)'), '=', DB::raw('CAST(ud.field_value AS CHAR)'))
                        ->join('master_gallery as mg', 'mg.id', '=', 'mc.company_logo_id')
                        ->where(function ($q) use ($user) {
                            if (! empty($user->email)) {
                                $q->where('ud.user_email', $user->email);
                            }
                            if (! empty($user->id)) {
                                $q->orWhere('ud.user_id', $user->id);
                            }
                        })
                        ->whereNull('ud.deleted_at')
                        ->select('mg.url', 'mg.path', 'mg.filename')
                        ->first();

                    if ($gallery) {
                        if (! empty($gallery->path) && ! empty($gallery->filename)) {
                            $storagePath = storage_path('app/public/' . trim($gallery->path, '/') . '/' . $gallery->filename);
                            if (File::exists($storagePath)) {
                                return $storagePath;
                            }
                        }
                        if (! empty($gallery->url)) {
                            $relativeUrl = str_replace([url('/'), asset('/')], '', $gallery->url);
                            $publicPath = public_path(ltrim($relativeUrl, '/'));
                            if (File::exists($publicPath)) {
                                return $publicPath;
                            }
                        }
                    }
                }
            } catch (\Throwable $e) {
                // Fallback to company code candidates
            }
        }

        // 2. Resolution via company_code file candidates
        $code = $this->getLoggedInCompanyCode();

        if ($code) {
            $codeUpper = strtoupper($code);
            $codeLower = strtolower($code);

            $candidates = [
                "logo_{$codeUpper}.png",
                "logo_{$codeLower}.png",
                "assets/images/logo_{$codeUpper}.png",
                "assets/images/logo_{$codeLower}.png",
                "assets/images/{$codeUpper}.png",
                "assets/images/{$codeLower}.png",
                "logo-{$codeUpper}.png",
                "logo-{$codeLower}.png",
                "img/logo_{$codeUpper}.png",
                "img/logo_{$codeLower}.png",
            ];

            if ($codeLower === 'meb') {
                $candidates[] = 'assets/images/meitech-logo.png';
                $candidates[] = 'themes/cms_meb/assets/img/s1-meitech-logo-only.png';
            } elseif ($codeLower === 'me' || $codeLower === 'meindo') {
                $candidates[] = 'img/meindo-icon.png';
                $candidates[] = 'assets/images/4-12-meindo-logo.png';
            }

            foreach ($candidates as $file) {
                $fullPath = public_path($file);
                if (File::exists($fullPath)) {
                    return $fullPath;
                }
            }
        }

        // 3. Default logo candidates
        $defaultCandidates = [
            'logo.png',
            'assets/images/logo.png',
            'img/logo.png',
        ];

        foreach ($defaultCandidates as $file) {
            $fullPath = public_path($file);
            if (File::exists($fullPath)) {
                return $fullPath;
            }
        }

        return public_path('logo.png');
    }

    /**
     * Get full filesystem path to favicon file for current user or default fallback.
     */
    public function getFaviconPath(): string
    {
        $code = $this->getLoggedInCompanyCode();

        if ($code) {
            $codeUpper = strtoupper($code);
            $codeLower = strtolower($code);

            $faviconCandidates = [
                "favicon_{$codeLower}.ico",
                "favicon_{$codeUpper}.ico",
                "favicon_{$codeLower}.png",
                "favicon_{$codeUpper}.png",
                "favicon_{$codeLower}.svg",
                "favicon_{$codeUpper}.svg",
                "favicons/favicon_{$codeLower}.ico",
                "favicons/favicon_{$codeUpper}.ico",
                "favicons/favicon_{$codeLower}.png",
                "favicons/favicon_{$codeUpper}.png",
                "favicon-{$codeLower}.ico",
                "favicon-{$codeUpper}.ico",
                "favicon-{$codeLower}.png",
                "favicon-{$codeUpper}.png",
                "assets/images/favicon_{$codeLower}.ico",
                "assets/images/favicon_{$codeUpper}.ico",
                "img/favicon_{$codeLower}.ico",
                "img/favicon_{$codeUpper}.ico",
            ];

            foreach ($faviconCandidates as $file) {
                $fullPath = public_path($file);
                if (File::exists($fullPath)) {
                    return $fullPath;
                }
            }

            $logoCandidates = [
                "logo_{$codeUpper}.png",
                "logo_{$codeLower}.png",
                "logo_{$codeUpper}.ico",
                "logo_{$codeLower}.ico",
                "assets/images/logo_{$codeUpper}.png",
                "assets/images/logo_{$codeLower}.png",
                "assets/images/{$codeUpper}.png",
                "assets/images/{$codeLower}.png",
                "logo-{$codeUpper}.png",
                "logo-{$codeLower}.png",
                "img/logo_{$codeUpper}.png",
                "img/logo_{$codeLower}.png",
            ];

            if ($codeLower === 'meb') {
                array_unshift($logoCandidates, 'assets/images/meitech-logo.png', 'themes/cms_meb/assets/img/s1-meitech-logo-only.png');
            } elseif ($codeLower === 'me' || $codeLower === 'meindo') {
                array_unshift($logoCandidates, 'img/meindo-icon.png', 'assets/images/4-12-meindo-logo.png');
            }

            foreach ($logoCandidates as $file) {
                $fullPath = public_path($file);
                if (File::exists($fullPath)) {
                    return $fullPath;
                }
            }
        }

        $defaultCandidates = [
            'favicon.ico',
            'favicons/favicon.ico',
            'assets/images/favicon.ico',
        ];

        foreach ($defaultCandidates as $file) {
            $fullPath = public_path($file);
            if (File::exists($fullPath)) {
                return $fullPath;
            }
        }

        return public_path('favicon.ico');
    }

    /**
     * Get public URL for dynamic logo.
     */
    public function getLogoUrl(): string
    {
        return route('dynamic.logo');
    }

    /**
     * Get public URL for dynamic favicon.
     */
    public function getFaviconUrl(): string
    {
        return route('dynamic.favicon');
    }
}

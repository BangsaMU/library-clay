<?php

namespace Bangsamu\LibraryClay\Storage;

use Illuminate\Filesystem\AwsS3V3Adapter;
use Illuminate\Http\Response;

/**
 * Respons X-Accel untuk objek S3/MinIO: PHP hanya mengirim header, nginx (location internal `/_s3_reproxy`)
 * yang mengambil file dari MinIO dengan presigned URL di header `X-Reproxy-URL` lalu mengalirkannya ke client
 * (Range, HEAD, 304 ditangani nginx + MinIO). Konfigurasi nginx: docs/minio-xaccel-proxy.md di meb_spb.
 */
class S3ReproxyResponse
{
    public const LOCATION = '/_s3_reproxy';

    public const URL_HEADER = 'X-Reproxy-URL';

    /** Header yang nilainya harus datang dari MinIO; bila ikut dikirim PHP, header di respons jadi dobel. */
    private const UPSTREAM_HEADERS = ['accept-ranges', 'content-length', 'content-range'];

    /**
     * @param  array<string, string>  $headers  header presentasi: Content-Type, Content-Disposition, Cache-Control
     *                                          (isi Cache-Control eksplisit, kalau kosong Laravel memakai `no-cache, private`)
     */
    public static function make(AwsS3V3Adapter $disk, string $key, array $headers = [], int $ttlSeconds = 600, string $location = self::LOCATION): Response
    {
        $headers = array_filter(
            $headers,
            fn (string $name): bool => ! in_array(strtolower($name), self::UPSTREAM_HEADERS, true),
            ARRAY_FILTER_USE_KEY
        );

        return new Response('', 200, [
            'X-Accel-Redirect' => $location,
            self::URL_HEADER => self::url($disk, $key, $ttlSeconds),
        ] + $headers);
    }

    /**
     * Presigned URL GetObject polos (tanpa override `response-*`).
     *
     * Dibuat langsung lewat client, bukan `$disk->temporaryUrl()`, agar host tidak diganti opsi `temporary_url` disk:
     * host harus endpoint MinIO yang dijangkau nginx (signature S3 mengikat host:port).
     * TTL hanya dicek MinIO saat request dimulai, jadi tidak membatasi lama download; buat cukup longgar untuk
     * selisih jam server aplikasi dan server MinIO.
     */
    public static function url(AwsS3V3Adapter $disk, string $key, int $ttlSeconds = 600): string
    {
        $client = $disk->getClient();
        $command = $client->getCommand('GetObject', [
            'Bucket' => $disk->getConfig()['bucket'],
            'Key' => $disk->path($key),
        ]);

        return (string) $client->createPresignedRequest($command, '+'.max(1, $ttlSeconds).' seconds')->getUri();
    }
}

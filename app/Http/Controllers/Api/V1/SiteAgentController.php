<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use RuntimeException;
use Symfony\Component\Finder\Finder;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use ZipArchive;

/**
 * Serves the companion "Imagina Reports Agent" WordPress plugin as a one-click ZIP
 * download for the admin (CLAUDE.md §9 — own-built per-site source). The plugin lives
 * in the repo at wp-plugin/imagina-reports-agent; we zip it on the fly so the download
 * always matches the deployed version. Files are nested under a top-level folder so the
 * ZIP installs cleanly via WordPress' "Upload plugin".
 */
final class SiteAgentController extends Controller
{
    private const PLUGIN_DIR = 'imagina-reports-agent';

    /**
     * The bundled agent plugin version (read from the plugin header), so the admin can see
     * which version the download will give without opening the ZIP.
     */
    public function version(): JsonResponse
    {
        $file = base_path('wp-plugin/'.self::PLUGIN_DIR.'/'.self::PLUGIN_DIR.'.php');
        $version = null;

        if (is_file($file)) {
            $contents = (string) file_get_contents($file);
            if (preg_match('/^\s*\*\s*Version:\s*(.+)$/mi', $contents, $matches) === 1) {
                $version = trim($matches[1]);
            }
        }

        return response()->json(['version' => $version]);
    }

    public function download(): BinaryFileResponse
    {
        $source = base_path('wp-plugin/'.self::PLUGIN_DIR);

        if (! is_dir($source)) {
            throw new RuntimeException('Plugin source directory is missing.');
        }

        $zipPath = tempnam(sys_get_temp_dir(), 'ir-agent-').'.zip';

        $zip = new ZipArchive;
        if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Could not create the plugin ZIP.');
        }

        foreach (Finder::create()->files()->in($source) as $file) {
            $realPath = $file->getRealPath();

            if ($realPath === false) {
                continue;
            }

            $zip->addFile($realPath, self::PLUGIN_DIR.'/'.$file->getRelativePathname());
        }

        $zip->close();

        return response()
            ->download($zipPath, self::PLUGIN_DIR.'.zip', ['Content-Type' => 'application/zip'])
            ->deleteFileAfterSend(true);
    }
}

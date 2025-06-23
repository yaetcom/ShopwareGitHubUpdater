<?php
namespace KawsPluginGitUpdater\Controller;

use Composer\InstalledVersions;
use Shopware\Core\Framework\Routing\Annotation\RouteScope;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;


#[Route(defaults: ['_routeScope' => ['api']])]
class GithubUpdateController
{
    #[Route(path: "/api/_action/github/check-version", name: 'api.action.github.check_version', methods: ['GET'])]
    public function checkVersion(Request $request): JsonResponse
    {
        $repoUrl = $request->get('name');
        try {
            $latestTag = $this->getLatestGithubTag($repoUrl);
            $allVersions = $this->getAllCompatibleVersions($repoUrl);
            
            return new JsonResponse([
                'success' => true, 
                'latestVersion' => $latestTag,
                'versions' => $allVersions
            ]);
        } catch (\Exception $e) {
            return new JsonResponse(['success' => false, 'message' => $e->getMessage()]);
        }
    }

    #[Route(path: "/api/_action/github/update-plugin", name: 'api.action.github.update', methods: ['POST'])]
    public function updatePlugin(Request $request): JsonResponse
    {
        $githubUrl = $request->get('url');
        $extensionName = $request->get('name');
        $selectedVersion = $request->get('version');
        
        // Verwende die ausgewählte Version oder fallback zur neuesten
        $tag = $selectedVersion ?: $this->getLatestGithubTag($githubUrl);
        
        if (!$githubUrl) {
            return new JsonResponse(['success' => false, 'message' => 'URL fehlt.'], 400);
        }

        try {
            $zipUrl = $githubUrl . '/zipball/' . $tag;
            $pluginDir = '../custom/plugins/';
            $zipPath = sprintf('../custom/plugins/%s.zip', $extensionName);
            $tempDir = $pluginDir . 'temp_' . uniqid() . '/';

            $zipContent = file_get_contents($zipUrl);

            if (!$zipContent) {
                throw new \Exception('Download fehlgeschlagen.');
            }

            $bytesWritten = @file_put_contents($zipPath, $zipContent);
            if ($bytesWritten === false) {
                $errorMessage = error_get_last();
                throw new \Exception('Speichern der Datei fehlgeschlagen: ' . $errorMessage['message']);
            }

            $zip = new \ZipArchive();
            if ($zip->open($zipPath) === true) {
                $topFolder = $zip->getNameIndex(0);
                $zip->extractTo($pluginDir);
                $zip->close();
                unlink($zipPath);
            } else {
                throw new \Exception('ZIP-Datei konnte nicht entpackt werden.');
            }


            $extractedFolder = $pluginDir . rtrim($topFolder, '/');

            $filesystem = new Filesystem();
            if (!is_dir($extractedFolder)) {
                throw new \RuntimeException('Entpacktes Plugin-Verzeichnis nicht gefunden.');
            }

            $pluginName = null;

            if (file_exists($extractedFolder . '/composer.json')) {
                $composer = json_decode(file_get_contents($extractedFolder . '/composer.json'), true);
                if (isset($composer['extra']['shopware-plugin-class'])) {
                    $parts = explode('\\', $composer['extra']['shopware-plugin-class']);
                    $pluginName = end($parts);
                }
            }

            if (!$pluginName && file_exists($extractedFolder . '/plugin.xml')) {
                $xml = simplexml_load_file($extractedFolder . '/plugin.xml');
                $pluginName = (string)$xml->name;
            }

            if (!$pluginName) {
                $filesystem->remove($extractedFolder);
                throw new \RuntimeException('Pluginname konnte nicht aus composer.json oder plugin.xml ermittelt werden.');
            }

            if (strtolower($pluginName) !== strtolower($extensionName)) {
                $filesystem->remove($extractedFolder);
                throw new \RuntimeException(sprintf(
                    'Das ZIP enthält nicht das erwartete Plugin "%s" (gefunden: "%s")',
                    $extensionName,
                    $pluginName
                ));
            }


            $targetFolder = $pluginDir . $extensionName;

            if ($filesystem->exists($targetFolder)) {
                $filesystem->remove($targetFolder);
            }

            $this->copyDirectory($extractedFolder, $targetFolder);
            $filesystem->remove($extractedFolder);

            $this->refreshPluginInShopware($extensionName);
            $this->clearCache();

            return new JsonResponse([
                'success' => true,
                'message' => 'Plugin erfolgreich aktualisiert.',
                'update' => true,
                'needsRefresh' => true
            ]);
        } catch (\Exception $e) {
            return new JsonResponse(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    private function copyDirectory($src, $dst): void
    {
        $dir = opendir($src);
        @mkdir($dst, 0775, true);

        while (false !== ($file = readdir($dir))) {
            if (($file != '.') && ($file != '..')) {
                $srcFile = $src . '/' . $file;
                $dstFile = $dst . '/' . $file;

                if (is_dir($srcFile)) {
                    $this->copyDirectory($srcFile, $dstFile);
                } else {
                    copy($srcFile, $dstFile);
                }
            }
        }

        closedir($dir);
    }

    private function getLatestGithubTag(string $repoUrl): string
    {
        $coreComposerFile = dirname(__DIR__,5) . '/composer.json';

        if (file_exists($coreComposerFile)) {
            $composerData = json_decode(file_get_contents($coreComposerFile), true);
            $version = $composerData['require']['shopware/core'] ?? 'unbekannt';
        } else {
            echo "Shopware core composer.json nicht gefunden";
        }
        $versionParts = explode('.', $version);
        $shopwareMajor = $versionParts[0] . '.' . $versionParts[1];

        $compatibleTags = $this->getCompatibleShopwareTagsFromGithub($repoUrl, $shopwareMajor);
        if (empty($compatibleTags)) {
            throw new \Exception("Keine kompatiblen Tags für Shopware $shopwareMajor gefunden.");
        }

        $normalizedTags = array_map(function ($tag) {
            return ltrim($tag, 'vV');
        }, $compatibleTags);

        usort($normalizedTags, 'version_compare');
        $latestNormalized = end($normalizedTags);

        foreach ($compatibleTags as $originalTag) {
            if (ltrim($originalTag, 'vV') === $latestNormalized) {
                return $originalTag;
            }
        }

        throw new \RuntimeException('Fehler beim Ermitteln der neuesten kompatiblen Version.');
    }

    private function getAllCompatibleVersions(string $repoUrl): array
    {
        $coreComposerFile = dirname(__DIR__,5) . '/composer.json';

        if (file_exists($coreComposerFile)) {
            $composerData = json_decode(file_get_contents($coreComposerFile), true);
            $version = $composerData['require']['shopware/core'] ?? 'unbekannt';
        } else {
            throw new \Exception("Shopware core composer.json nicht gefunden");
        }
        $versionParts = explode('.', $version);
        $shopwareMajor = $versionParts[0] . '.' . $versionParts[1];

        $compatibleTags = $this->getCompatibleShopwareTagsFromGithub($repoUrl, $shopwareMajor);
        
        if (empty($compatibleTags)) {
            throw new \Exception("Keine kompatiblen Tags für Shopware $shopwareMajor gefunden.");
        }

        // Sortiere Versionen absteigend (neueste zuerst)
        $normalizedTags = array_map(function ($tag) {
            return ['original' => $tag, 'normalized' => ltrim($tag, 'vV')];
        }, $compatibleTags);

        usort($normalizedTags, function($a, $b) {
            return version_compare($b['normalized'], $a['normalized']);
        });

        return array_column($normalizedTags, 'original');
    }

    private function getCompatibleShopwareTagsFromGithub(string $repoUrl, string $shopwareVersion): array
    {
        $pattern = '/github\.com\/([^\/]+\/[^\/]+)/';
        if (!preg_match($pattern, $repoUrl, $matches)) {
            throw new \InvalidArgumentException("Ungültige GitHub-URL: $repoUrl");
        }

        $repoPath = rtrim($matches[1], '/');

        $tagListUrl = "https://api.github.com/repos/$repoPath/tags";

        $tagsJson = $this->curlGithub($tagListUrl);
        $tags = json_decode($tagsJson, true);

        if (!is_array($tags)) {
            throw new \RuntimeException("Konnte keine Tags vom Repository abrufen.");
        }

        $compatibleTags = [];

        foreach ($tags as $tagInfo) {
            $tag = $tagInfo['name'];

            $composerUrl = "https://raw.githubusercontent.com/$repoPath/$tag/composer.json";

            try {
                $composerRaw = $this->curlGithub($composerUrl);
                $composer = json_decode($composerRaw, true);

                if (
                    isset($composer['require']['shopware/core']) &&
                    $this->isVersionCompatible($composer['require']['shopware/core'], $shopwareVersion)
                ) {
                    $compatibleTags[] = $tag;
                }
            } catch (\Throwable $e) {
                continue;
            }
        }

        return $compatibleTags;
    }

    private function curlGithub(string $url): string
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_USERAGENT => 'Shopware GitHub Updater',
            CURLOPT_TIMEOUT => 10,
        ]);

        $response = curl_exec($ch);

        if ($response === false) {
            $error = curl_error($ch);
            curl_close($ch);
            throw new \RuntimeException("Fehler beim Abruf von $url: $error");
        }

        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode >= 400) {
            throw new \RuntimeException("HTTP $httpCode beim Abruf von $url");
        }

        return $response;
    }

    private function isVersionCompatible(string $constraint, string $targetVersion): bool
    {
        // Normalize target version by removing 'v' or 'V' prefix
        $targetVersion = ltrim($targetVersion, 'vV');
        
        // Handle OR constraints (e.g., "~6.6.0 || ~6.7.0")
        if (str_contains($constraint, '||')) {
            $constraints = explode('||', $constraint);
            foreach ($constraints as $singleConstraint) {
                if ($this->matchesVersionConstraint(trim($singleConstraint), $targetVersion)) {

                    return true;
                }
            }
            return false;
        }

        // Handle single constraint
        return $this->matchesVersionConstraint($constraint, $targetVersion);
    }

    private function matchesVersionConstraint(string $constraint, string $targetVersion): bool
    {
        $constraint = trim($constraint);

        if (str_starts_with($constraint, '~')) {
            $versionPart = ltrim($constraint, '~');
            $parts = explode('.', $versionPart);
            
            if (count($parts) >= 2) {
                $major = $parts[0];
                $minor = $parts[1];
                $targetMajorMinor = $major . '.' . $minor;
                
                return str_starts_with($targetVersion, $targetMajorMinor);
            }
        }

        if (str_starts_with($constraint, '^')) {
            $versionPart = ltrim($constraint, '^');
            $parts = explode('.', $versionPart);
            
            if (count($parts) >= 1) {
                $major = $parts[0];
                return str_starts_with($targetVersion, $major . '.');
            }
        }

        if (str_contains($constraint, '*')) {
            $pattern = str_replace('*', '', $constraint);
            return str_starts_with($targetVersion, $pattern);
        }

        return str_contains($constraint, $targetVersion);
    }

    private function refreshPluginInShopware(string $pluginName): void
    {
        try {
            $command = sprintf('cd %s && bin/console plugin:refresh %s --no-interaction', 
                dirname(__DIR__, 5), 
                escapeshellarg($pluginName)
            );
            
            exec($command . ' 2>&1', $output, $returnCode);
            
            if ($returnCode !== 0) {
                throw new \RuntimeException('Plugin refresh failed: ' . implode("\n", $output));
            }
        } catch (\Exception $e) {
            error_log('Plugin refresh failed: ' . $e->getMessage());
        }
    }

    private function clearCache(): void
    {
        try {
            $command = sprintf('cd %s && bin/console cache:clear --no-interaction', 
                dirname(__DIR__, 5)
            );
            
            exec($command . ' 2>&1', $output, $returnCode);
            
            if ($returnCode !== 0) {
                throw new \RuntimeException('Cache clear failed: ' . implode("\n", $output));
            }
        } catch (\Exception $e) {
            error_log('Cache clear failed: ' . $e->getMessage());
        }
    }

}
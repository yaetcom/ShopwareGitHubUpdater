<?php
declare(strict_types=1);

namespace KawsPluginGitUpdater\Controller;

use Composer\InstalledVersions;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;

#[Route(defaults: ['_routeScope' => ['api']])]
class GithubInstallController
{
    #[Route(path: '/api/_action/github/install-plugin', name: 'api.action.github.install_plugin', methods: ['POST'])]
    public function installPlugin(Request $request): JsonResponse
    {
        $githubUrl = $request->get('url');

        if (!$githubUrl) {
            return new JsonResponse(['success' => false, 'message' => 'GitHub-URL fehlt.'], 400);
        }

        try {
            $tag = $this->getLatestGithubTag($githubUrl);
            $zipUrl = $githubUrl . '/zipball/' . $tag;

            $pluginDir = realpath(__DIR__ . '/../../../../../custom/plugins/') . '/';
            $zipPath = $pluginDir . 'install_temp_' . uniqid() . '.zip';

            $zipContent = file_get_contents($zipUrl);
            if (!$zipContent) {
                throw new \RuntimeException('ZIP-Download fehlgeschlagen.');
            }

            file_put_contents($zipPath, $zipContent);

            $zip = new \ZipArchive();
            if ($zip->open($zipPath) !== true) {
                unlink($zipPath);
                throw new \RuntimeException('ZIP-Datei konnte nicht geöffnet werden.');
            }

            $topFolder = rtrim($zip->getNameIndex(0), '/');
            $zip->extractTo($pluginDir);
            $zip->close();
            unlink($zipPath);

            $extractedFolder = $pluginDir . '/' . $topFolder;
            if (!is_dir($extractedFolder)) {
                throw new \RuntimeException('Entpacktes Pluginverzeichnis nicht gefunden.');
            }

            // ✅ Pluginname aus composer.json oder plugin.xml ermitteln
            $pluginName = null;

            if (file_exists($extractedFolder . '/composer.json')) {
                $composer = json_decode(file_get_contents($extractedFolder . '/composer.json'), true);
                if (isset($composer['extra']['shopware-plugin-class'])) {
                    $pluginClass = $composer['extra']['shopware-plugin-class'];
                    $parts = explode('\\', $pluginClass);
                    $pluginName = end($parts);
                }
            }

            if (!$pluginName && file_exists($extractedFolder . '/plugin.xml')) {
                $xml = simplexml_load_file($extractedFolder . '/plugin.xml');
                $pluginName = (string)$xml->name;
            }

            if (!$pluginName) {
                (new Filesystem())->remove($extractedFolder);
                throw new \RuntimeException('Pluginname konnte nicht aus composer.json oder plugin.xml ermittelt werden.');
            }

            $targetFolder = $pluginDir . $pluginName;
            if (is_dir($targetFolder)) {
                (new Filesystem())->remove($extractedFolder);
                throw new \RuntimeException("Plugin '$pluginName' ist bereits installiert.");
            }

            // Plugin-Ordner verschieben
            (new Filesystem())->rename($extractedFolder, $targetFolder);

            // Plugin registrieren & aktivieren
            exec('php bin/console plugin:refresh');
            exec("php bin/console plugin:install --activate $pluginName");

            return new JsonResponse([
                'success' => true,
                'message' => "Plugin '$pluginName' wurde erfolgreich installiert.",
                'version' => $tag,
                'pluginName' => $pluginName,
                'installed' => true,
            ]);
        } catch (\Throwable $e) {
            return new JsonResponse(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }


    private function getLatestGithubTag(string $repoUrl): string
    {
        $version = InstalledVersions::getVersion('shopware/core'); // z. B. 6.6.3.1
        $parts = explode('.', $version);
        $shopwareMajor = $parts[0] . '.' . $parts[1];

        $compatibleTags = $this->getCompatibleShopwareTagsFromGithub($repoUrl, $shopwareMajor);

        if (empty($compatibleTags)) {
            throw new \RuntimeException("Keine kompatiblen Versionen für Shopware $shopwareMajor gefunden.");
        }

        $normalizedTags = array_map(fn($t) => ltrim($t, 'vV'), $compatibleTags);
        usort($normalizedTags, 'version_compare');
        $latest = end($normalizedTags);

        foreach ($compatibleTags as $original) {
            if (ltrim($original, 'vV') === $latest) {
                return $original;
            }
        }

        throw new \RuntimeException('Kein gültiger Tag gefunden.');
    }

    private function getCompatibleShopwareTagsFromGithub(string $repoUrl, string $shopwareMajor): array
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
            throw new \RuntimeException("Konnte keine Tags abrufen.");
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
                    str_contains($composer['require']['shopware/core'], $shopwareMajor)
                ) {
                    $compatibleTags[] = $tag;
                }
            } catch (\Throwable) {
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
            CURLOPT_USERAGENT => 'Shopware Plugin Installer',
            CURLOPT_TIMEOUT => 10,
        ]);

        $response = curl_exec($ch);

        if ($response === false) {
            throw new \RuntimeException("Fehler bei Request: " . curl_error($ch));
        }

        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($code >= 400) {
            throw new \RuntimeException("HTTP $code bei $url");
        }

        return $response;
    }
}

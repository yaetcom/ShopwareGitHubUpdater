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
        $currentVersion = $request->get('currentVersion'); // Aktuelle Plugin-Version
        $installedBranch = $request->get('installedBranch'); // Aktuell installierter Branch
        
        try {
            $latestTag = $this->getLatestGithubTag($repoUrl);
            $allVersions = $this->getAllCompatibleVersions($repoUrl);
            
            // Spezielle Logik für branch-basierte Updates
            $updateAvailable = false;
            $updateInfo = null;
            
            if ($this->isBranchName($latestTag) && $installedBranch) {
                // Branch-basierter Update-Check über Commit-Vergleich
                $updateInfo = $this->checkBranchUpdate($repoUrl, $installedBranch, $latestTag);
                $updateAvailable = $updateInfo['hasUpdate'];
            } elseif (!$this->isBranchName($latestTag) && $currentVersion) {
                // Tag-basierter Update-Check über Versionsnummern
                $updateAvailable = version_compare(
                    ltrim($currentVersion, 'vV'), 
                    ltrim($latestTag, 'vV'), 
                    '<'
                );
            }
            
            return new JsonResponse([
                'success' => true, 
                'latestVersion' => $latestTag,
                'versions' => $allVersions,
                'updateAvailable' => $updateAvailable,
                'updateInfo' => $updateInfo
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
            // Normalisiere die GitHub-URL (entferne /tree/branch etc.)
            $baseGithubUrl = $this->normalizeGithubUrl($githubUrl);
            
            // Prüfe ob es ein Branch ist (enthält keinen 'v' oder Punkte)
            $isBranch = $this->isBranchName($tag);
            
            if ($isBranch) {
                $zipUrl = $baseGithubUrl . '/archive/refs/heads/' . $tag . '.zip';
            } else {
                $zipUrl = $baseGithubUrl . '/zipball/' . $tag;
            }
            
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
            // Fallback: Wenn keine Tags vorhanden, versuche passenden Branch zu finden
            return $this->getCompatibleBranch($repoUrl, $shopwareMajor);
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
            // Fallback: Versuche kompatible Branches zu finden
            try {
                $branch = $this->getCompatibleBranch($repoUrl, $shopwareMajor);
                return [$branch];
            } catch (\Exception $e) {
                throw new \Exception("Keine kompatiblen Tags oder Branches für Shopware $shopwareMajor gefunden.");
            }
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
        $repoPath = $this->extractRepoPath($repoUrl);
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

    private function getCompatibleBranch(string $repoUrl, string $shopwareMajor): string
    {
        $repoPath = $this->extractRepoPath($repoUrl);
        $branchListUrl = "https://api.github.com/repos/$repoPath/branches";

        $branchesJson = $this->curlGithub($branchListUrl);
        $branches = json_decode($branchesJson, true);

        if (!is_array($branches)) {
            throw new \RuntimeException("Konnte keine Branches vom Repository abrufen.");
        }

        $compatibleBranches = [];

        foreach ($branches as $branchInfo) {
            $branchName = $branchInfo['name'];
            
            // Prüfe zuerst, ob Branch-Name mit Shopware-Version übereinstimmt
            if (str_contains($branchName, $shopwareMajor)) {
                $composerUrl = "https://raw.githubusercontent.com/$repoPath/$branchName/composer.json";
                
                try {
                    $composerRaw = $this->curlGithub($composerUrl);
                    $composer = json_decode($composerRaw, true);

                    if (
                        isset($composer['require']['shopware/core']) &&
                        $this->isVersionCompatible($composer['require']['shopware/core'], $shopwareMajor)
                    ) {
                        $compatibleBranches[] = $branchName;
                    }
                } catch (\Throwable $e) {
                    continue;
                }
            }
        }

        if (empty($compatibleBranches)) {
            throw new \Exception("Keine kompatiblen Branches für Shopware $shopwareMajor gefunden.");
        }

        // Bevorzuge exakten Match (z.B. "6.7" für Shopware 6.7)
        if (in_array($shopwareMajor, $compatibleBranches)) {
            return $shopwareMajor;
        }

        // Sortiere nach Länge (längere Branch-Namen zuerst, da sie spezifischer sind)
        usort($compatibleBranches, function($a, $b) {
            return strlen($b) - strlen($a);
        });

        return $compatibleBranches[0];
    }

    private function isBranchName(string $name): bool
    {
        // Branch-Namen enthalten normalerweise keine Versionspunkte oder 'v' Präfixe
        // Typische Branch-Namen: "6.7", "main", "develop", "feature/xyz"
        return !preg_match('/^v?\d+\.\d+\.\d+/', $name);
    }

    private function extractRepoPath(string $repoUrl): string
    {
        // Normalisiere verschiedene GitHub-URL-Formate:
        // https://github.com/owner/repo
        // https://github.com/owner/repo/tree/branch
        // https://github.com/owner/repo/releases/tag/v1.0.0
        // etc.
        
        $pattern = '/github\.com\/([^\/]+\/[^\/]+)/';
        if (!preg_match($pattern, $repoUrl, $matches)) {
            throw new \InvalidArgumentException("Ungültige GitHub-URL: $repoUrl");
        }

        return rtrim($matches[1], '/');
    }

    private function normalizeGithubUrl(string $repoUrl): string
    {
        $repoPath = $this->extractRepoPath($repoUrl);
        return "https://github.com/$repoPath";
    }

    private function checkBranchUpdate(string $repoUrl, string $installedBranch, string $latestBranch): array
    {
        $repoPath = $this->extractRepoPath($repoUrl);
        
        try {
            // Hole die neuesten Commits beider Branches
            $installedCommitUrl = "https://api.github.com/repos/$repoPath/commits/$installedBranch";
            $latestCommitUrl = "https://api.github.com/repos/$repoPath/commits/$latestBranch";
            
            $installedCommitData = json_decode($this->curlGithub($installedCommitUrl), true);
            $latestCommitData = json_decode($this->curlGithub($latestCommitUrl), true);
            
            if (!isset($installedCommitData['sha']) || !isset($latestCommitData['sha'])) {
                return ['hasUpdate' => false, 'error' => 'Commit-Daten konnten nicht abgerufen werden'];
            }
            
            $installedCommitSha = $installedCommitData['sha'];
            $latestCommitSha = $latestCommitData['sha'];
            $installedCommitDate = $installedCommitData['commit']['author']['date'] ?? null;
            $latestCommitDate = $latestCommitData['commit']['author']['date'] ?? null;
            
            // Vergleiche die Commit-SHAs
            $hasUpdate = $installedCommitSha !== $latestCommitSha;
            
            return [
                'hasUpdate' => $hasUpdate,
                'installedCommit' => substr($installedCommitSha, 0, 7),
                'latestCommit' => substr($latestCommitSha, 0, 7),
                'installedDate' => $installedCommitDate,
                'latestDate' => $latestCommitDate,
                'commitsBehind' => $hasUpdate ? $this->getCommitsBehind($repoPath, $installedCommitSha, $latestCommitSha) : 0
            ];
            
        } catch (\Exception $e) {
            return [
                'hasUpdate' => false, 
                'error' => 'Branch-Update-Check fehlgeschlagen: ' . $e->getMessage()
            ];
        }
    }
    
    private function getCommitsBehind(string $repoPath, string $baseCommit, string $headCommit): int
    {
        try {
            $compareUrl = "https://api.github.com/repos/$repoPath/compare/$baseCommit...$headCommit";
            $compareData = json_decode($this->curlGithub($compareUrl), true);
            
            return $compareData['ahead_by'] ?? 0;
        } catch (\Exception $e) {
            return 0; // Fallback bei Fehlern
        }
    }

}
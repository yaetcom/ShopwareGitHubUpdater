<?php
declare(strict_types=1);

namespace KawsPluginGitUpdater\Controller;

use Composer\InstalledVersions;
use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Uuid\Uuid;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

#[Route(defaults: ['_routeScope' => ['api']])]
class GithubInstallController
{
    private Connection $connection;

    public function __construct(Connection $connection)
    {
        $this->connection = $connection;
    }
    #[Route(path: "/api/_action/github/install-versions", name: 'api.action.github.install_versions', methods: ['GET'])]
    public function getInstallVersions(Request $request): JsonResponse
    {
        $repoUrl = $request->get('url');
        
        if (!$repoUrl) {
            return new JsonResponse(['success' => false, 'message' => 'URL fehlt.'], 400);
        }
        
        try {
            $allVersions = $this->getAllCompatibleVersions($repoUrl);
            
            return new JsonResponse([
                'success' => true, 
                'versions' => $allVersions
            ]);
        } catch (\Exception $e) {
            return new JsonResponse(['success' => false, 'message' => $e->getMessage()]);
        }
    }

    #[Route(path: '/api/_action/github/install-plugin', name: 'api.action.github.install_plugin', methods: ['POST'])]
    public function installPlugin(Request $request): JsonResponse
    {
        $githubUrl = $request->get('url');
        $selectedVersion = $request->get('version');

        if (!$githubUrl) {
            return new JsonResponse(['success' => false, 'message' => 'GitHub-URL fehlt.'], 400);
        }

        try {
            // Verwende die ausgewählte Version oder fallback zur neuesten
            $tag = $selectedVersion ?: $this->getLatestGithubTag($githubUrl);
            
            // Extrahiere die ursprüngliche Tag/Branch-Referenz aus der angereicherten Version
            $originalTag = $this->extractOriginalVersion($tag);
            
            // Normalisiere die GitHub-URL (entferne /tree/branch etc.)
            $baseGithubUrl = $this->normalizeGithubUrl($githubUrl);
            
            // Prüfe ob es ein Branch ist
            $isBranch = $this->isBranchName($originalTag);
            
            if ($isBranch) {
                $zipUrl = $baseGithubUrl . '/archive/refs/heads/' . $originalTag . '.zip';
            } else {
                $zipUrl = $baseGithubUrl . '/zipball/' . $originalTag;
            }

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

            // ✅ Pluginname und Version aus composer.json oder plugin.xml ermitteln
            $pluginName = null;
            $pluginVersion = null;

            if (file_exists($extractedFolder . '/composer.json')) {
                $composer = json_decode(file_get_contents($extractedFolder . '/composer.json'), true);
                if (isset($composer['extra']['shopware-plugin-class'])) {
                    $pluginClass = $composer['extra']['shopware-plugin-class'];
                    $parts = explode('\\', $pluginClass);
                    $pluginName = end($parts);
                }
                // Plugin-Version aus composer.json extrahieren
                if (isset($composer['version'])) {
                    $pluginVersion = $composer['version'];
                }
            }

            if (!$pluginName && file_exists($extractedFolder . '/plugin.xml')) {
                $xml = simplexml_load_file($extractedFolder . '/plugin.xml');
                $pluginName = (string)$xml->name;
                // Fallback: Version aus plugin.xml wenn nicht in composer.json
                if (!$pluginVersion && isset($xml->version)) {
                    $pluginVersion = (string)$xml->version;
                }
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
            
            // Cache clearen nach Plugin-Installation
            exec('php bin/console cache:clear');
            
            // Git-Info in Datenbank speichern
            $this->saveGitInfo($pluginName, $githubUrl, $originalTag, $pluginVersion);

            return new JsonResponse([
                'success' => true,
                'message' => "Plugin '$pluginName' wurde erfolgreich installiert.",
                'version' => $originalTag, // Git-Referenz (Tag/Branch)
                'displayVersion' => $tag, // Angereicherte Version für Anzeige
                'pluginVersion' => $pluginVersion ?: $originalTag, // Plugin-Version oder Fallback
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
            // Fallback: Wenn keine Tags vorhanden, versuche passenden Branch zu finden
            return $this->getCompatibleBranch($repoUrl, $shopwareMajor);
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
        $repoPath = $this->extractRepoPath($repoUrl);
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
                    $this->isVersionCompatible($composer['require']['shopware/core'], $shopwareMajor)
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
                        str_contains($composer['require']['shopware/core'], $shopwareMajor)
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

    private function getAllCompatibleVersions(string $repoUrl): array
    {
        $version = InstalledVersions::getVersion('shopware/core');
        $parts = explode('.', $version);
        $shopwareMajor = $parts[0] . '.' . $parts[1];

        $compatibleTags = $this->getCompatibleShopwareTagsFromGithub($repoUrl, $shopwareMajor);
        
        if (empty($compatibleTags)) {
            // Fallback: Versuche kompatible Branches zu finden
            try {
                $branch = $this->getCompatibleBranch($repoUrl, $shopwareMajor);
                return [$this->enrichVersionWithPluginInfo($repoUrl, $branch)];
            } catch (\Exception $e) {
                return []; // Keine kompatiblen Versionen gefunden
            }
        }

        // Sortiere Versionen absteigend (neueste zuerst) und füge Plugin-Info hinzu
        $normalizedTags = array_map(function ($tag) use ($repoUrl) {
            return [
                'original' => $tag, 
                'normalized' => ltrim($tag, 'vV'),
                'enriched' => $this->enrichVersionWithPluginInfo($repoUrl, $tag)
            ];
        }, $compatibleTags);

        usort($normalizedTags, function($a, $b) {
            return version_compare($b['normalized'], $a['normalized']);
        });

        return array_column($normalizedTags, 'enriched');
    }

    private function enrichVersionWithPluginInfo(string $repoUrl, string $tagOrBranch): string
    {
        try {
            $repoPath = $this->extractRepoPath($repoUrl);
            $composerUrl = "https://raw.githubusercontent.com/$repoPath/$tagOrBranch/composer.json";
            
            $composerRaw = $this->curlGithub($composerUrl);
            $composer = json_decode($composerRaw, true);
            
            if (isset($composer['version'])) {
                return "$tagOrBranch (v{$composer['version']})";
            }
        } catch (\Throwable $e) {
            // Fallback: Nur Tag/Branch zurückgeben
        }
        
        return $tagOrBranch;
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

    private function extractOriginalVersion(string $enrichedVersion): string
    {
        // Extrahiert die ursprüngliche Tag/Branch-Referenz aus angereicherten Versionen
        // z.B. "6.7 (v1.7.0)" -> "6.7"
        // z.B. "v1.2.3 (v1.2.3)" -> "v1.2.3"
        if (strpos($enrichedVersion, ' (') !== false) {
            return trim(explode(' (', $enrichedVersion)[0]);
        }
        
        return $enrichedVersion;
    }

    private function saveGitInfo(string $pluginName, string $githubUrl, string $branch, ?string $pluginVersion): void
    {
        try {
            // Hole Plugin-ID aus der Plugin-Tabelle
            $pluginId = $this->connection->fetchOne(
                'SELECT id FROM plugin WHERE name = :name',
                ['name' => $pluginName]
            );

            if (!$pluginId) {
                error_log("Plugin '$pluginName' nicht in Datenbank gefunden");
                return;
            }

            // Hole aktuellen Commit-SHA für Branch
            $currentCommit = null;
            if ($this->isBranchName($branch)) {
                try {
                    $repoPath = $this->extractRepoPath($githubUrl);
                    $commitUrl = "https://api.github.com/repos/$repoPath/commits/$branch";
                    $commitData = json_decode($this->curlGithub($commitUrl), true);
                    $currentCommit = $commitData['sha'] ?? null;
                } catch (\Exception $e) {
                    error_log("Konnte Commit-SHA nicht abrufen: " . $e->getMessage());
                }
            }

            // Prüfe ob bereits ein Eintrag existiert
            $existingId = $this->connection->fetchOne(
                'SELECT id FROM plugin_git WHERE plugin_id = :plugin_id',
                ['plugin_id' => $pluginId]
            );

            if ($existingId) {
                // Update existierenden Eintrag
                $this->connection->executeStatement(
                    'UPDATE plugin_git SET 
                        github_url = :github_url,
                        installed_branch = :installed_branch,
                        installed_commit = :installed_commit,
                        plugin_version = :plugin_version,
                        updated_at = NOW()
                    WHERE plugin_id = :plugin_id',
                    [
                        'github_url' => $githubUrl,
                        'installed_branch' => $this->isBranchName($branch) ? $branch : null,
                        'installed_commit' => $currentCommit,
                        'plugin_version' => $pluginVersion,
                        'plugin_id' => $pluginId
                    ]
                );
            } else {
                // Erstelle neuen Eintrag
                $this->connection->executeStatement(
                    'INSERT INTO plugin_git (
                        id, plugin_id, github_url, source, installed_branch, 
                        installed_commit, plugin_version, created_at
                    ) VALUES (
                        :id, :plugin_id, :github_url, :source, :installed_branch,
                        :installed_commit, :plugin_version, NOW()
                    )',
                    [
                        'id' => Uuid::randomBytes(),
                        'plugin_id' => $pluginId,
                        'github_url' => $githubUrl,
                        'source' => 'git',
                        'installed_branch' => $this->isBranchName($branch) ? $branch : null,
                        'installed_commit' => $currentCommit,
                        'plugin_version' => $pluginVersion
                    ]
                );
            }
        } catch (\Exception $e) {
            error_log("Fehler beim Speichern der Git-Info: " . $e->getMessage());
        }
    }
}

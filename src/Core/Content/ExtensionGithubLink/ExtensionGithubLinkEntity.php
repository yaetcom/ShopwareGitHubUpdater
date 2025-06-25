<?php
declare(strict_types=1);

namespace KawsPluginGitUpdater\Core\Content\ExtensionGithubLink;

use Shopware\Core\Framework\DataAbstractionLayer\Entity;
use Shopware\Core\Framework\Plugin\PluginEntity;

class ExtensionGithubLinkEntity extends Entity
{
    protected string $pluginId;

    protected ?PluginEntity $plugin = null;

    protected string $githubUrl;
    
    protected ?string $installedBranch = null;
    
    protected ?string $installedCommit = null;
    
    protected ?string $pluginVersion = null;

    public function getPluginId (): string
    {
        return $this->pluginId;
    }

    public function setPluginId (string $pluginId): void
    {
        $this->pluginId = $pluginId;
    }

    public function getPlugin (): ?PluginEntity
    {
        return $this->plugin;
    }

    public function setPlugin (?PluginEntity $plugin): void
    {
        $this->plugin = $plugin;
    }

    public function getGithubUrl (): string
    {
        return $this->githubUrl;
    }

    public function setGithubUrl (string $githubUrl): void
    {
        $this->githubUrl = $githubUrl;
    }
    
    public function getInstalledBranch(): ?string
    {
        return $this->installedBranch;
    }
    
    public function setInstalledBranch(?string $installedBranch): void
    {
        $this->installedBranch = $installedBranch;
    }
    
    public function getInstalledCommit(): ?string
    {
        return $this->installedCommit;
    }
    
    public function setInstalledCommit(?string $installedCommit): void
    {
        $this->installedCommit = $installedCommit;
    }
    
    public function getPluginVersion(): ?string
    {
        return $this->pluginVersion;
    }
    
    public function setPluginVersion(?string $pluginVersion): void
    {
        $this->pluginVersion = $pluginVersion;
    }
}

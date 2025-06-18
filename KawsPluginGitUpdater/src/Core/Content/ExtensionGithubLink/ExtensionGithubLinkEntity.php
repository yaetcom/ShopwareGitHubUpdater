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
}

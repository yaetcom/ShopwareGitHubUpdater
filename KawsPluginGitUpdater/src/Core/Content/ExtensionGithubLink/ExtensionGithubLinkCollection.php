<?php
declare(strict_types=1);

namespace KawsPluginGitUpdater\Core\Content\ExtensionGithubLink;

use Shopware\Core\Framework\DataAbstractionLayer\EntityCollection;

/**
 * @method void add(ExtensionGithubLinkEntity $entity)
 * @method void set(string $key, ExtensionGithubLinkEntity $entity)
 * @method ExtensionGithubLinkEntity[] getIterator()
 * @method ExtensionGithubLinkEntity[] getElements()
 * @method ExtensionGithubLinkEntity|null get(string $key)
 * @method ExtensionGithubLinkEntity|null first()
 * @method ExtensionGithubLinkEntity|null last()
 */
class ExtensionGithubLinkCollection extends EntityCollection
{
    protected function getExpectedClass (): string
    {
        return ExtensionGithubLinkEntity::class;
    }
}

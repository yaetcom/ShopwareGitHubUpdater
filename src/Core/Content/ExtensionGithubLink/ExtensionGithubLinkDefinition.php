<?php
declare(strict_types=1);

namespace KawsPluginGitUpdater\Core\Content\ExtensionGithubLink;

use Shopware\Core\Framework\DataAbstractionLayer\EntityDefinition;
use Shopware\Core\Framework\DataAbstractionLayer\Field\CreatedAtField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\FkField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\ApiAware;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\PrimaryKey;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\Required;
use Shopware\Core\Framework\DataAbstractionLayer\Field\IdField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\ManyToOneAssociationField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\StringField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\UpdatedAtField;
use Shopware\Core\Framework\DataAbstractionLayer\FieldCollection;
use Shopware\Core\Framework\Plugin\PluginDefinition;

class ExtensionGithubLinkDefinition extends EntityDefinition
{
    public const ENTITY_NAME = 'plugin_git';

    public function getEntityName (): string
    {
        return self::ENTITY_NAME;
    }

    public function getCollectionClass (): string
    {
        return ExtensionGithubLinkCollection::class;
    }

    public function getEntityClass (): string
    {
        return ExtensionGithubLinkEntity::class;
    }

    protected function defineFields(): FieldCollection
    {
        return new FieldCollection([
            (new IdField('id', 'id'))->addFlags(
                new PrimaryKey(),
                new Required(),
                new ApiAware()
            ),

            (new FkField('plugin_id', 'pluginId', PluginDefinition::class))->addFlags(
                new Required(),
                new ApiAware()
            ),
            new ManyToOneAssociationField(
                'plugin',
                'plugin_id',
                PluginDefinition::class,
                'id',
                false
            ),

            (new StringField('github_url', 'githubUrl'))->addFlags(
                new Required(),
                new ApiAware()
            ),

            (new StringField('source', 'source'))
                ->addFlags(new Required(), new ApiAware()),

            (new StringField('installed_branch', 'installedBranch'))
                ->addFlags(new ApiAware()),
                
            (new StringField('installed_commit', 'installedCommit'))
                ->addFlags(new ApiAware()),
                
            (new StringField('plugin_version', 'pluginVersion'))
                ->addFlags(new ApiAware()),

            new CreatedAtField(),
            new UpdatedAtField(),
        ]);
    }

}

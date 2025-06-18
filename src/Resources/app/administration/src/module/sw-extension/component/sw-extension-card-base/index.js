import template from './sw-extension-card-base.html.twig';

const { Criteria } = Shopware.Data;
const { Component, Utils, Mixin } = Shopware;

Component.override('sw-extension-card-base', {
    template,

    inject: [
        'systemConfigApiService',
        'shopwareExtensionService',
        'extensionStoreActionService',
        'cacheApiService',
        'repositoryFactory'
    ],

    props: {
        extension: {
            type: Object,
            required: true,
        },
    },

    data() {
        return {
            isLoading: false,
            updateMessage: '',
            showGitSettings: false,
        }
    },

    created() {
        this.loadExtensionsGit(this.extension);
    },

    computed: {
        sourceOptions() {
            return [
                { value: 'shopware', name: 'Shopware Store' },
                { value: 'git', name: 'GitHub' },
            ];
        },
    },

    methods: {
        async loadExtensionsGit(extension) {
            const extensionGitRepository = this.repositoryFactory.create('plugin_git');

            const criteria = new Criteria();

            criteria.addFilter(Criteria.equals('pluginId', extension.localId));

            const gitEntries = await extensionGitRepository.search(criteria, Shopware.Context.api);
            const gitEntry = gitEntries.first();

            if(gitEntry && gitEntry.source === 'git') {
                this.extension.updateGitSource = gitEntry.source;
                this.extension.repositoryUrl = gitEntry.githubUrl;
            } else {
                this.extension.updateGitSource = 'shopware';
                this.extension.repositoryUrl = '';
            }
        },


    }
});


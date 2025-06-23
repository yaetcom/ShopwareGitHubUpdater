import template from './sw-extension-my-extensions-index.html.twig';

const { Criteria } = Shopware.Data;
const { Component, Utils } = Shopware;

Component.override('sw-extension-my-extensions-index', {
    template,

    inject: [
        'systemConfigApiService',
        'shopwareExtensionService',
        'extensionStoreActionService',
        'cacheApiService',
        'repositoryFactory'
    ],

    mixins: ['sw-extension-error'],

    data() {
        return {
            showGitInstallModal: false,
            gitInstallUrl: '',
            gitInstallPluginName: '',
            isInstalling: false,
        };
    },

    computed: {
        isShopware67() {
            const version = Shopware.Context.app.config.version.slice(0,3);
            return version === '6.7';
        }
    },

    methods: {
        onGitInstall() {
            this.gitInstallUrl = '';
            this.gitInstallPluginName = '';
            this.showGitInstallModal = true;
        },

        async submitGitInstall() {
            this.isInstalling = true;

            try {
                const response = await fetch('/api/_action/github/install-plugin', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        Authorization: `Bearer ${Shopware.Context.api.authToken.access}`,
                    },
                    body: JSON.stringify({
                        url: this.gitInstallUrl
                    }),
                });

                const result = await response.json();

                if (!result.success) {
                    throw new Error(result.message);
                }

                this.createNotificationSuccess({
                    title: 'Installation erfolgreich',
                    message: `Plugin "${result.pluginName}" wurde installiert.`,
                });

                this.showGitInstallModal = false;
                this.$emit('update-list');
            } catch (error) {
                this.createNotificationError({
                    title: 'Fehler bei Installation',
                    message: error.message,
                });
            } finally {
                this.isInstalling = false;
            }
        },
    }
});
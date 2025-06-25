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
            availableVersions: [],
            selectedVersion: null,
            isLoadingVersions: false,
            showVersionSelection: false,
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
            this.availableVersions = [];
            this.selectedVersion = null;
            this.showVersionSelection = false;
            this.showGitInstallModal = true;
        },

        async loadAvailableVersions() {
            if (!this.gitInstallUrl) return;

            this.isLoadingVersions = true;

            try {
                const response = await fetch(`/api/_action/github/install-versions?url=${encodeURIComponent(this.gitInstallUrl)}`, {
                    method: 'GET',
                    headers: {
                        'Content-Type': 'application/json',
                        Authorization: `Bearer ${Shopware.Context.api.authToken.access}`,
                    },
                });

                const result = await response.json();

                if (result.success && result.versions.length > 0) {
                    this.availableVersions = result.versions;
                    this.selectedVersion = result.versions[0]; // Wähle neueste Version als Standard
                    this.showVersionSelection = true;
                } else {
                    this.availableVersions = [];
                    this.selectedVersion = null;
                    this.showVersionSelection = false;
                }
            } catch (error) {
                this.createNotificationError({
                    title: 'Fehler beim Laden der Versionen',
                    message: error.message,
                });
                this.availableVersions = [];
                this.selectedVersion = null;
                this.showVersionSelection = false;
            } finally {
                this.isLoadingVersions = false;
            }
        },

        async submitGitInstall() {
            this.isInstalling = true;

            try {
                const requestBody = {
                    url: this.gitInstallUrl
                };

                // Nur Version hinzufügen wenn eine ausgewählt wurde
                if (this.selectedVersion) {
                    requestBody.version = this.selectedVersion;
                }

                const response = await fetch('/api/_action/github/install-plugin', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        Authorization: `Bearer ${Shopware.Context.api.authToken.access}`,
                    },
                    body: JSON.stringify(requestBody),
                });

                const result = await response.json();

                if (!result.success) {
                    throw new Error(result.message);
                }

                this.createNotificationSuccess({
                    title: 'Installation erfolgreich',
                    message: `Plugin "${result.pluginName}" wurde installiert. Seite wird neu geladen...`,
                });

                this.showGitInstallModal = false;
                this.$emit('update-list');
                
                // Cache API Service für Cache-Clear nutzen (falls verfügbar)
                if (this.cacheApiService) {
                    this.cacheApiService.clear().finally(() => {
                        // Seite nach Cache-Clear neu laden
                        setTimeout(() => {
                            window.location.reload();
                        }, 1000);
                    });
                } else {
                    // Fallback: Direkt neu laden
                    setTimeout(() => {
                        window.location.reload();
                    }, 2000);
                }
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
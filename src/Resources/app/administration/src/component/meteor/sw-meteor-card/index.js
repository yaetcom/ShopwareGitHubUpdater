import template from './sw-meteor-card.html.twig';
import './sw-meteor-card.scss';

const { Criteria } = Shopware.Data;
const { Component, Utils, Mixin } = Shopware;

Component.override('sw-meteor-card', {
    template,

    inject: [
        'systemConfigApiService',
        'shopwareExtensionService',
        'extensionStoreActionService',
        'cacheApiService',
        'repositoryFactory'
    ],

    mixins: ['sw-extension-error'],

    emits: ['update-list'],


    props: {
        title: {
            type: String,
            required: false,
            default: null,
        },
        hero: {
            type: Boolean,
            required: false,
            default: false,
        },
        isLoading: {
            type: Boolean,
            required: false,
            default: false,
        },
        large: {
            type: Boolean,
            required: false,
            default: false,
        },
        defaultTab: {
            type: String,
            required: false,
            default: null,
        },
        isExtension: {
            type: Boolean,
            required: false,
            default: true,
        },
        extension: {
            type: Object,
            required: false,
            default: () => ({}),
        },
    },


    data() {
        return {
            githubUrl: null,
            versionOptions: [],
            selectedVersion: null,
            isLoadingVersions: false,
            isLoading: false,
            updateMessage: '',
            localRepositoryUrl: '',
        }
    },

    computed: {
        currentExtension() {
            // Versuche Extension-Daten von verschiedenen Quellen zu bekommen
            if (this.extension && Object.keys(this.extension).length > 0) {
                return this.extension;
            }
            
            // Fallback: Suche in Parent-Component
            if (this.$parent && this.$parent.extension) {
                return this.$parent.extension;
            }
            
            // Fallback: Suche in Grandparent
            if (this.$parent && this.$parent.$parent && this.$parent.$parent.extension) {
                return this.$parent.$parent.extension;
            }
            
            return {};
        },
        
        isShopware67() {
            const version = Shopware.Context.app.config.version.slice(0,3);
            return version === '6.7';
        }
    },

    created() {
        this.checkForPendingUpdate();
    },

    watch: {
        currentExtension: {
            handler(newExtension) {
                if (newExtension && newExtension.localId) {
                    this.loadExtensionGitSettings(newExtension);
                }
            },
            immediate: true
        }
    },

    methods: {
        async checkUpdate() {
            if (!this.localRepositoryUrl) {
                this.createNotificationError({
                    title: 'Fehler',
                    message: 'Bitte geben Sie eine GitHub-URL ein.',
                });
                return;
            }

            this.isLoadingVersions = true;
            console.log('Loading started, isLoadingVersions:', this.isLoadingVersions);
            try {
                console.log('Checking updates for:', this.currentExtension.name, 'from:', this.localRepositoryUrl);
                // Temporär repositoryUrl für die API setzen
                const tempExtension = { ...this.currentExtension, repositoryUrl: this.localRepositoryUrl };
                await this.checkForNewVersion(tempExtension);
            } catch (error) {
                this.createNotificationError({
                    title: 'Fehler beim Laden der Versionen',
                    message: error.message,
                });
            } finally {
                this.isLoadingVersions = false;
                console.log('Loading finished, isLoadingVersions:', this.isLoadingVersions);
            }
        },

        async Update() {
            if (!this.selectedVersion) {
                this.createNotificationError({
                    title: 'Fehler',
                    message: 'Bitte wählen Sie eine Version aus.',
                });
                return;
            }
            this.updatePluginGithub(this.selectedVersion);
            console.log('Updating extension:', this.currentExtension.name, 'to version:', this.selectedVersion);
        },

        async loadExtensionGitSettings(extension) {
            try {
                const extensionGitRepository = this.repositoryFactory.create('plugin_git');
                const criteria = new Criteria();
                criteria.addFilter(Criteria.equals('pluginId', extension.localId));

                const gitEntries = await extensionGitRepository.search(criteria, Shopware.Context.api);
                const gitEntry = gitEntries.first();

                if (gitEntry && gitEntry.githubUrl) {
                    this.localRepositoryUrl = gitEntry.githubUrl;
                } else {
                    this.localRepositoryUrl = '';
                }
            } catch (error) {
                console.error('Failed to load git settings:', error);
                this.localRepositoryUrl = '';
            }
        },

        async checkForPendingUpdate() {
            const pendingUpdate = localStorage.getItem('pendingExtensionUpdate');
            if (pendingUpdate) {
                try {
                    const updateData = JSON.parse(pendingUpdate);
                    console.log('Pending update found:', updateData);
                    
                    // Prüfe ob das die richtige Extension ist
                    if (updateData.extensionName === this.currentExtension.name) {
                        // LocalStorage Flag entfernen
                        localStorage.removeItem('pendingExtensionUpdate');
                        
                        // Shopware Extension Update durchführen
                        await this.shopwareExtensionService.updateExtension(
                            updateData.extensionName,
                            updateData.extensionType,
                            false
                        );
                        
                        this.createNotificationSuccess({
                            title: 'Update abgeschlossen',
                            message: `${this.currentExtension.label} wurde erfolgreich auf Version ${updateData.version} aktualisiert!`,
                        });
                        
                        console.log('Extension update completed');
                    }
                } catch (error) {
                    console.error('Failed to apply pending update:', error);
                    localStorage.removeItem('pendingExtensionUpdate');
                }
            }
        },

        async updatePluginGithub(version) {
            this.isLoading = true;
            this.updateMessage = 'Lade Plugin von GitHub...';
            try {
                const response = await fetch('/api/_action/github/update-plugin', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        Authorization: `Bearer ${Shopware.Context.api.authToken.access}`,
                    },
                    body: JSON.stringify({ 
                        url: this.localRepositoryUrl, 
                        name: this.currentExtension.name,
                        version: version
                    }),
                });


                const result = await response.json();
                console.log(result);

                if (!response.ok) {
                    throw new Error(result.message || 'Update fehlgeschlagen.');
                }
                
                this.createNotificationSuccess({
                    title: 'Update erfolgreich',
                    message: this.currentExtension.label + ' wurde auf Version ' + version + ' aktualisiert. Seite wird neu geladen...',
                });

                // Flag setzen, dass nach Reload ein Update durchgeführt werden soll
                localStorage.setItem('pendingExtensionUpdate', JSON.stringify({
                    extensionName: this.currentExtension.name,
                    extensionType: this.currentExtension.type,
                    version: version
                }));

                // Cache leeren und Seite neu laden
                setTimeout(async () => {
                    await this.cacheApiService.clear();
                    window.location.reload();
                }, 2000);
                
                this.isLoading = false;
            } catch (error) {
                this.createNotificationError({
                    title: 'Update fehlgeschlagen',
                    message: 'Ungültige URL',
                });
                this.isLoading = false;
            }
        },

        async checkForNewVersion(extension) {
            try {
                if (extension.repositoryUrl.length > 0) {
                    const response = await fetch(`/api/_action/github/check-version?name=${encodeURIComponent(extension.repositoryUrl)}`, {
                        method: 'GET',
                        headers: {
                            Authorization: `Bearer ${Shopware.Context.api.authToken.access}`,
                        },
                    });

                    const result = await response.json();
                    console.log(result);
                    
                    if (result.success && result.versions) {
                        // Alle Versionen verarbeiten
                        const allVersions = result.versions.map(version => {
                            // Entferne "v" am Anfang falls vorhanden
                            const cleanVersion = version.replace(/^v/, '');
                            return {
                                original: version,
                                clean: cleanVersion,
                                label: cleanVersion,
                                value: version
                            };
                        });

                        // Aktuelle Version für Vergleich vorbereiten
                        const currentVersion = extension.version.replace(/^v/, '');
                        
                        // Filtere nur höhere Versionen
                        const higherVersions = allVersions.filter(version => {
                            return this.isVersionHigher(version.clean, currentVersion);
                        });

                        // Update die Options für das Select-Field
                        this.versionOptions = higherVersions.map(version => ({
                            label: version.label,
                            value: version.value
                        }));

                        if (higherVersions.length > 0) {
                            // Automatisch die erste (neueste) Version auswählen
                            this.selectedVersion = higherVersions[0].value;
                            
                            this.createNotificationInfo({
                                title: `${higherVersions.length} neuere Version(en) gefunden`,
                                message: `Neueste verfügbare Version: ${higherVersions[0].label}`,
                            });
                        } else {
                            this.createNotificationInfo({
                                title: `${extension.label} ist aktuell`,
                                message: `Du hast bereits die neueste Version: ${currentVersion}`,
                            });
                        }
                    } else {
                        this.createNotificationError({
                            title: `Fehler mit URL`,
                            message: `Ungültige URL oder keine Versionen gefunden`,
                        });
                    }
                } else {
                    this.createNotificationError({
                        title: `Fehler mit URL`,
                        message: `Keine URL hinterlegt.`,
                    });
                }
            } catch (error) {
                this.createNotificationError({
                    title: `Fehler bei der Versionsprüfung für ${extension.name}`,
                    message: error.message,
                });
            }
        },

        // Hilfsfunktion zum Vergleichen von Versionen
        compareVersions(a, b) {
            const aParts = a.split('.').map(Number);
            const bParts = b.split('.').map(Number);
            
            for (let i = 0; i < Math.max(aParts.length, bParts.length); i++) {
                const aPart = aParts[i] || 0;
                const bPart = bParts[i] || 0;
                
                if (aPart > bPart) return 1;
                if (aPart < bPart) return -1;
            }
            return 0;
        },

        // Prüft ob Version A höher als Version B ist
        isVersionHigher(versionA, versionB) {
            return this.compareVersions(versionA, versionB) > 0;
        },

        updatePluginKaws() {
            this.clearCacheAndReloadPage();
            this.clearCacheAndReloadPage();
        },

        clearCacheAndReloadPage() {
            return this.cacheApiService.clear().then(() => {
                window.location.reload();
            });
        },

        async onSaveSettings() {
            try {
                this.isLoading = true;

                const extensionGitRepository = this.repositoryFactory.create('plugin_git');

                if (!this.currentExtension.localId) {
                    throw new Error('Keine lokale Plugin-ID gefunden.');
                }

                const extensionGitCriteria = new Criteria();
                extensionGitCriteria.addFilter(Criteria.equals('pluginId', this.currentExtension.localId));

                const existingGitEntryResult = await extensionGitRepository.search(extensionGitCriteria, Shopware.Context.api);
                const existingGitEntry = existingGitEntryResult.first();

                // Wenn URL leer ist
                if (!this.localRepositoryUrl || this.localRepositoryUrl.trim() === '') {
                    if (existingGitEntry) {
                        // Eintrag löschen falls vorhanden
                        await extensionGitRepository.delete(existingGitEntry.id, Shopware.Context.api);
                        this.createNotificationSuccess({
                            title: 'Erfolgreich gelöscht',
                            message: 'Die Git-Einstellungen wurden entfernt.',
                        });
                    }
                    // Nichts tun wenn kein Eintrag vorhanden ist
                    return;
                }

                // URL ist nicht leer - speichern
                let entity;

                if (existingGitEntry) {
                    entity = existingGitEntry;
                } else {
                    entity = extensionGitRepository.create(Shopware.Context.api);
                    entity.pluginId = this.currentExtension.localId;
                    entity.source = 'git'; // Standard Wert für source
                }
                entity.githubUrl = this.localRepositoryUrl;
                entity.source = 'git'; // Sicherstellen dass source immer gesetzt ist

                await extensionGitRepository.save(entity, Shopware.Context.api);

                this.createNotificationSuccess({
                    title: 'Erfolgreich gespeichert',
                    message: 'Die Git-Einstellungen wurden gespeichert.',
                });

            } catch (error) {
                this.createNotificationError({
                    title: 'Fehler beim Speichern',
                    message: error.message,
                });
            } finally {
                this.isLoading = false;
            }
        },



}

});
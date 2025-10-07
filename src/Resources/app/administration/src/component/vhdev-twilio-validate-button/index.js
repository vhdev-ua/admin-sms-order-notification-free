import template from './vhdev-twilio-validate-button.html.twig';

const { Component, Mixin } = Shopware;

Component.register('vhdev-twilio-validate-button', {
    template,

    inject: ['systemConfigApiService', 'loginService'],

    mixins: [
        Mixin.getByName('notification')
    ],

    data() {
        return {
            isLoading: false,
            isValidated: false
        };
    },

    computed: {
        buttonDisabled() {
            return this.isLoading;
        },

        salesChannelId() {
            // Піднімаємося по дереву до sw-system-config компонента
            let parent = this.$parent;
            let level = 0;
            
            while (parent && level < 20) {
                // Шукаємо currentSalesChannelId або salesChannelId
                if (parent.currentSalesChannelId) {
                    if (typeof parent.currentSalesChannelId === 'string' && parent.currentSalesChannelId !== 'null') {
                        return parent.currentSalesChannelId;
                    }
                }
                
                if (parent.salesChannelId) {
                    if (typeof parent.salesChannelId === 'string' && parent.salesChannelId !== 'null') {
                        return parent.salesChannelId;
                    }
                }
                
                parent = parent.$parent;
                level++;
            }
            
            return null;
        }
    },

    methods: {
        async validateCredentials() {
            this.isLoading = true;
            this.isValidated = false;

            try {
                const salesChannelId = this.salesChannelId;

                // Отримуємо токен
                let bearerToken = null;
                try {
                    const bearerAuth = JSON.parse(localStorage.getItem('bearerAuth'));
                    if (bearerAuth && bearerAuth.access) {
                        bearerToken = bearerAuth.access;
                    }
                } catch (e) {
                    // Fallback to loginService
                }

                if (!bearerToken) {
                    const auth = this.loginService.getBearerAuthentication();
                    if (auth && auth.access) {
                        bearerToken = auth.access;
                    }
                }

                const headers = {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json'
                };

                if (bearerToken) {
                    headers['Authorization'] = `Bearer ${bearerToken}`;
                }

                const response = await fetch('/api/_action/vhdev-sms/validate-twilio', {
                    method: 'POST',
                    headers: headers,
                    credentials: 'include',
                    body: JSON.stringify({
                        salesChannelId: salesChannelId
                    })
                });

                if (!response.ok) {
                    const errorData = await response.json().catch(() => ({}));
                    throw new Error(errorData.errors?.[0]?.detail || errorData.message || `HTTP ${response.status}`);
                }

                const result = await response.json();

                if (result.valid) {
                    this.isValidated = true;
                    let message = result.message;
                    if (result.accountName) {
                        message += ` (${result.accountName})`;
                    }
                    
                    this.createNotificationSuccess({
                        title: this.$tc('vhdev-sms.validation.successTitle'),
                        message: message
                    });
                } else {
                    this.createNotificationError({
                        title: this.$tc('vhdev-sms.validation.errorTitle'),
                        message: result.message || this.$tc('vhdev-sms.validation.errorMessage')
                    });
                }
            } catch (error) {
                console.error('Twilio validation error:', error);
                
                this.createNotificationError({
                    title: this.$tc('vhdev-sms.validation.errorTitle'),
                    message: error.message || this.$tc('vhdev-sms.validation.errorDefault')
                });
            } finally {
                this.isLoading = false;
            }
        }
    }
});

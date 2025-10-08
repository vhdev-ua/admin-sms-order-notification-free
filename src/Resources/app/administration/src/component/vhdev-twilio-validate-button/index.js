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
            // Traverse up the component tree to sw-system-config
            let parent = this.$parent;
            let level = 0;
            
            while (parent && level < 20) {
                // Search for currentSalesChannelId or salesChannelId
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
        translateMessage(backendMessage, result) {
            // Map backend messages to translation keys
            const messageMap = {
                'Twilio SID and Auth Token are not configured': 'vhdev-sms.validation.messages.missingSidToken',
                'Twilio From Number is not configured': 'vhdev-sms.validation.messages.missingFromNumber',
                'The provided phone number is not valid': 'vhdev-sms.validation.messages.invalidPhoneNumber',
                'Invalid credentials': 'vhdev-sms.validation.messages.invalidCredentials',
                'Failed to connect': 'vhdev-sms.validation.messages.connectionError'
            };

            // Check if backend message matches any known pattern
            for (const [pattern, translationKey] of Object.entries(messageMap)) {
                if (backendMessage && backendMessage.includes(pattern)) {
                    return this.$tc(translationKey);
                }
            }

            // If it's a success message, build translated version
            if (result && result.valid) {
                let message = this.$tc('vhdev-sms.validation.messages.credentialsValid');
                
                if (result.testSmsSent && result.testSmsResults) {
                    const successCount = result.testSmsResults.filter(r => r.result?.success).length;
                    const totalCount = result.testSmsResults.length;
                    
                    message += ' ' + this.$tc('vhdev-sms.validation.messages.testSmsSent', 0, {
                        successCount: successCount,
                        totalCount: totalCount
                    });
                }
                
                if (result.accountName) {
                    message += ` (${result.accountName})`;
                }
                
                return message;
            }

            // Return original message if no translation found
            return backendMessage;
        },

        async validateCredentials() {
            this.isLoading = true;
            this.isValidated = false;

            try {
                const salesChannelId = this.salesChannelId;

                // Get bearer token
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
                    const errorMessage = errorData.errors?.[0]?.detail || errorData.message;
                    
                    throw new Error(this.translateMessage(errorMessage, errorData));
                }

                const result = await response.json();

                if (result.valid) {
                    this.isValidated = true;
                    const translatedMessage = this.translateMessage(result.message, result);
                    
                    this.createNotificationSuccess({
                        title: this.$tc('vhdev-sms.validation.successTitle'),
                        message: translatedMessage
                    });
                } else {
                    const translatedMessage = this.translateMessage(result.message, result);
                    
                    this.createNotificationError({
                        title: this.$tc('vhdev-sms.validation.errorTitle'),
                        message: translatedMessage || this.$tc('vhdev-sms.validation.errorMessage')
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

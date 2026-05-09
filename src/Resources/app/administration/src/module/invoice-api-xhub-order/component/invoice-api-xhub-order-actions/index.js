import template from './invoice-api-xhub-order-actions.html.twig'

const { Component, Mixin } = Shopware

Component.register('invoice-api-xhub-order-actions', {
  template,

  inject: ['acl', 'repositoryFactory', 'invoiceApiXhubApiService'],

  mixins: [Mixin.getByName('notification')],

  props: {
    orderId: { type: String, required: true },
  },

  data() {
    return {
      isLoading: false,
      invoiceMeta: null, // populated from order.customFields
    }
  },

  computed: {
    hasInvoice() {
      return Boolean(this.invoiceMeta && this.invoiceMeta.filepath)
    },
    canRegenerate() {
      // Fall back to true when ACL is not yet wired so the button stays usable in dev.
      if (!this.acl || typeof this.acl.can !== 'function') {
        return true
      }
      return this.acl.can('order:update')
    },
    orderRepository() {
      return this.repositoryFactory.create('order')
    },
  },

  created() {
    this.loadOrderMeta()
  },

  methods: {
    async loadOrderMeta() {
      try {
        const criteria = new Shopware.Data.Criteria(1, 1)
        const order = await this.orderRepository.get(
          this.orderId,
          Shopware.Context.api,
          criteria,
        )
        const customFields = (order && order.customFields) || {}
        this.invoiceMeta = {
          filepath: customFields.invoice_api_xhub_filepath || null,
          filename: customFields.invoice_api_xhub_filename || null,
          format: customFields.invoice_api_xhub_format || null,
          generatedAt: customFields.invoice_api_xhub_generated_at || null,
          lastError: customFields.invoice_api_xhub_last_error || null,
        }
      } catch (e) {
        this.invoiceMeta = null
      }
    },

    async onRegenerate() {
      this.isLoading = true
      try {
        await this.invoiceApiXhubApiService.regenerate(this.orderId)
        this.createNotificationSuccess({
          message: this.$tc('invoice-api-xhub.actions.regenerateSuccess'),
        })
        await this.loadOrderMeta()
      } catch (e) {
        const msg = (e && (e.response?.data?.errors?.[0]?.detail || e.message)) || ''
        this.createNotificationError({
          message: this.$tc(
            'invoice-api-xhub.actions.regenerateError',
            0,
            { message: msg },
          ),
        })
      } finally {
        this.isLoading = false
      }
    },

    async onDownload() {
      try {
        const response = await this.invoiceApiXhubApiService.download(this.orderId)
        const blob = response.data instanceof Blob
          ? response.data
          : new Blob([response.data])
        const url = window.URL.createObjectURL(blob)
        const a = document.createElement('a')
        a.href = url
        a.download = (this.invoiceMeta && this.invoiceMeta.filename) || `invoice-${this.orderId}`
        document.body.appendChild(a)
        a.click()
        a.remove()
        window.URL.revokeObjectURL(url)
      } catch (e) {
        this.createNotificationError({
          message: this.$tc('invoice-api-xhub.actions.downloadFailed'),
        })
      }
    },
  },
})

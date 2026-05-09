import template from './invoice-api-xhub-logs-tab.html.twig'

const { Component, Mixin } = Shopware

Component.register('invoice-api-xhub-logs-tab', {
  template,

  inject: ['invoiceApiXhubApiService'],

  mixins: [Mixin.getByName('notification')],

  props: {
    orderId: { type: String, required: true },
  },

  data() {
    return {
      isLoading: false,
      entries: [],
      error: null,
    }
  },

  computed: {
    hasEntries() {
      return Array.isArray(this.entries) && this.entries.length > 0
    },
    columns() {
      return [
        { property: 'timestamp', label: 'invoice-api-xhub.logs.col.timestamp', primary: true },
        { property: 'action', label: 'invoice-api-xhub.logs.col.action' },
        { property: 'format', label: 'invoice-api-xhub.logs.col.format' },
        { property: 'status', label: 'invoice-api-xhub.logs.col.status' },
        { property: 'filename', label: 'invoice-api-xhub.logs.col.filename' },
        { property: 'message', label: 'invoice-api-xhub.logs.col.message' },
      ]
    },
  },

  watch: {
    orderId: { immediate: true, handler: 'loadLogs' },
  },

  methods: {
    async loadLogs() {
      if (!this.orderId) return
      this.isLoading = true
      this.error = null
      try {
        const response = await this.invoiceApiXhubApiService.logs(this.orderId)
        this.entries = response?.data?.entries ?? []
      } catch (e) {
        this.error = (e && e.message) || 'Failed to load logs'
        this.entries = []
      } finally {
        this.isLoading = false
      }
    },

    statusVariant(status) {
      switch (status) {
        case 'success':
          return 'success'
        case 'error':
          return 'danger'
        case 'skipped':
          return 'neutral'
        default:
          return 'info'
      }
    },

    formatTimestamp(iso) {
      if (!iso) return ''
      try {
        return new Date(iso).toLocaleString()
      } catch {
        return iso
      }
    },
  },
})

(() => {
  // src/module/invoice-api-xhub-order/extension/sw-order-detail-general/sw-order-detail-general.html.twig
  var sw_order_detail_general_html_default = '{% block sw_order_detail_general %}\n    {% parent %}\n\n    <mt-card\n        class="invoice-api-xhub-card"\n        position-identifier="invoice-api-xhub-order-card"\n        title="Invoice-api.xhub"\n    >\n        <invoice-api-xhub-order-actions :order-id="order.id" />\n        <invoice-api-xhub-logs-tab :order-id="order.id" />\n    </mt-card>\n{% endblock %}\n';

  // src/module/invoice-api-xhub-order/extension/sw-order-detail-general/index.js
  var { Component } = Shopware;
  Component.override("sw-order-detail-general", {
    template: sw_order_detail_general_html_default
  });

  // src/module/invoice-api-xhub-order/component/invoice-api-xhub-order-actions/invoice-api-xhub-order-actions.html.twig
  var invoice_api_xhub_order_actions_html_default = `{% block invoice_api_xhub_order_actions %}
<sw-card :title="$tc('invoice-api-xhub.actions.title')" class="invoice-api-xhub-order-actions">
    <template v-if="hasInvoice">
        <div class="invoice-api-xhub-meta">
            <p>
                <strong>{{ $tc('invoice-api-xhub.actions.filename') }}:</strong>
                {{ invoiceMeta.filename }}
            </p>
            <p v-if="invoiceMeta.generatedAt">
                <strong>{{ $tc('invoice-api-xhub.actions.generatedAt') }}:</strong>
                {{ invoiceMeta.generatedAt }}
            </p>
        </div>
        <div class="invoice-api-xhub-buttons">
            <sw-button
                variant="primary"
                :disabled="!canRegenerate || isLoading"
                @click="onRegenerate"
            >
                <span v-if="isLoading">{{ $tc('invoice-api-xhub.actions.regenerateInProgress') }}</span>
                <span v-else>{{ $tc('invoice-api-xhub.actions.regenerate') }}</span>
            </sw-button>
            <sw-button
                :disabled="isLoading"
                @click="onDownload"
            >
                {{ $tc('invoice-api-xhub.actions.download') }}
            </sw-button>
        </div>
    </template>
    <template v-else>
        <p>{{ $tc('invoice-api-xhub.actions.noInvoiceYet') }}</p>
        <p
            v-if="invoiceMeta && invoiceMeta.lastError"
            class="invoice-api-xhub-last-error"
        >
            <strong>{{ $tc('invoice-api-xhub.actions.lastError') }}:</strong>
            {{ invoiceMeta.lastError }}
        </p>
        <sw-button
            variant="primary"
            :disabled="!canRegenerate || isLoading"
            @click="onRegenerate"
        >
            <span v-if="isLoading">{{ $tc('invoice-api-xhub.actions.regenerateInProgress') }}</span>
            <span v-else>{{ $tc('invoice-api-xhub.actions.regenerate') }}</span>
        </sw-button>
    </template>
</sw-card>
{% endblock %}
`;

  // src/module/invoice-api-xhub-order/component/invoice-api-xhub-order-actions/index.js
  var { Component: Component2, Mixin } = Shopware;
  Component2.register("invoice-api-xhub-order-actions", {
    template: invoice_api_xhub_order_actions_html_default,
    inject: ["acl", "repositoryFactory", "invoiceApiXhubApiService"],
    mixins: [Mixin.getByName("notification")],
    props: {
      orderId: { type: String, required: true }
    },
    data() {
      return {
        isLoading: false,
        invoiceMeta: null
        // populated from order.customFields
      };
    },
    computed: {
      hasInvoice() {
        return Boolean(this.invoiceMeta && this.invoiceMeta.filepath);
      },
      canRegenerate() {
        if (!this.acl || typeof this.acl.can !== "function") {
          return true;
        }
        return this.acl.can("order:update");
      },
      orderRepository() {
        return this.repositoryFactory.create("order");
      }
    },
    created() {
      this.loadOrderMeta();
    },
    methods: {
      async loadOrderMeta() {
        try {
          const criteria = new Shopware.Data.Criteria(1, 1);
          const order = await this.orderRepository.get(
            this.orderId,
            Shopware.Context.api,
            criteria
          );
          const customFields = order && order.customFields || {};
          this.invoiceMeta = {
            filepath: customFields.invoice_api_xhub_filepath || null,
            filename: customFields.invoice_api_xhub_filename || null,
            format: customFields.invoice_api_xhub_format || null,
            generatedAt: customFields.invoice_api_xhub_generated_at || null,
            lastError: customFields.invoice_api_xhub_last_error || null
          };
        } catch (e) {
          this.invoiceMeta = null;
        }
      },
      async onRegenerate() {
        this.isLoading = true;
        try {
          await this.invoiceApiXhubApiService.regenerate(this.orderId);
          this.createNotificationSuccess({
            message: this.$tc("invoice-api-xhub.actions.regenerateSuccess")
          });
          await this.loadOrderMeta();
        } catch (e) {
          const msg = e && (e.response?.data?.errors?.[0]?.detail || e.message) || "";
          this.createNotificationError({
            message: this.$tc(
              "invoice-api-xhub.actions.regenerateError",
              0,
              { message: msg }
            )
          });
        } finally {
          this.isLoading = false;
        }
      },
      async onDownload() {
        try {
          const response = await this.invoiceApiXhubApiService.download(this.orderId);
          const blob = response.data instanceof Blob ? response.data : new Blob([response.data]);
          const url = window.URL.createObjectURL(blob);
          const a = document.createElement("a");
          a.href = url;
          a.download = this.invoiceMeta && this.invoiceMeta.filename || `invoice-${this.orderId}`;
          document.body.appendChild(a);
          a.click();
          a.remove();
          window.URL.revokeObjectURL(url);
        } catch (e) {
          this.createNotificationError({
            message: this.$tc("invoice-api-xhub.actions.downloadFailed")
          });
        }
      }
    }
  });

  // src/module/invoice-api-xhub-order/component/invoice-api-xhub-logs-tab/invoice-api-xhub-logs-tab.html.twig
  var invoice_api_xhub_logs_tab_html_default = `{% block invoice_api_xhub_logs_tab %}
<sw-card :title="$tc('invoice-api-xhub.logs.title')" class="invoice-api-xhub-logs-tab">
    <template #toolbar>
        <sw-button
            @click="loadLogs"
            :is-loading="isLoading"
            size="small"
            variant="ghost"
        >
            {{ $tc('invoice-api-xhub.logs.refresh') }}
        </sw-button>
    </template>

    <sw-loader v-if="isLoading" />

    <sw-empty-state
        v-else-if="!hasEntries && !error"
        :title="$tc('invoice-api-xhub.logs.empty')"
        :subline="$tc('invoice-api-xhub.logs.emptySubline')"
        icon="regular-file-text"
    />

    <div v-else-if="error" class="invoice-api-xhub-logs__error">
        <sw-alert variant="error">{{ error }}</sw-alert>
    </div>

    <sw-data-grid
        v-else
        :data-source="entries"
        :columns="columns"
        :show-selection="false"
        :show-actions="false"
        :show-settings="false"
        :compact-mode="true"
        :plain-appearance="true"
    >
        <template #column-timestamp="{ item }">
            <span class="invoice-api-xhub-logs__timestamp">{{ formatTimestamp(item.timestamp) }}</span>
        </template>
        <template #column-status="{ item }">
            <sw-color-badge :variant="statusVariant(item.status)" rounded>
                {{ item.status }}
            </sw-color-badge>
        </template>
        <template #column-message="{ item }">
            <span class="invoice-api-xhub-logs__message" :title="item.message">{{ item.message }}</span>
        </template>
    </sw-data-grid>
</sw-card>
{% endblock %}
`;

  // src/module/invoice-api-xhub-order/component/invoice-api-xhub-logs-tab/index.js
  var { Component: Component3, Mixin: Mixin2 } = Shopware;
  Component3.register("invoice-api-xhub-logs-tab", {
    template: invoice_api_xhub_logs_tab_html_default,
    inject: ["invoiceApiXhubApiService"],
    mixins: [Mixin2.getByName("notification")],
    props: {
      orderId: { type: String, required: true }
    },
    data() {
      return {
        isLoading: false,
        entries: [],
        error: null
      };
    },
    computed: {
      hasEntries() {
        return Array.isArray(this.entries) && this.entries.length > 0;
      },
      columns() {
        return [
          { property: "timestamp", label: "invoice-api-xhub.logs.col.timestamp", primary: true },
          { property: "action", label: "invoice-api-xhub.logs.col.action" },
          { property: "format", label: "invoice-api-xhub.logs.col.format" },
          { property: "status", label: "invoice-api-xhub.logs.col.status" },
          { property: "filename", label: "invoice-api-xhub.logs.col.filename" },
          { property: "message", label: "invoice-api-xhub.logs.col.message" }
        ];
      }
    },
    watch: {
      orderId: { immediate: true, handler: "loadLogs" }
    },
    methods: {
      async loadLogs() {
        if (!this.orderId)
          return;
        this.isLoading = true;
        this.error = null;
        try {
          const response = await this.invoiceApiXhubApiService.logs(this.orderId);
          this.entries = response?.data?.entries ?? [];
        } catch (e) {
          this.error = e && e.message || "Failed to load logs";
          this.entries = [];
        } finally {
          this.isLoading = false;
        }
      },
      statusVariant(status) {
        switch (status) {
          case "success":
            return "success";
          case "error":
            return "danger";
          case "skipped":
            return "neutral";
          default:
            return "info";
        }
      },
      formatTimestamp(iso) {
        if (!iso)
          return "";
        try {
          return new Date(iso).toLocaleString();
        } catch {
          return iso;
        }
      }
    }
  });

  // src/module/invoice-api-xhub-order/snippet/en-GB.json
  var en_GB_default = {
    "invoice-api-xhub": {
      module: {
        title: "Invoice-api.xhub",
        description: "E-invoice generation"
      },
      tab: {
        label: "Invoice-api.xhub"
      },
      actions: {
        title: "Invoice actions",
        regenerate: "Re-generate invoice",
        download: "Download",
        regenerateInProgress: "Generating...",
        regenerateSuccess: "Invoice generated successfully.",
        regenerateError: "Generation failed: {{message}}",
        downloadFailed: "Download failed.",
        noInvoiceYet: "No invoice generated yet.",
        lastError: "Last error",
        filename: "Filename",
        generatedAt: "Generated at"
      },
      logs: {
        title: "History",
        empty: "No history entries yet.",
        emptySubline: "Once invoices are generated for this order, they appear here.",
        refresh: "Refresh",
        col: {
          timestamp: "When",
          action: "Action",
          format: "Format",
          status: "Status",
          filename: "File",
          message: "Detail"
        }
      }
    }
  };

  // src/module/invoice-api-xhub-order/snippet/de-DE.json
  var de_DE_default = {
    "invoice-api-xhub": {
      module: {
        title: "Invoice-api.xhub",
        description: "E-Rechnungs-Erzeugung"
      },
      tab: {
        label: "Invoice-api.xhub"
      },
      actions: {
        title: "Rechnungs-Aktionen",
        regenerate: "Rechnung erneut erzeugen",
        download: "Herunterladen",
        regenerateInProgress: "Wird erzeugt...",
        regenerateSuccess: "Rechnung erfolgreich erzeugt.",
        regenerateError: "Erzeugung fehlgeschlagen: {{message}}",
        downloadFailed: "Download fehlgeschlagen.",
        noInvoiceYet: "Noch keine Rechnung erzeugt.",
        lastError: "Letzter Fehler",
        filename: "Dateiname",
        generatedAt: "Erzeugt am"
      },
      logs: {
        title: "Verlauf",
        empty: "Noch keine Eintr\xE4ge im Verlauf.",
        emptySubline: "Sobald Rechnungen f\xFCr diese Bestellung erzeugt werden, erscheinen sie hier.",
        refresh: "Aktualisieren",
        col: {
          timestamp: "Zeitpunkt",
          action: "Aktion",
          format: "Format",
          status: "Status",
          filename: "Datei",
          message: "Details"
        }
      }
    }
  };

  // src/module/invoice-api-xhub-order/index.js
  var { Locale } = Shopware;
  Locale.extend("en-GB", en_GB_default);
  Locale.extend("de-DE", de_DE_default);

  // src/service/invoice-api-xhub.api.service.js
  var { Application, Classes } = Shopware;
  var ApiService = Classes.ApiService;
  var InvoiceApiXhubApiService = class extends ApiService {
    constructor(httpClient, loginService, apiEndpoint = "_action/invoice-api-xhub") {
      super(httpClient, loginService, apiEndpoint);
      this.name = "invoiceApiXhubApiService";
    }
    regenerate(orderId) {
      return this.httpClient.post(
        `/${this.apiEndpoint}/regenerate`,
        { orderId },
        { headers: this.getBasicHeaders() }
      );
    }
    download(orderId) {
      return this.httpClient.get(
        `/${this.apiEndpoint}/download/${orderId}`,
        {
          headers: this.getBasicHeaders(),
          responseType: "blob"
        }
      );
    }
    logs(orderId) {
      return this.httpClient.get(
        `/${this.apiEndpoint}/logs/${orderId}`,
        { headers: this.getBasicHeaders() }
      );
    }
    getOrderMeta(orderId) {
      return this.httpClient.get(`/order/${orderId}`, {
        params: {
          associations: { customFields: {} }
        },
        headers: this.getBasicHeaders()
      }).then((response) => {
        const customFields = response && response.data && response.data.data && response.data.data.customFields || {};
        return {
          filename: customFields.invoice_api_xhub_filename || null,
          filepath: customFields.invoice_api_xhub_filepath || null,
          lastError: customFields.invoice_api_xhub_last_error || null,
          generatedAt: customFields.invoice_api_xhub_generated_at || null,
          templateId: customFields.invoice_api_xhub_template_id || null
        };
      });
    }
  };
  Application.addServiceProvider("invoiceApiXhubApiService", (container) => {
    const initContainer = Application.getContainer("init");
    return new InvoiceApiXhubApiService(initContainer.httpClient, container.loginService);
  });
})();

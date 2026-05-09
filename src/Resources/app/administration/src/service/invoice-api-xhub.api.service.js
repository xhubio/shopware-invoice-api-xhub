// Service-provider that wraps the Shopware ApiService base class so our
// components can call `this.invoiceApiXhubApiService.regenerate(orderId)` etc.
const { Application, Classes } = Shopware
const ApiService = Classes.ApiService

class InvoiceApiXhubApiService extends ApiService {
  constructor(httpClient, loginService, apiEndpoint = '_action/invoice-api-xhub') {
    super(httpClient, loginService, apiEndpoint)
    this.name = 'invoiceApiXhubApiService'
  }

  regenerate(orderId) {
    return this.httpClient.post(
      `/${this.apiEndpoint}/regenerate`,
      { orderId },
      { headers: this.getBasicHeaders() },
    )
  }

  download(orderId) {
    return this.httpClient.get(
      `/${this.apiEndpoint}/download/${orderId}`,
      {
        headers: this.getBasicHeaders(),
        responseType: 'blob',
      },
    )
  }

  logs(orderId) {
    return this.httpClient.get(
      `/${this.apiEndpoint}/logs/${orderId}`,
      { headers: this.getBasicHeaders() },
    )
  }

  getOrderMeta(orderId) {
    return this.httpClient
      .get(`/order/${orderId}`, {
        params: {
          associations: { customFields: {} },
        },
        headers: this.getBasicHeaders(),
      })
      .then((response) => {
        const customFields = (response && response.data && response.data.data && response.data.data.customFields) || {}
        return {
          filename: customFields.invoice_api_xhub_filename || null,
          filepath: customFields.invoice_api_xhub_filepath || null,
          lastError: customFields.invoice_api_xhub_last_error || null,
          generatedAt: customFields.invoice_api_xhub_generated_at || null,
          templateId: customFields.invoice_api_xhub_template_id || null,
        }
      })
  }
}

Application.addServiceProvider('invoiceApiXhubApiService', (container) => {
  const initContainer = Application.getContainer('init')
  return new InvoiceApiXhubApiService(initContainer.httpClient, container.loginService)
})

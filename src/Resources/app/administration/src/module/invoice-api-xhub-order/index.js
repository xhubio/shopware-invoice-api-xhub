// Module entry: registers our 2 leaf components and the override of
// sw-order-detail-general. We deliberately do NOT call Module.register —
// our plugin doesn't add a top-level admin module/route. It only extends
// an existing core component with extra cards, so registering a Module
// without `routes` would silently fail validation in Shopware 6.6+.
//
// Snippet loading is handled by Shopware-Core via the plugin snippet
// pipeline (Resources/config/snippet/{de_DE,en_GB}/messages.*.json plus
// the per-component snippet/{de-DE,en-GB}.json files registered by each
// component's index.js — see invoice-api-xhub-order-actions/index.js).
import './extension/sw-order-detail-general/index.js'
import './component/invoice-api-xhub-order-actions/index.js'
import './component/invoice-api-xhub-logs-tab/index.js'

import enGB from './snippet/en-GB.json'
import deDE from './snippet/de-DE.json'

const { Locale } = Shopware

// Register snippets so $tc('invoice-api-xhub.…') resolves in our components
// and in the overridden Twig template.
Locale.extend('en-GB', enGB)
Locale.extend('de-DE', deDE)
